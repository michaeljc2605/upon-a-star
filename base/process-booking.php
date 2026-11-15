<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo 'Use the booking form to reserve seats.';
    exit;
}

function return_to_booking(int $performanceId, string $message, array $old = []): void
{
    booking_flash($message, $old);
    $destination = base_url('book.php');
    if ($performanceId > 0) {
        $destination .= '?performance_id=' . $performanceId;
    }
    header('Location: ' . $destination, true, 303);
    exit;
}

function valid_post_text($value): string
{
    return is_string($value) ? trim($value) : '';
}

$performanceRaw = $_POST['performance_id'] ?? null;
$performanceId = is_string($performanceRaw) && ctype_digit($performanceRaw)
    ? (int) $performanceRaw : 0;
$name = valid_post_text($_POST['customer_name'] ?? null);
$signedInUser = function_exists('php_current_user') ? php_current_user() : null;
// The enhanced PHP route gets identity from Express on the server. A forged
// form email must never move a signed-in reservation to another account.
$email = is_array($signedInUser)
    ? strtolower(valid_post_text($signedInUser['email'] ?? null))
    : strtolower(valid_post_text($_POST['customer_email'] ?? null));
$phone = valid_post_text($_POST['customer_phone'] ?? null);
$rawSeats = $_POST['seat_ids'] ?? null;
$oldSeats = [];
if (is_array($rawSeats)) {
    foreach (array_slice($rawSeats, 0, 12) as $rawSeat) {
        if (is_string($rawSeat) && ctype_digit($rawSeat) && (int) $rawSeat > 0) {
            $oldSeats[] = (int) $rawSeat;
        }
    }
}
$old = [
    'performance_id' => $performanceId,
    'seat_ids' => $oldSeats,
    'customer_name' => $name,
    'customer_email' => $email,
    'customer_phone' => $phone,
];

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    return_to_booking($performanceId, 'Your form session expired. Please review the screening and try again.');
}
if ($performanceId < 1) {
    return_to_booking(0, 'Choose a valid screening.', $old);
}
if (strlen($name) < 2 || strlen($name) > 160 || !preg_match('/\p{L}/u', $name) || preg_match('/[\x00-\x1F\x7F]/', $name)) {
    return_to_booking($performanceId, 'Enter your full name (2 to 160 characters).', $old);
}
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return_to_booking($performanceId, 'Enter a valid email address.', $old);
}
if (strlen($phone) < 6 || strlen($phone) > 40 || !preg_match('/^\+?[0-9().\- ]+$/D', $phone) || preg_match_all('/[0-9]/', $phone) < 6) {
    return_to_booking($performanceId, 'Enter a phone number with at least six digits.', $old);
}
if (!is_array($rawSeats) || count($rawSeats) < 1 || count($rawSeats) > 12) {
    return_to_booking($performanceId, 'Select between one and 12 seats.', $old);
}
$seatIds = [];
foreach ($rawSeats as $rawSeat) {
    if (!is_string($rawSeat) || !ctype_digit($rawSeat) || (int) $rawSeat < 1) {
        return_to_booking($performanceId, 'Choose seats from the displayed seat map.', $old);
    }
    $seatIds[] = (int) $rawSeat;
}
if (count(array_unique($seatIds)) !== count($seatIds)) {
    return_to_booking($performanceId, 'Each seat can only be selected once.', $old);
}

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();

    // Lock the screening first. All PHP reservations for a screening then inspect
    // and claim seats in one order, while the ticket table's unique key also
    // protects against concurrent writers from another application.
    $screeningQuery = $pdo->prepare(<<<'SQL'
        SELECT p.id, p.unit_price, p.capacity, p.seats_booked
          FROM performances p
          JOIN shows s ON s.id = p.show_id
          JOIN rooms r ON r.id = p.room_id
         WHERE p.id = ? AND p.status = 'scheduled' AND p.history_deleted_at IS NULL
           AND p.starts_at > CURRENT_TIMESTAMP AND s.status = 'published'
           AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
           AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
           AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
           AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date)
         FOR UPDATE
    SQL);
    $screeningQuery->execute([$performanceId]);
    $screening = $screeningQuery->fetch();
    if (!$screening) {
        throw new DomainException('This screening is no longer open for reservations.');
    }
    if ((int) $screening['seats_booked'] + count($seatIds) > (int) $screening['capacity']) {
        throw new DomainException('There are not enough seats left for this selection.');
    }

    $placeholders = implode(', ', array_fill(0, count($seatIds), '?'));
    $seatQuery = $pdo->prepare("SELECT ps.id, bt.id AS booked_ticket, sh.id AS active_hold
        FROM performance_seats ps
        LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
        LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
        WHERE ps.performance_id = ? AND ps.id IN ({$placeholders}) FOR UPDATE");
    $seatQuery->execute(array_merge([$performanceId], $seatIds));
    $seats = $seatQuery->fetchAll();
    if (count($seats) !== count($seatIds)) {
        throw new DomainException('One or more seats do not belong to this screening. Choose seats again.');
    }
    foreach ($seats as $seat) {
        if ($seat['booked_ticket'] !== null || $seat['active_hold'] !== null) {
            throw new DomainException('One or more seats have just been reserved. Choose another seat.');
        }
    }

    $unitCents = booking_price_cents((string) $screening['unit_price']);
    if ($unitCents < 1) {
        throw new RuntimeException('The screening price is invalid.');
    }
    $quantity = count($seatIds);
    $total = number_format(($unitCents * $quantity) / 100, 2, '.', '');
    $unit = number_format($unitCents / 100, 2, '.', '');
    $reference = 'SV-' . strtoupper(bin2hex(random_bytes(12)));

    $createBooking = $pdo->prepare(<<<'SQL'
        INSERT INTO bookings
            (user_id, public_reference, customer_name, customer_email, customer_phone,
             total_amount, booking_status, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, 'confirmed', 'pending')
    SQL);
    $createBooking->execute([is_array($signedInUser) ? $signedInUser['id'] : null, $reference, $name, $email, $phone, $total]);
    $bookingId = (int) $pdo->lastInsertId();

    $createItem = $pdo->prepare('INSERT INTO booking_items (booking_id, performance_id, ticket_quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
    $createItem->execute([$bookingId, $performanceId, $quantity, $unit, $total]);
    $itemId = (int) $pdo->lastInsertId();

    $createTicket = $pdo->prepare('INSERT INTO booking_tickets (ticket_code, booking_id, booking_item_id, performance_seat_id) VALUES (?, ?, ?, ?)');
    foreach ($seatIds as $seatId) {
        // Each saved seat gets an entry code immediately.
        $ticketCode = 'SVT-' . strtoupper(bin2hex(random_bytes(8)));
        $createTicket->execute([$ticketCode, $bookingId, $itemId, $seatId]);
    }

    $update = $pdo->prepare('UPDATE performances SET seats_booked = seats_booked + ? WHERE id = ? AND seats_booked + ? <= capacity');
    $update->execute([$quantity, $performanceId, $quantity]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('There are not enough seats left for this selection.');
    }

    $pdo->commit();
    $_SESSION['booking_access'][$reference] = $email;
    unset($_SESSION['booking_flash']);
    if (function_exists('php_send_reservation_acknowledgement')) {
        // Email is an optional enhanced-site acknowledgement. The reservation
        // is already committed; a provider failure must not undo the seats.
        try {
            php_send_reservation_acknowledgement($bookingId);
        } catch (Throwable $exception) {
            error_log('PHP reservation email: unexpected post-commit error.');
        }
    }
    header('Location: ' . base_url('confirmation.php?reference=' . rawurlencode($reference)), true, 303);
    exit;
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    return_to_booking($performanceId, $exception->getMessage(), $old);
} catch (PDOException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception->getCode() === '23000') {
        return_to_booking($performanceId, 'One or more seats have just been reserved. Choose another seat.', $old);
    }
    error_log('Base booking database error: ' . $exception->getMessage());
    return_to_booking($performanceId, 'We could not save this reservation right now. Please try again.', $old);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Base booking error: ' . $exception->getMessage());
    return_to_booking($performanceId, 'We could not save this reservation right now. Please try again.', $old);
}
