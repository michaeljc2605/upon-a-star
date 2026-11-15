<?php
declare(strict_types=1);

/**
 * Reservation acknowledgements for the PHP-led site only. The independent
 * /base/ booking flow never loads this file or needs an email provider.
 */

function php_email_setting(string $name): string
{
    if (!in_array($name, [
        'EMAILJS_SERVICE_ID', 'EMAILJS_TEMPLATE_ID',
        'EMAILJS_RESERVATION_TEMPLATE_ID', 'EMAILJS_PUBLIC_KEY',
        'EMAILJS_PRIVATE_KEY',
    ], true)) {
        return '';
    }

    $fromProcess = getenv($name);
    if (is_string($fromProcess) && trim($fromProcess) !== '') {
        return trim($fromProcess);
    }

    static $settings = null;
    if ($settings === null) {
        $settings = [];
        $path = dirname(__DIR__, 2) . '/server/.env';
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/D', $line, $parts)) {
                        continue;
                    }
                    $value = trim($parts[2]);
                    if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"')
                        || ($value[0] === "'" && substr($value, -1) === "'"))) {
                        $value = substr($value, 1, -1);
                    }
                    $settings[$parts[1]] = $value;
                }
            }
        }
    }
    return (string) ($settings[$name] ?? '');
}

/** @return ?array{service_id:string,template_id:string,user_id:string,accessToken:string} */
function php_email_config(): ?array
{
    $service = php_email_setting('EMAILJS_SERVICE_ID');
    // The shared ticket template renders one QR image per booked seat.
    $template = php_email_setting('EMAILJS_TEMPLATE_ID');
    $public = php_email_setting('EMAILJS_PUBLIC_KEY');
    if ($service === '' || $template === '' || $public === '') {
        return null;
    }
    return [
        'service_id' => $service,
        'template_id' => $template,
        'user_id' => $public,
        'accessToken' => php_email_setting('EMAILJS_PRIVATE_KEY'),
    ];
}

/**
 * Build per-seat ticket values from committed booking rows.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function php_reservation_email_params(array $rows): array
{
    if (!$rows) {
        throw new InvalidArgumentException('A saved booking is required.');
    }
    $booking = $rows[0];
    $orders = [];
    $details = [];
    foreach ($rows as $row) {
        $code = (string) $row['ticket_code'];
        $seat = (string) $row['seat_code'];
        $name = (string) $row['title'] . ' · ' . (string) $row['room_name'] . ' · Seat ' . $seat;
        $orders[] = [
            'name' => $name,
            'units' => 1,
            'price' => number_format((float) $row['unit_price'], 0, ',', '.'),
            'ticket_code' => $code,
            'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&format=png&qzone=4&data=' . rawurlencode($code),
        ];
        $details[] = $name . ' · ' . booking_datetime((string) $row['starts_at']);
    }
    $message = 'Your seats are booked. Present each seat QR code once at the cinema entrance.';
    return [
        'email' => (string) $booking['customer_email'],
        'to_email' => (string) $booking['customer_email'],
        'customer_name' => (string) $booking['customer_name'],
        'order_id' => (string) $booking['public_reference'],
        'booking_reference' => (string) $booking['public_reference'],
        'email_subject' => 'Upon a Star · Tickets ' . (string) $booking['public_reference'],
        'is_reservation' => false,
        'orders' => $orders,
        'cost' => [
            'shipping' => '0',
            'tax' => '0',
            'total' => number_format((float) $booking['total_amount'], 0, ',', '.'),
        ],
        'ticket_details' => implode("\n", $details),
        'ticket_message' => $message,
    ];
}

/** @param array<string,string> $config @param array<string,mixed> $params */
function php_emailjs_send(array $config, array $params): bool
{
    $body = [
        'service_id' => $config['service_id'],
        'template_id' => $config['template_id'],
        'user_id' => $config['user_id'],
        'template_params' => $params,
    ];
    if ($config['accessToken'] !== '') {
        $body['accessToken'] = $config['accessToken'];
    }
    $request = curl_init('https://api.emailjs.com/api/v1.0/email/send');
    if ($request === false) {
        error_log('PHP reservation email: cURL could not start.');
        return false;
    }
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/plain'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $response = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if ($response === false) {
        error_log('PHP reservation email: EmailJS request failed.');
        return false;
    }
    if ($status !== 200) {
        // EmailJS can echo provider diagnostics. Never log response bodies or keys.
        error_log('PHP reservation email: EmailJS returned HTTP ' . $status . '.');
        return false;
    }
    return true;
}

function php_reservation_email_current(int $bookingId, string $templateId, ?PDO $connection = null): bool
{
    if ($bookingId < 1 || $templateId === '') {
        return false;
    }
    $query = ($connection ?? db())->prepare(<<<'SQL'
        SELECT id FROM email_deliveries
         WHERE booking_id = ? AND delivery_status = 'sent' AND provider_message = ?
         LIMIT 1
    SQL);
    $query->execute([$bookingId, 'ticket-template:' . $templateId]);
    return $query->fetchColumn() !== false;
}

/**
 * @param ?callable(array<string,string>,array<string,mixed>):bool $transport
 * @return string sent, already_sent, unavailable, busy, or failed
 */
function php_send_reservation_acknowledgement(int $bookingId, ?callable $transport = null, ?PDO $connection = null): string
{
    if ($bookingId < 1) {
        return 'failed';
    }
    $pdo = $connection ?? db();
    $lockName = 'silver_reservation_email_' . $bookingId;
    $locked = false;
    try {
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        $locked = (int) $lock->fetchColumn() === 1;
        if (!$locked) {
            return 'busy';
        }

        $query = $pdo->prepare(<<<'SQL'
            SELECT b.id, b.public_reference, b.customer_name, b.customer_email,
                   b.total_amount, b.booking_status,
                   b.acknowledgement_sent_at, bi.unit_price,
                   s.title, p.starts_at, r.name AS room_name, rs.seat_code, bt.ticket_code
              FROM bookings b
              JOIN booking_items bi ON bi.booking_id = b.id
              JOIN performances p ON p.id = bi.performance_id
              JOIN shows s ON s.id = p.show_id
              JOIN rooms r ON r.id = p.room_id
              JOIN booking_tickets bt ON bt.booking_item_id = bi.id
              JOIN performance_seats ps ON ps.id = bt.performance_seat_id
              JOIN room_seats rs ON rs.id = ps.room_seat_id
             WHERE b.id = ?
             ORDER BY p.starts_at, rs.row_label, rs.seat_number
        SQL);
        $query->execute([$bookingId]);
        $rows = $query->fetchAll();
        if (!$rows || $rows[0]['booking_status'] !== 'confirmed'
            || !filter_var($rows[0]['customer_email'], FILTER_VALIDATE_EMAIL)) {
            return 'failed';
        }
        $config = php_email_config();
        if ($config === null) {
            error_log('PHP reservation email: EmailJS settings are unavailable.');
            return 'unavailable';
        }
        if (php_reservation_email_current($bookingId, $config['template_id'], $pdo)) {
            return 'already_sent';
        }
        $params = php_reservation_email_params($rows);
        $accepted = $transport === null
            ? php_emailjs_send($config, $params)
            : $transport($config, $params);
        if (!$accepted) {
            return 'failed';
        }
        $delivery = $pdo->prepare("INSERT INTO email_deliveries (booking_id, recipient_email, delivery_status, provider_message, sent_at) VALUES (?, ?, 'sent', ?, CURRENT_TIMESTAMP)");
        $delivery->execute([$bookingId, $rows[0]['customer_email'], 'ticket-template:' . $config['template_id']]);
        $update = $pdo->prepare('UPDATE bookings SET acknowledgement_sent_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$bookingId]);
        return 'sent';
    } catch (Throwable $exception) {
        error_log('PHP reservation email: unexpected ' . get_class($exception) . '.');
        return 'failed';
    } finally {
        if ($locked) {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $exception) {
                error_log('PHP reservation email: could not release send lock.');
            }
        }
    }
}
