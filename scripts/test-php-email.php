<?php
declare(strict_types=1);

// This exercises the PHP reservation email path with a fake provider. It never
// calls EmailJS, and all fixture database writes are rolled back.
putenv('EMAILJS_SERVICE_ID=mock-service');
putenv('EMAILJS_TEMPLATE_ID=mock-ticket-template');
putenv('EMAILJS_PUBLIC_KEY=mock-public-key');

require dirname(__DIR__) . '/php/_start.php';
require dirname(__DIR__) . '/php/includes/email.php';

function email_check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo 'PASS  ' . $description . PHP_EOL;
}

$pdo = db();
$fixture = $pdo->query(<<<'SQL'
    SELECT ps.id AS seat_id, p.id AS performance_id, p.unit_price
      FROM performance_seats ps
      JOIN performances p ON p.id = ps.performance_id
      LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
     WHERE bt.id IS NULL
     LIMIT 1
SQL)->fetch();
email_check(is_array($fixture), 'An unreserved seat is available for the rollback fixture');

$pdo->beginTransaction();
try {
    $reference = 'SV-' . strtoupper(bin2hex(random_bytes(12)));
    $email = 'php-mail-test-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $createBooking = $pdo->prepare(<<<'SQL'
        INSERT INTO bookings
            (user_id, public_reference, customer_name, customer_email,
             customer_phone, total_amount, booking_status, payment_status)
        VALUES (NULL, ?, 'Email Test Guest', ?, '12345678', ?, 'confirmed', 'pending')
    SQL);
    $createBooking->execute([$reference, $email, $fixture['unit_price']]);
    $bookingId = (int) $pdo->lastInsertId();
    $item = $pdo->prepare('INSERT INTO booking_items (booking_id, performance_id, ticket_quantity, unit_price, line_total) VALUES (?, ?, 1, ?, ?)');
    $item->execute([$bookingId, $fixture['performance_id'], $fixture['unit_price'], $fixture['unit_price']]);
    $itemId = (int) $pdo->lastInsertId();
    $ticket = $pdo->prepare('INSERT INTO booking_tickets (ticket_code, booking_id, booking_item_id, performance_seat_id) VALUES (?, ?, ?, ?)');
    $ticketCode = 'SVT-' . strtoupper(bin2hex(random_bytes(8)));
    $ticket->execute([$ticketCode, $bookingId, $itemId, $fixture['seat_id']]);
    $pdo->exec('SAVEPOINT before_email');

    $sends = 0;
    $payloadValid = false;
    $accept = static function (array $config, array $params) use (&$sends, &$payloadValid, $email, $reference, $ticketCode): bool {
        $sends++;
        $orders = $params['orders'] ?? null;
        $payloadValid = $config['template_id'] === 'mock-ticket-template'
            && $params['to_email'] === $email
            && ($params['is_reservation'] ?? null) === false
            && ($params['email_subject'] ?? null) === 'Upon a Star · Tickets ' . $reference
            && ($params['booking_reference'] ?? null) === $reference
            && str_contains($params['ticket_message'], 'Present each seat QR code once')
            && is_array($orders) && count($orders) === 1
            && ($orders[0]['ticket_code'] ?? null) === $ticketCode
            && ($orders[0]['units'] ?? null) === 1
            && ($orders[0]['qr_url'] ?? null) === 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&format=png&qzone=4&data=' . rawurlencode($ticketCode)
            && !array_key_exists('payment_status', $params);
        return true;
    };

    $first = php_send_reservation_acknowledgement($bookingId, $accept, $pdo);
    $second = php_send_reservation_acknowledgement($bookingId, $accept, $pdo);
    $ackQuery = $pdo->prepare('SELECT acknowledgement_sent_at FROM bookings WHERE id = ?');
    $ackQuery->execute([$bookingId]);
    $marked = $ackQuery->fetchColumn();
    email_check($first === 'sent' && $second === 'already_sent' && $sends === 1,
        'Provider acceptance sends once and suppresses a duplicate');
    email_check($payloadValid && $marked !== null,
        'The per-seat ticket payload and provider-acceptance timestamp are correct');
    email_check(php_reservation_email_current($bookingId, 'mock-ticket-template', $pdo),
        'The accepted ticket template is tracked separately from the old email timestamp');

    $pdo->exec('ROLLBACK TO SAVEPOINT before_email');
    $failure = php_send_reservation_acknowledgement($bookingId, static function (): bool {
        return false;
    }, $pdo);
    $ackQuery->execute([$bookingId]);
    email_check($failure === 'failed' && $ackQuery->fetchColumn() === null,
        'Provider rejection leaves the reservation eligible for retry');

    $pdo->prepare('UPDATE bookings SET acknowledgement_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookingId]);
    $oldMailSends = 0;
    $oldMailTransport = static function () use (&$oldMailSends): bool {
        $oldMailSends++;
        return true;
    };
    $corrected = php_send_reservation_acknowledgement($bookingId, $oldMailTransport, $pdo);
    $duplicate = php_send_reservation_acknowledgement($bookingId, $oldMailTransport, $pdo);
    email_check($corrected === 'sent' && $duplicate === 'already_sent' && $oldMailSends === 1,
        'A booking with an old incorrect email can receive one corrected template send');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
echo 'INFO  Test reservation rolled back; no email sent.' . PHP_EOL;
