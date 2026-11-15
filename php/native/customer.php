<?php
declare(strict_types=1);

/** Customer API for the optional native PHP backend. All prices come from SQL. */
function native_customer_window(bool $sales = false): string
{
    $window = "s.status = 'published' AND p.status = 'scheduled' AND p.history_deleted_at IS NULL
        AND p.starts_at > CURRENT_TIMESTAMP
        AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
        AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)";
    return $window . ($sales ? " AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date)
        AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)" : '');
}

function native_customer_active_ticket(): string
{
    return "s.status = 'published' AND p.status = 'scheduled' AND p.history_deleted_at IS NULL
        AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
        AND CURRENT_TIMESTAMP < TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at)";
}

function native_customer_ticket_reason(): string
{
    return "CASE WHEN p.status = 'cancelled' THEN 'Screening cancelled'
        WHEN s.status != 'published' THEN 'Movie no longer available'
        WHEN s.run_end_date IS NOT NULL AND CURRENT_DATE > s.run_end_date THEN 'Movie run finished'
        WHEN CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) THEN 'Screening finished'
        ELSE NULL END";
}

function native_customer_archive(): void
{
    require_once __DIR__ . '/../includes/rolling-programme.php';
    cinema_rolling_archive_finished(db());
}

function native_customer_movie_fields(): string
{
    return "s.id, s.title, s.slug, s.synopsis, s.genre, s.duration_minutes, s.age_rating, s.poster_url,
        s.backdrop_url, s.trailer_url, s.featured, s.status, s.theme_key,
        DATE_FORMAT(s.run_start_date, '%Y-%m-%d') AS run_start_date,
        DATE_FORMAT(s.run_end_date, '%Y-%m-%d') AS run_end_date,
        DATE_FORMAT(s.sales_open_date, '%Y-%m-%d') AS sales_open_date,
        CASE WHEN s.run_start_date IS NULL OR s.run_end_date IS NULL THEN 'ongoing'
          WHEN CURRENT_DATE < s.run_start_date THEN 'arriving_soon'
          WHEN CURRENT_DATE <= s.run_end_date THEN 'ongoing' ELSE 'ended' END AS lifecycle_status,
        IF(s.run_start_date IS NULL OR s.run_end_date IS NULL OR
          (CURRENT_DATE >= s.sales_open_date AND CURRENT_DATE <= s.run_end_date), 1, 0) AS tickets_on_sale";
}

function native_customer_visible(): string
{
    return "s.status = 'published' AND ((s.run_end_date IS NOT NULL AND s.run_end_date >= CURRENT_DATE)
        OR (s.run_end_date IS NULL AND EXISTS (SELECT 1 FROM performances visible_performance
          WHERE visible_performance.show_id = s.id AND visible_performance.status = 'scheduled'
          AND visible_performance.history_deleted_at IS NULL AND visible_performance.starts_at >= CURRENT_TIMESTAMP)))";
}

function native_customer_row(array $row): array
{
    foreach (['starts_at', 'ends_at', 'room_available_at', 'created_at', 'checked_in_at'] as $key) {
        if (isset($row[$key]) && is_string($row[$key])) {
            $row[$key] = (new DateTimeImmutable($row[$key], new DateTimeZone('Asia/Singapore')))->format(DATE_ATOM);
        }
    }
    foreach (['id', 'show_id', 'room_id', 'user_id', 'capacity', 'seats_available', 'booking_open', 'before_sales',
        'available', 'held_by_you', 'seat_number', 'block_number', 'duration_minutes', 'featured',
        'tickets_on_sale', 'ticket_available', 'ticket_quantity'] as $key) {
        if (isset($row[$key])) $row[$key] = (int) $row[$key];
    }
    return $row;
}

function native_customer_screenings(string $where, array $params = []): array
{
    $bookable = native_customer_window(true);
    return array_map('native_customer_row', native_query("SELECT p.id, p.show_id, p.starts_at, p.format, p.language,
        p.unit_price, p.status, r.id AS room_id, r.code AS room_code, r.name AS room_name, r.room_type, r.capacity,
        TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS ends_at,
        TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at) AS room_available_at,
        IF($bookable, 1, 0) AS booking_open,
        (SELECT COUNT(*) FROM performance_seats ps LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
          WHERE ps.performance_id = p.id AND bt.id IS NULL) AS seats_available
        FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
        WHERE $where ORDER BY p.starts_at", $params)->fetchAll());
}

function native_customer_seat_ids(mixed $value, int $minimum = 1): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > 12) {
        native_error('Choose between ' . $minimum . ' and 12 seats.', 400);
    }
    $ids = array_map(fn($id) => native_int($id, 'Seat'), $value);
    if (count(array_unique($ids)) !== count($ids)) native_error('Each seat can only be selected once.', 400);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function native_customer_bookable(array $screening): void
{
    if ((int) ($screening['booking_open'] ?? 0) === 1) return;
    native_error(!empty($screening['before_sales']) ? 'Tickets for this movie are not on sale yet.'
        : 'This screening is outside the movie booking window or is no longer available.', 409);
}

function native_customer_hold(int $performanceId, array $seatIds, array $user): array
{
    $expires = native_transaction(function (PDO $connection) use ($performanceId, $seatIds, $user) {
        $window = native_customer_window(true);
        $screening = native_query("SELECT IF($window, 1, 0) AS booking_open,
            IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales
            FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id = ? FOR UPDATE", [$performanceId])->fetch();
        if (!$screening) native_error('Screening not found.', 404);
        if (!$seatIds) {
            native_query('DELETE sh FROM seat_holds sh JOIN performance_seats ps ON ps.id = sh.performance_seat_id WHERE sh.user_id = ? AND ps.performance_id = ?', [$user['id'], $performanceId]);
            return null;
        }
        native_customer_bookable($screening);
        native_query('DELETE sh FROM seat_holds sh JOIN performance_seats ps ON ps.id = sh.performance_seat_id WHERE ps.performance_id = ? AND sh.expires_at <= CURRENT_TIMESTAMP', [$performanceId]);
        $marks = implode(',', array_fill(0, count($seatIds), '?'));
        $seats = native_query("SELECT ps.id, bt.id AS ticket_id, sh.user_id AS hold_user FROM performance_seats ps
            LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
            LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
            WHERE ps.performance_id = ? AND ps.id IN ($marks) ORDER BY ps.id FOR UPDATE", [$performanceId, ...$seatIds])->fetchAll();
        if (count($seats) !== count($seatIds)) native_error('One or more seats are no longer available.', 409);
        foreach ($seats as $seat) {
            if ($seat['ticket_id'] !== null || ($seat['hold_user'] !== null && (int) $seat['hold_user'] !== (int) $user['id'])) {
                native_error('One or more seats are no longer available.', 409);
            }
        }
        native_query("DELETE FROM seat_holds WHERE user_id = ? AND performance_seat_id IN
            (SELECT id FROM performance_seats WHERE performance_id = ?) AND performance_seat_id NOT IN ($marks)", [$user['id'], $performanceId, ...$seatIds]);
        foreach ($seatIds as $seatId) {
            native_query('INSERT INTO seat_holds (performance_seat_id, user_id, expires_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), expires_at = VALUES(expires_at)', [$seatId, $user['id']]);
        }
        return native_query('SELECT expires_at FROM seat_holds WHERE performance_seat_id = ? AND user_id = ?', [$seatIds[0], $user['id']])->fetchColumn();
    });
    return ['heldSeatIds' => $seatIds, 'expiresAt' => $expires === null ? null
        : (new DateTimeImmutable((string) $expires, new DateTimeZone('Asia/Singapore')))->format(DATE_ATOM)];
}

function native_customer_book(array $body, array $user): array
{
    $phone = $body['customerPhone'] ?? null;
    if (!is_string($phone) || strlen(trim($phone)) < 6 || strlen(trim($phone)) > 40
        || !preg_match('/^\+?[0-9().\- ]+$/D', trim($phone)) || preg_match_all('/[0-9]/', $phone) < 6) {
        native_error('Enter a valid customer phone number.', 400);
    }
    $items = $body['items'] ?? null;
    if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 10) {
        native_error('Choose between one and 10 screenings.', 400);
    }
    $requested = [];
    foreach ($items as $item) {
        if (!is_array($item)) native_error('Choose a valid screening and seats.', 400);
        $id = native_int($item['performanceId'] ?? null, 'Screening');
        if (isset($requested[$id])) native_error('A screening can only appear once in an order.', 400);
        $requested[$id] = native_customer_seat_ids($item['seatIds'] ?? null);
    }
    // Consistent lock order across multi-screening orders avoids reversed-order deadlocks.
    ksort($requested, SORT_NUMERIC);
    $booking = native_transaction(function (PDO $connection) use ($requested, $user, $phone) {
        $selected = [];
        $totalCents = 0;
        $window = native_customer_window(true);
        foreach ($requested as $performanceId => $seatIds) {
            $screening = native_query("SELECT p.id, p.unit_price, p.capacity, p.seats_booked, p.starts_at,
                s.title, r.name AS room_name, IF($window, 1, 0) AS booking_open,
                IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales
                FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
                WHERE p.id = ? FOR UPDATE", [$performanceId])->fetch();
            if (!$screening) native_error('One selected screening is no longer available.', 409);
            native_customer_bookable($screening);
            if ((int) $screening['seats_booked'] + count($seatIds) > (int) $screening['capacity']) native_error('Not enough seats remain.', 409);
            $marks = implode(',', array_fill(0, count($seatIds), '?'));
            $seats = native_query("SELECT ps.id, rs.seat_code, bt.id AS ticket_id, sh.user_id AS hold_user
                FROM performance_seats ps JOIN room_seats rs ON rs.id = ps.room_seat_id
                LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
                LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
                WHERE ps.performance_id = ? AND ps.id IN ($marks) ORDER BY ps.id FOR UPDATE", [$performanceId, ...$seatIds])->fetchAll();
            if (count($seats) !== count($seatIds)) native_error('Your ten-minute seat hold has expired. Please choose your seats again.', 409);
            foreach ($seats as $seat) {
                if ($seat['ticket_id'] !== null || $seat['hold_user'] === null || (int) $seat['hold_user'] !== (int) $user['id']) {
                    native_error('Your ten-minute seat hold has expired. Please choose your seats again.', 409);
                }
            }
            $unitCents = booking_price_cents((string) $screening['unit_price']);
            if ($unitCents < 1) native_error('The screening price is invalid.', 409);
            $screening['seats'] = $seats;
            $screening['unitCents'] = $unitCents;
            $selected[] = $screening;
            $totalCents += $unitCents * count($seats);
        }
        $reference = 'SV-' . strtoupper(bin2hex(random_bytes(12)));
        $total = number_format($totalCents / 100, 2, '.', '');
        native_query("INSERT INTO bookings (user_id, public_reference, customer_name, customer_email, customer_phone,
            total_amount, booking_status, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'confirmed', 'pending')",
            [$user['id'], $reference, $user['displayName'], $user['email'], trim($phone), $total]);
        $bookingId = (int) $connection->lastInsertId();
        foreach ($selected as $screening) {
            $quantity = count($screening['seats']);
            native_query('INSERT INTO booking_items (booking_id, performance_id, ticket_quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $screening['id'], $quantity, number_format($screening['unitCents'] / 100, 2, '.', ''), number_format($screening['unitCents'] * $quantity / 100, 2, '.', '')]);
            $itemId = (int) $connection->lastInsertId();
            foreach ($screening['seats'] as $seat) {
                native_query('INSERT INTO booking_tickets (ticket_code, booking_id, booking_item_id, performance_seat_id) VALUES (?, ?, ?, ?)',
                    ['SVT-' . strtoupper(bin2hex(random_bytes(8))), $bookingId, $itemId, $seat['id']]);
                native_query('DELETE FROM seat_holds WHERE performance_seat_id = ? AND user_id = ?', [$seat['id'], $user['id']]);
            }
            $updated = native_query('UPDATE performances SET seats_booked = seats_booked + ? WHERE id = ? AND seats_booked + ? <= capacity', [$quantity, $screening['id'], $quantity]);
            if ($updated->rowCount() !== 1) native_error('Not enough seats remain.', 409);
        }
        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        native_query('INSERT INTO audit_events (actor_user_id, action_name, entity_type, entity_id, event_data, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
            [$user['id'], 'create_reservation', 'booking', (string) $bookingId, json_encode(['publicReference' => $reference], JSON_THROW_ON_ERROR),
             is_string($address) && filter_var($address, FILTER_VALIDATE_IP) ? $address : null]);
        return ['id' => $bookingId, 'reference' => $reference, 'total' => (float) $total, 'items' => array_map(fn($screening) => [
            'title' => $screening['title'], 'startsAt' => native_customer_row($screening)['starts_at'],
            'roomName' => $screening['room_name'], 'seats' => array_column($screening['seats'], 'seat_code'),
        ], $selected)];
    });
    // A transport failure must never undo a confirmed reservation.
    try {
        require_once __DIR__ . '/../includes/email.php';
        php_send_reservation_acknowledgement($booking['id']);
    } catch (Throwable $error) {
        error_log('Native booking saved; ticket email could not be sent.');
    }
    unset($booking['id']);
    return $booking;
}

function native_customer_route(string $method, string $route, array $query, ?array $body, bool $internal): ?array
{
    $body ??= [];
    $ok = fn(array $data, int $status = 200) => ['status' => $status, 'data' => $data];
    if ($method === 'GET' && in_array($route, ['/api/catalog', '/api/movies'], true)) {
        native_customer_archive();
        $movies = array_map('native_customer_row', native_query('SELECT ' . native_customer_movie_fields() . "
            FROM shows s LEFT JOIN (SELECT future.show_id, MIN(future.starts_at) AS starts_at
              FROM performances future JOIN shows timed_show ON timed_show.id = future.show_id
              WHERE future.status = 'scheduled' AND future.history_deleted_at IS NULL AND future.starts_at >= CURRENT_TIMESTAMP
                AND (timed_show.run_start_date IS NULL OR DATE(future.starts_at) >= timed_show.run_start_date)
                AND (timed_show.run_end_date IS NULL OR DATE(future.starts_at) <= timed_show.run_end_date)
              GROUP BY future.show_id) next_screening ON next_screening.show_id = s.id
            WHERE " . native_customer_visible() . ' ORDER BY next_screening.starts_at IS NULL, next_screening.starts_at, s.title, s.id')->fetchAll());
        return $ok($route === '/api/movies' ? ['movies' => $movies] : ['shows' => $movies, 'performances' => native_customer_screenings(native_customer_window())]);
    }
    if ($method === 'GET' && preg_match('~^/api/movies/([a-z0-9]+(?:-[a-z0-9]+)*)$~D', $route, $match)) {
        native_customer_archive();
        $movie = native_query('SELECT ' . native_customer_movie_fields() . ' FROM shows s WHERE s.slug = ? AND ' . native_customer_visible() . ' LIMIT 1', [$match[1]])->fetch();
        if (!$movie) native_error('Movie not found.', 404);
        return $ok(['movie' => native_customer_row($movie), 'screenings' => native_customer_screenings('p.show_id = ? AND ' . native_customer_window(), [$movie['id']])]);
    }
    if (preg_match('~^/api/screenings/([0-9]+)/(seats|hold)$~D', $route, $match)
        && (($method === 'GET' && $match[2] === 'seats') || ($method === 'POST' && $match[2] === 'hold'))) {
        $user = native_require_user();
        $id = native_int($match[1], 'Screening');
        if ($method === 'POST') {
            native_require_csrf($internal);
            if (empty($user['onboardingComplete'])) native_error('Complete your profile before choosing seats or booking tickets.', 403);
            return $ok(native_customer_hold($id, native_customer_seat_ids($body['seatIds'] ?? null, 0), $user));
        }
        $window = native_customer_window(true);
        $screening = native_query("SELECT p.id, p.starts_at, p.unit_price, p.format, s.title, s.synopsis, s.poster_url,
            s.sales_open_date, r.name AS room_name, r.room_type, IF($window, 1, 0) AS booking_open,
            IF(s.sales_open_date IS NOT NULL AND CURRENT_DATE < s.sales_open_date, 1, 0) AS before_sales
            FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.id = ?", [$id])->fetch();
        if (!$screening) native_error('Screening not found.', 404);
        native_customer_bookable($screening);
        $seats = native_query('SELECT ps.id, rs.seat_code, rs.row_label, rs.seat_number, rs.block_number,
            IF(bt.id IS NULL AND (sh.id IS NULL OR sh.user_id = ?), 1, 0) AS available,
            IF(sh.user_id = ?, 1, 0) AS held_by_you FROM performance_seats ps JOIN room_seats rs ON rs.id = ps.room_seat_id
            LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
            LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
            WHERE ps.performance_id = ? ORDER BY rs.row_label, rs.seat_number', [$user['id'], $user['id'], $id])->fetchAll();
        return $ok(['screening' => native_customer_row($screening), 'seats' => array_map('native_customer_row', $seats)]);
    }
    if ($method === 'POST' && $route === '/api/bookings') {
        $user = native_require_user();
        native_require_csrf($internal);
        if (empty($user['onboardingComplete'])) native_error('Complete your profile before choosing seats or booking tickets.', 403);
        return $ok(native_customer_book($body, $user), 201);
    }
    if ($method === 'GET' && preg_match('~^/api/bookings/([A-Za-z0-9-]{4,80})$~D', $route, $match)) {
        $user = native_require_user();
        $booking = native_query('SELECT id, user_id, public_reference AS reference, customer_name, total_amount, booking_status, created_at FROM bookings WHERE public_reference = ? LIMIT 1', [$match[1]])->fetch();
        if (!$booking) native_error('Booking not found.', 404);
        if ((int) $booking['user_id'] !== (int) $user['id'] && !in_array($user['role'], ['owner', 'staff', 'manager'], true)) native_error('You cannot view this booking.', 403);
        $active = native_customer_active_ticket(); $reason = native_customer_ticket_reason();
        $items = native_query("SELECT s.title, p.starts_at, r.name AS room_name, bi.ticket_quantity, bi.unit_price,
            IF(b.booking_status = 'confirmed' AND $active, 1, 0) AS ticket_available,
            CASE WHEN b.booking_status = 'cancelled' THEN 'Booking cancelled' ELSE $reason END AS ticket_unavailable_reason,
            GROUP_CONCAT(rs.seat_code ORDER BY rs.row_label, rs.seat_number SEPARATOR ', ') AS seats
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id JOIN performances p ON p.id = bi.performance_id
            JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
            JOIN booking_tickets bt ON bt.booking_item_id = bi.id JOIN performance_seats ps ON ps.id = bt.performance_seat_id
            JOIN room_seats rs ON rs.id = ps.room_seat_id WHERE bi.booking_id = ? GROUP BY bi.id", [$booking['id']])->fetchAll();
        return $ok(['booking' => native_customer_row($booking), 'items' => array_map('native_customer_row', $items)]);
    }
    if ($method === 'GET' && $route === '/api/tickets') {
        $user = native_require_user();
        $active = native_customer_active_ticket(); $reason = native_customer_ticket_reason();
        $rows = native_query("SELECT b.public_reference, b.created_at, s.title, p.starts_at, r.name AS room_name,
            r.room_type, bt.ticket_code, bt.checked_in_at, rs.seat_code, IF($active, 1, 0) AS ticket_available,
            $reason AS ticket_unavailable_reason FROM bookings b JOIN booking_items bi ON bi.booking_id = b.id
            JOIN performances p ON p.id = bi.performance_id JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
            JOIN booking_tickets bt ON bt.booking_item_id = bi.id JOIN performance_seats ps ON ps.id = bt.performance_seat_id
            JOIN room_seats rs ON rs.id = ps.room_seat_id WHERE b.user_id = ? AND b.booking_status = 'confirmed'
            ORDER BY p.starts_at DESC, rs.row_label, rs.seat_number", [$user['id']])->fetchAll();
        $tickets = [];
        foreach ($rows as $row) {
            $row = native_customer_row($row); $reference = $row['public_reference'];
            if (!isset($tickets[$reference])) $tickets[$reference] = ['reference' => $reference, 'bookedAt' => $row['created_at'], 'items' => []];
            $key = json_encode([$row['title'], $row['starts_at'], $row['room_name']], JSON_THROW_ON_ERROR);
            if (!isset($tickets[$reference]['items'][$key])) $tickets[$reference]['items'][$key] = [
                'title' => $row['title'], 'startsAt' => $row['starts_at'], 'roomName' => $row['room_name'],
                'roomType' => $row['room_type'], 'ticketAvailable' => $row['ticket_available'],
                'ticketUnavailableReason' => $row['ticket_unavailable_reason'], 'seats' => [],
            ];
            $tickets[$reference]['items'][$key]['seats'][] = ['code' => $row['seat_code'], 'ticketCode' => $row['ticket_code'], 'checkedInAt' => $row['checked_in_at']];
        }
        foreach ($tickets as &$ticket) $ticket['items'] = array_values($ticket['items']);
        unset($ticket);
        return $ok(['tickets' => array_values($tickets)]);
    }
    return null;
}
