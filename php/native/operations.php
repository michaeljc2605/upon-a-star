<?php
declare(strict_types=1);

/** Native equivalents of the cinema's staff and owner API actions. */
function native_ops_statement(string $sql, array $params = [], ?PDO $pdo = null): PDOStatement
{
    if ($pdo === null) {
        return native_query($sql, $params);
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function native_ops_enum($value, string $name, array $choices): string
{
    if (!is_string($value) || !in_array($value, $choices, true)) {
        native_error('Invalid ' . $name . '.', 400);
    }
    return $value;
}

function native_ops_price($value): string
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)
        || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > 10000000) {
        native_error('Invalid ticket price.', 400);
    }
    return number_format((float) $value, 2, '.', '');
}

function native_ops_start($value): DateTimeImmutable
{
    $value = native_string($value, 'start time', 1, 64);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](\d{2}):?(\d{2}))$/D', $value, $parts)
        || (isset($parts[1]) && ((int) $parts[1] > 23 || (int) $parts[2] > 59))) {
        native_error('Use a valid start time with a time zone.', 400);
    }
    native_date(substr($value, 0, 10), 'start date');
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            native_error('Use a valid start time.', 400);
        }
    } catch (Exception $exception) {
        native_error('Use a valid start time.', 400);
    }
    return $date->setTimezone(new DateTimeZone('Asia/Singapore'));
}

function native_ops_themes(): array
{
    return ['enchanted_castle', 'legends_and_courage', 'ocean_and_islands', 'hero_city',
        'tech_and_arcade', 'worlds_of_imagination', 'sky_adventure', 'culinary_magic', 'animal_worlds'];
}

function native_ops_response(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = native_ops_response($value);
        } elseif (is_string($value) && in_array($key, ['starts_at', 'ends_at', 'room_available_at',
            'created_at', 'checked_in_at', 'cancelled_at', 'last_login_at', 'startsAt', 'createdAt', 'checkedInAt'], true)) {
            $data[$key] = (new DateTimeImmutable($value, new DateTimeZone('Asia/Singapore')))->format(DATE_ATOM);
        } elseif ($value !== null && in_array($key, ['id', 'show_id', 'room_id', 'capacity', 'seats_booked',
            'duration_minutes', 'featured', 'tickets_on_sale', 'active', 'onboarding_complete', 'is_completed',
            'screenings_today', 'seats_booked_today', 'seats_checked_in_today'], true)) {
            $data[$key] = (int) $value;
        }
    }
    return $data;
}

function native_ops_asset($value, string $name): string
{
    $value = native_string($value, $name, 1, 500);
    if (preg_match('~^/assets/movies/[a-z0-9]+(?:-[a-z0-9]+)*/(?:poster|backdrop)\.webp$~D', $value)) {
        return $value;
    }
    $parts = parse_url($value);
    if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts)
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        native_error('Use an approved /assets/movies/<slug>/poster.webp or backdrop.webp path, or an HTTP(S) URL.', 400);
    }
    return $value;
}

function native_ops_lifecycle(string $start, string $end, string $sales = ''): array
{
    if ($end < $start) {
        native_error('Movie end date must be on or after its start date.', 400);
    }
    $previousMonth = (new DateTimeImmutable(substr($start, 0, 7) . '-01'))->modify('-1 month');
    $earliest = $previousMonth->format('Y-m-') . str_pad((string) min((int) substr($start, 8, 2), (int) $previousMonth->format('t')), 2, '0', STR_PAD_LEFT);
    $sales = $sales === '' ? $earliest : $sales;
    if ($sales < $earliest) {
        native_error('Ticket sales cannot open before ' . $earliest . ', one calendar month before the movie starts.', 400);
    }
    if ($sales > $start) {
        native_error('Ticket sales must open on or before the movie start date.', 400);
    }
    return ['runStartDate' => $start, 'runEndDate' => $end, 'salesOpenDate' => $sales];
}

function native_ops_require_publishable(string $status, string $end, ?PDO $pdo = null): void
{
    if ($status === 'published'
        && $end < native_ops_statement("SELECT DATE_FORMAT(CURRENT_DATE, '%Y-%m-%d')", [], $pdo)->fetchColumn()) {
        native_error('This movie run has finished. Extend its end date before publishing it.', 400);
    }
}

function native_ops_archive_finished(): void
{
    require_once __DIR__ . '/../includes/rolling-programme.php';
    cinema_rolling_archive_finished(db());
}

function native_ops_movie_fields(): string
{
    return "id, title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url,
        trailer_url, featured, status, theme_key,
        DATE_FORMAT(run_start_date, '%Y-%m-%d') AS run_start_date,
        DATE_FORMAT(run_end_date, '%Y-%m-%d') AS run_end_date,
        DATE_FORMAT(sales_open_date, '%Y-%m-%d') AS sales_open_date,
        CASE WHEN run_start_date IS NULL OR run_end_date IS NULL THEN 'ongoing'
            WHEN CURRENT_DATE < run_start_date THEN 'arriving_soon'
            WHEN CURRENT_DATE <= run_end_date THEN 'ongoing' ELSE 'ended' END AS lifecycle_status,
        IF(run_start_date IS NULL OR run_end_date IS NULL
            OR (CURRENT_DATE >= sales_open_date AND CURRENT_DATE <= run_end_date), 1, 0) AS tickets_on_sale";
}

function native_ops_screening_fields(): string
{
    return "p.id, p.starts_at, p.status, p.format, p.language, p.unit_price, p.capacity,
        p.seats_booked, p.cancelled_at, p.cancellation_reason, s.id AS show_id, s.title,
        r.id AS room_id, r.code AS room_code, r.name AS room_name,
        TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS ends_at,
        TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at) AS room_available_at,
        CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS is_completed";
}

function native_ops_active_ticket(): string
{
    return "s.status = 'published' AND p.status = 'scheduled'
        AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
        AND CURRENT_TIMESTAMP < TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at)";
}

function native_ops_booking_sql(): string
{
    $active = native_ops_active_ticket();
    return "SELECT b.id, b.public_reference, b.customer_name, b.customer_email, b.customer_phone,
        b.total_amount, b.booking_status, b.created_at, bi.id AS booking_item_id, s.title,
        p.starts_at, p.status AS screening_status, r.name AS room_name, bt.id AS ticket_id,
        bt.ticket_code, bt.checked_in_at, rs.seat_code,
        IF(b.booking_status = 'confirmed' AND $active, 1, 0) AS ticket_available,
        CASE WHEN b.booking_status = 'cancelled' THEN 'Booking cancelled'
            WHEN p.status = 'cancelled' THEN 'Screening cancelled'
            WHEN s.status != 'published' THEN 'Movie no longer available'
            WHEN s.run_end_date IS NOT NULL AND CURRENT_DATE > s.run_end_date THEN 'Movie run finished'
            WHEN CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) THEN 'Screening finished'
            ELSE NULL END AS ticket_unavailable_reason
        FROM bookings b JOIN booking_items bi ON bi.booking_id = b.id
        JOIN performances p ON p.id = bi.performance_id JOIN shows s ON s.id = p.show_id
        JOIN rooms r ON r.id = p.room_id JOIN booking_tickets bt ON bt.booking_item_id = bi.id
        JOIN performance_seats ps ON ps.id = bt.performance_seat_id
        JOIN room_seats rs ON rs.id = ps.room_seat_id";
}

function native_ops_booking(array $rows): ?array
{
    if (!$rows) return null;
    $first = $rows[0];
    $booking = ['id' => (int) $first['id'], 'reference' => $first['public_reference'],
        'customerName' => $first['customer_name'], 'customerEmail' => $first['customer_email'],
        'customerPhone' => $first['customer_phone'], 'total' => $first['total_amount'],
        'bookingStatus' => $first['booking_status'], 'createdAt' => $first['created_at'], 'items' => []];
    $indexes = [];
    foreach ($rows as $row) {
        $itemId = (int) $row['booking_item_id'];
        if (!array_key_exists($itemId, $indexes)) {
            $indexes[$itemId] = count($booking['items']);
            $booking['items'][] = ['title' => $row['title'], 'startsAt' => $row['starts_at'],
                'roomName' => $row['room_name'], 'screeningStatus' => $row['screening_status'],
                'ticketAvailable' => (int) $row['ticket_available'],
                'ticketUnavailableReason' => $row['ticket_unavailable_reason'], 'tickets' => []];
        }
        $booking['items'][$indexes[$itemId]]['tickets'][] = ['id' => (int) $row['ticket_id'],
            'code' => $row['ticket_code'], 'seat' => $row['seat_code'], 'checkedInAt' => $row['checked_in_at'],
            'available' => (int) $row['ticket_available'], 'unavailableReason' => $row['ticket_unavailable_reason']];
    }
    return $booking;
}

function native_ops_create_movie(array $body): array
{
    $title = native_string($body['title'] ?? null, 'title', 2, 180);
    $slug = native_string($body['slug'] ?? null, 'slug', 1, 200);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) native_error('Invalid movie slug.', 400);
    $synopsis = native_string($body['synopsis'] ?? null, 'synopsis', 10);
    $genre = native_string($body['genre'] ?? null, 'genre', 2, 120);
    $duration = native_int($body['durationMinutes'] ?? null, 'duration', 30, 360);
    $rating = native_string($body['ageRating'] ?? null, 'age rating', 1, 20);
    $poster = native_ops_asset($body['posterUrl'] ?? null, 'poster URL');
    $backdrop = native_ops_asset($body['backdropUrl'] ?? null, 'backdrop URL');
    $trailer = native_string($body['trailerUrl'] ?? '', 'trailer URL', 0, 500);
    if ($trailer !== '' && filter_var($trailer, FILTER_VALIDATE_URL) === false) native_error('Invalid trailer URL.', 400);
    $theme = native_ops_enum($body['themeKey'] ?? null, 'movie theme', native_ops_themes());
    $status = native_ops_enum($body['status'] ?? null, 'movie status', ['draft', 'published', 'archived']);
    $start = native_date($body['runStartDate'] ?? null, 'movie start date');
    $end = native_date($body['runEndDate'] ?? null, 'movie end date');
    $sales = array_key_exists('salesOpenDate', $body) && $body['salesOpenDate'] !== ''
        ? native_date($body['salesOpenDate'], 'sales opening date') : '';
    $lifecycle = native_ops_lifecycle($start, $end, $sales);
    native_ops_require_publishable($status, $end);
    native_query('INSERT INTO shows (title, slug, synopsis, genre, duration_minutes, age_rating,
        poster_url, backdrop_url, trailer_url, theme_key, status, featured, run_start_date, run_end_date, sales_open_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$title, $slug, $synopsis, $genre, $duration, $rating, $poster, $backdrop, $trailer === '' ? null : $trailer,
            $theme, $status, !empty($body['featured']) ? 1 : 0, $start, $end, $lifecycle['salesOpenDate']]);
    $id = (int) db()->lastInsertId();
    $movie = native_query('SELECT ' . native_ops_movie_fields() . ' FROM shows WHERE id = ? LIMIT 1', [$id])->fetch();
    return ['id' => $id, 'movie' => $movie];
}

function native_ops_update_movie(int $id, array $body): void
{
    $input = [];
    if (array_key_exists('status', $body)) $input['status'] = native_ops_enum($body['status'], 'movie status', ['draft', 'published', 'archived']);
    if (array_key_exists('themeKey', $body)) $input['themeKey'] = native_ops_enum($body['themeKey'], 'movie theme', native_ops_themes());
    foreach (['runStartDate', 'runEndDate', 'salesOpenDate'] as $key) {
        if (array_key_exists($key, $body)) {
            $input[$key] = $key === 'salesOpenDate' && $body[$key] === '' ? '' : native_date($body[$key], $key);
        }
    }
    if (!$input) native_error('Choose a movie change.', 400);
    native_ops_archive_finished();
    native_transaction(function (PDO $pdo) use ($id, $input): void {
        $existing = native_ops_statement('SELECT id, status, run_start_date, run_end_date, sales_open_date FROM shows WHERE id = ? FOR UPDATE', [$id], $pdo)->fetch();
        if (!$existing) native_error('Movie not found.', 404);
        $nextStatus = $input['status'] ?? $existing['status'];
        $start = $input['runStartDate'] ?? (string) $existing['run_start_date'];
        $end = $input['runEndDate'] ?? (string) $existing['run_end_date'];
        $sales = $input['salesOpenDate'] ?? (string) $existing['sales_open_date'];
        $changingDates = array_key_exists('runStartDate', $input) || array_key_exists('runEndDate', $input) || array_key_exists('salesOpenDate', $input);
        $lifecycle = null;
        if ($changingDates || $nextStatus === 'published') {
            if ($start === '' || $end === '') native_error('Set the movie start and end dates before publishing it.', 400);
            $lifecycle = native_ops_lifecycle($start, $end, $sales);
            native_ops_require_publishable($nextStatus, $end, $pdo);
            $outside = native_ops_statement("SELECT id FROM performances WHERE show_id = ? AND status = 'scheduled'
                AND starts_at >= CURRENT_TIMESTAMP AND DATE(starts_at) NOT BETWEEN ? AND ? LIMIT 1 FOR UPDATE", [$id, $start, $end], $pdo)->fetch();
            if ($outside) native_error('The new movie dates must include every future scheduled screening. Move or cancel those screenings first.', 409);
        }
        if ($existing['status'] === 'archived' && ($input['status'] ?? '') === 'published') {
            $future = native_ops_statement("SELECT id FROM performances WHERE show_id = ? AND status = 'scheduled'
                AND history_deleted_at IS NULL AND starts_at > CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE", [$id], $pdo)->fetch();
            if (!$future) native_error('Add a future screening before republishing this archived movie.', 409);
        }
        $fields = [];
        $params = [];
        if (isset($input['status'])) { $fields[] = 'status = ?'; $params[] = $input['status']; }
        if (isset($input['themeKey'])) { $fields[] = 'theme_key = ?'; $params[] = $input['themeKey']; }
        if ($lifecycle !== null && $changingDates) {
            array_push($fields, 'run_start_date = ?', 'run_end_date = ?', 'sales_open_date = ?');
            array_push($params, $lifecycle['runStartDate'], $lifecycle['runEndDate'], $lifecycle['salesOpenDate']);
        }
        $params[] = $id;
        native_ops_statement('UPDATE shows SET ' . implode(', ', $fields) . ' WHERE id = ?', $params, $pdo);
    });
}

function native_ops_screening_input(array $body, bool $create): array
{
    $input = [];
    foreach (['showId', 'roomId'] as $key) {
        if (($create && $key === 'showId') || ($create && $key === 'roomId') || ($key === 'roomId' && array_key_exists($key, $body))) {
            $input[$key] = native_int($body[$key] ?? null, $key);
        }
    }
    if ($create || array_key_exists('startsAt', $body)) $input['startsAt'] = native_ops_start($body['startsAt'] ?? null);
    foreach (['format' => 40, 'language' => 60] as $key => $max) {
        if ($create || array_key_exists($key, $body)) $input[$key] = native_string($body[$key] ?? null, $key, 2, $max);
    }
    if ($create || array_key_exists('unitPrice', $body)) $input['unitPrice'] = native_ops_price($body['unitPrice'] ?? null);
    if (!$create && array_key_exists('status', $body)) $input['status'] = native_ops_enum($body['status'], 'screening status', ['scheduled', 'cancelled']);
    if (!$create && array_key_exists('cancellationReason', $body)) $input['cancellationReason'] = native_string($body['cancellationReason'], 'cancellation reason', 0, 255);
    return $input;
}

function native_ops_room_conflict(PDO $pdo, int $roomId, DateTimeImmutable $start, int $duration, int $excludeId = 0): void
{
    $available = $start->modify('+' . ($duration + 60) . ' minutes')->format('Y-m-d H:i:s');
    // The caller holds the room row lock before checking or inserting allocations.
    $conflict = native_ops_statement("SELECT p.id FROM performances p JOIN shows s ON s.id = p.show_id
        WHERE p.id != ? AND p.room_id = ? AND p.status = 'scheduled' AND p.starts_at < ?
        AND TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at) > ? LIMIT 1 FOR UPDATE",
        [$excludeId, $roomId, $available, $start->format('Y-m-d H:i:s')], $pdo)->fetch();
    if ($conflict) native_error('That room is already allocated during this time. Choose another room or time.', 409);
}

function native_ops_create_screening(array $body): array
{
    $input = native_ops_screening_input($body, true);
    if ($input['startsAt']->getTimestamp() <= time()) native_error('Choose a future start time', 400);
    $id = native_transaction(function (PDO $pdo) use ($input): int {
        $start = $input['startsAt']->format('Y-m-d H:i:s');
        $show = native_ops_statement('SELECT duration_minutes, run_start_date, run_end_date,
            IF(run_start_date IS NOT NULL AND run_end_date IS NOT NULL AND DATE(?) BETWEEN run_start_date AND run_end_date, 1, 0) AS within_run
            FROM shows WHERE id = ? FOR UPDATE', [$start, $input['showId']], $pdo)->fetch();
        if (!$show) native_error('Movie not found', 404);
        if (!$show['run_start_date'] || !$show['run_end_date']) native_error('Set this movie’s start and end dates before scheduling screenings.', 400);
        if (!(int) $show['within_run']) native_error('The screening date must fall within the movie start and end dates.', 400);
        $room = native_ops_statement('SELECT id FROM rooms WHERE id = ? AND active = 1 FOR UPDATE', [$input['roomId']], $pdo)->fetch();
        if (!$room) native_error('Room not found or inactive', 404);
        native_ops_room_conflict($pdo, $input['roomId'], $input['startsAt'], (int) $show['duration_minutes']);
        native_ops_statement('INSERT INTO performances (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked)
            SELECT ?, r.id, ?, r.name, ?, ?, ?, r.capacity, 0 FROM rooms r WHERE r.id = ?',
            [$input['showId'], $start, $input['format'], $input['language'], $input['unitPrice'], $input['roomId']], $pdo);
        $id = (int) $pdo->lastInsertId();
        native_ops_statement('INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [$id, $input['roomId']], $pdo);
        return $id;
    });
    $screening = native_query('SELECT ' . native_ops_screening_fields() . ' FROM performances p JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id WHERE p.id = ? LIMIT 1', [$id])->fetch();
    return ['id' => $id, 'screening' => $screening];
}

function native_ops_update_screening(int $id, array $body): void
{
    $input = native_ops_screening_input($body, false);
    native_transaction(function (PDO $pdo) use ($id, $input): void {
        $existing = native_ops_statement('SELECT p.id, p.room_id, p.starts_at, p.status, p.seats_booked,
            s.duration_minutes, s.run_start_date, s.run_end_date
            FROM performances p JOIN shows s ON s.id = p.show_id WHERE p.id = ? FOR UPDATE', [$id], $pdo)->fetch();
        if (!$existing) native_error('Screening not found.', 404);
        $start = $input['startsAt'] ?? new DateTimeImmutable($existing['starts_at'], new DateTimeZone('Asia/Singapore'));
        if (isset($input['startsAt']) && $start->getTimestamp() <= time()) native_error('Choose a future start time.', 400);
        $roomId = $input['roomId'] ?? (int) $existing['room_id'];
        $status = $input['status'] ?? $existing['status'];
        if ($status === 'cancelled' && $existing['status'] !== 'cancelled' && $start->getTimestamp() > time()
            && ($input['cancellationReason'] ?? '') === '') {
            $ticket = native_ops_statement('SELECT bt.id FROM booking_tickets bt JOIN booking_items bi ON bi.id = bt.booking_item_id
                WHERE bi.performance_id = ? LIMIT 1', [$id], $pdo)->fetch();
            if ((int) $existing['seats_booked'] > 0 || $ticket) native_error('Give a cancellation reason: guests already hold tickets for this screening.', 400);
        }
        $day = $start->format('Y-m-d');
        if (!$existing['run_start_date'] || !$existing['run_end_date'] || $day < $existing['run_start_date'] || $day > $existing['run_end_date']) {
            native_error('The screening date must fall within the movie start and end dates.', 400);
        }
        $room = native_ops_statement('SELECT id, name, capacity FROM rooms WHERE id = ? AND active = 1 FOR UPDATE', [$roomId], $pdo)->fetch();
        if (!$room) native_error('Room not found or inactive.', 404);
        $roomChanged = $roomId !== (int) $existing['room_id'];
        if ($roomChanged) {
            $usage = native_ops_statement('SELECT (SELECT COUNT(*) FROM booking_tickets bt JOIN performance_seats ps ON ps.id = bt.performance_seat_id WHERE ps.performance_id = ?) AS tickets,
                (SELECT COUNT(*) FROM seat_holds WHERE performance_seat_id IN (SELECT id FROM performance_seats WHERE performance_id = ?) AND expires_at > CURRENT_TIMESTAMP) AS holds', [$id, $id], $pdo)->fetch();
            if ((int) $usage['tickets'] || (int) $usage['holds']) native_error('Cannot move a screening to another room once seats are held or sold.', 409);
        }
        if ($status === 'scheduled') native_ops_room_conflict($pdo, $roomId, $start, (int) $existing['duration_minutes'], $id);
        $fields = ['starts_at = ?', 'room_id = ?', 'auditorium = ?', 'capacity = ?'];
        $params = [$start->format('Y-m-d H:i:s'), $roomId, $room['name'], $room['capacity']];
        foreach (['format' => 'format', 'language' => 'language', 'unitPrice' => 'unit_price'] as $key => $column) {
            if (isset($input[$key])) { $fields[] = $column . ' = ?'; $params[] = $input[$key]; }
        }
        if (isset($input['status'])) {
            $fields[] = 'status = ?'; $params[] = $input['status'];
            $fields[] = $input['status'] === 'cancelled' ? 'cancelled_at = CURRENT_TIMESTAMP' : 'cancelled_at = NULL';
        }
        if (array_key_exists('cancellationReason', $input)) {
            $fields[] = 'cancellation_reason = ?';
            $params[] = $input['cancellationReason'] === '' ? null : $input['cancellationReason'];
        }
        $params[] = $id;
        native_ops_statement('UPDATE performances SET ' . implode(', ', $fields) . ' WHERE id = ?', $params, $pdo);
        if ($roomChanged) {
            native_ops_statement('DELETE FROM performance_seats WHERE performance_id = ?', [$id], $pdo);
            native_ops_statement('INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [$id, $roomId], $pdo);
        }
    });
}

function native_ops_delete_screenings(array $body, array $user): array
{
    if (!isset($body['ids']) || !is_array($body['ids']) || !array_is_list($body['ids']) || count($body['ids']) < 1 || count($body['ids']) > 250) {
        native_error('Choose between 1 and 250 screenings.', 400);
    }
    $ids = array_values(array_unique(array_map(static fn($id): int => native_int($id, 'screening ID'), $body['ids'])));
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return native_transaction(function (PDO $pdo) use ($ids, $placeholders, $user): array {
        $screenings = native_ops_statement("SELECT p.id, CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) AS is_past
            FROM performances p JOIN shows s ON s.id = p.show_id
            WHERE p.id IN ($placeholders) AND p.history_deleted_at IS NULL ORDER BY p.id FOR UPDATE", $ids, $pdo)->fetchAll();
        if (count($screenings) !== count($ids)) native_error('One or more selected screenings no longer exist in the visible history.', 404);
        foreach ($screenings as $screening) {
            if (!(int) $screening['is_past']) native_error('Only past screenings can be deleted from history.', 409);
        }
        $items = native_ops_statement("SELECT id, booking_id FROM booking_items WHERE performance_id IN ($placeholders) ORDER BY id FOR UPDATE", $ids, $pdo)->fetchAll();
        $itemIds = array_column($items, 'id');
        $bookingIds = array_values(array_unique(array_column($items, 'booking_id')));
        $deletedTickets = 0;
        $deletedBookings = 0;
        if ($itemIds) {
            $itemSlots = implode(',', array_fill(0, count($itemIds), '?'));
            $deletedTickets = native_ops_statement("DELETE FROM booking_tickets WHERE booking_item_id IN ($itemSlots)", $itemIds, $pdo)->rowCount();
            native_ops_statement("DELETE FROM booking_items WHERE id IN ($itemSlots)", $itemIds, $pdo);
        }
        if ($bookingIds) {
            $bookingSlots = implode(',', array_fill(0, count($bookingIds), '?'));
            native_ops_statement("UPDATE bookings b SET total_amount = COALESCE((SELECT SUM(bi.line_total) FROM booking_items bi WHERE bi.booking_id = b.id), 0) WHERE b.id IN ($bookingSlots)", $bookingIds, $pdo);
            $emptyIds = native_ops_statement("SELECT b.id FROM bookings b WHERE b.id IN ($bookingSlots)
                AND NOT EXISTS (SELECT 1 FROM booking_items bi WHERE bi.booking_id = b.id) FOR UPDATE", $bookingIds, $pdo)->fetchAll(PDO::FETCH_COLUMN);
            if ($emptyIds) {
                $emptySlots = implode(',', array_fill(0, count($emptyIds), '?'));
                native_ops_statement("DELETE FROM payment_events WHERE booking_id IN ($emptySlots)", $emptyIds, $pdo);
                native_ops_statement("DELETE FROM email_deliveries WHERE booking_id IN ($emptySlots)", $emptyIds, $pdo);
                $deletedBookings = native_ops_statement("DELETE FROM bookings WHERE id IN ($emptySlots)", $emptyIds, $pdo)->rowCount();
            }
        }
        $deleted = native_ops_statement("DELETE p FROM performances p JOIN shows s ON s.id = p.show_id
            WHERE p.id IN ($placeholders) AND CURRENT_TIMESTAMP >= TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at)
            AND p.history_deleted_at IS NULL", $ids, $pdo)->rowCount();
        if ($deleted !== count($ids)) native_error('The screening history changed. Refresh and try again.', 409);
        $requestServer = function_exists('native_request') ? native_request()['server'] : $_SERVER;
        $ip = $requestServer['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        native_ops_statement('INSERT INTO audit_events (actor_user_id, action_name, entity_type, event_data, ip_address) VALUES (?, ?, ?, ?, ?)',
            [$user['id'], 'permanently_delete_past_screenings', 'performance', json_encode(['screeningIds' => $ids, 'deletedTickets' => $deletedTickets, 'deletedBookings' => $deletedBookings], JSON_THROW_ON_ERROR), $ip], $pdo);
        return ['ok' => true, 'deleted' => $deleted, 'deletedTickets' => $deletedTickets, 'deletedBookings' => $deletedBookings];
    });
}

function native_ops_change_account(int $id, array $body, array $user): void
{
    $role = array_key_exists('role', $body) ? native_ops_enum($body['role'], 'role', ['customer', 'staff', 'owner']) : null;
    $status = array_key_exists('accountStatus', $body) ? native_ops_enum($body['accountStatus'], 'account status', ['active', 'disabled']) : null;
    if ($role === null && $status === null) native_error('Choose an account change.', 400);
    if ($id === (int) $user['id']) native_error('You cannot change your own owner access or disable your own account.', 400);
    native_transaction(function (PDO $pdo) use ($id, $role, $status, $user): void {
        // All owner changes take the same ordered locks, then recheck the actor.
        // Two owners therefore cannot concurrently remove each other's access.
        $rows = native_ops_statement("SELECT id, role, account_status, google_subject FROM users
            WHERE role = 'owner' OR id IN (?, ?) ORDER BY id FOR UPDATE", [$id, $user['id']], $pdo)->fetchAll();
        $account = null;
        $actor = null;
        $owners = 0;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) $account = $row;
            if ((int) $row['id'] === (int) $user['id']) $actor = $row;
            if ($row['role'] === 'owner' && $row['account_status'] === 'active') $owners++;
        }
        if (!$actor || $actor['role'] !== 'owner' || $actor['account_status'] !== 'active') native_error('Owner access required', 403);
        if (!$account || $account['google_subject'] === null) native_error('Google account not found.', 404);
        if ($account['role'] === 'owner' && (($role !== null && $role !== 'owner') || $status === 'disabled') && $owners <= 1) {
            native_error('At least one active owner must remain.', 409);
        }
        if ($role !== null) native_ops_statement('UPDATE users SET role = ? WHERE id = ?', [$role, $id], $pdo);
        if ($status !== null) {
            native_ops_statement('UPDATE users SET account_status = ? WHERE id = ?', [$status, $id], $pdo);
            if ($status === 'disabled') native_ops_statement('DELETE FROM sessions WHERE user_id = ?', [$id], $pdo);
        }
    });
}

/** @return ?array{status:int,data:?array} */
function native_operations_route(string $method, string $route, array $query, ?array $body, bool $internal): ?array
{
    $routes = [
        'GET /api/operations/ticket-lookup', 'POST /api/operations/scan-ticket',
        'GET /api/admin/bookings', 'GET /api/admin/summary', 'GET /api/admin/screenings',
        'DELETE /api/admin/screenings', 'POST /api/admin/screenings', 'GET /api/admin/accounts',
        'GET /api/admin/rooms', 'GET /api/admin/movies', 'POST /api/admin/movies',
    ];
    $key = $method . ' ' . $route;
    $bookingMatch = $ticketMatch = $accountMatch = $screeningMatch = $movieMatch = [];
    $bookingRoute = $method === 'GET' && preg_match('~^/api/operations/bookings/([^/]+)$~D', $route, $bookingMatch);
    $ticketRoute = $method === 'PATCH' && preg_match('~^/api/operations/tickets/([^/]+)/check-in$~D', $route, $ticketMatch);
    $accountRoute = $method === 'PATCH' && preg_match('~^/api/admin/accounts/([^/]+)$~D', $route, $accountMatch);
    $screeningRoute = $method === 'PATCH' && preg_match('~^/api/admin/screenings/([^/]+)$~D', $route, $screeningMatch);
    $movieRoute = in_array($method, ['PATCH', 'DELETE'], true) && preg_match('~^/api/admin/movies/([^/]+)$~D', $route, $movieMatch);
    if (!in_array($key, $routes, true) && !$bookingRoute && !$ticketRoute && !$accountRoute && !$screeningRoute && !$movieRoute) return null;

    $user = native_require_user();
    if (str_starts_with($route, '/api/admin/')) native_owner($user); else native_staff($user);
    if ($method !== 'GET') native_require_csrf($internal);
    $body = $body ?? [];
    $data = ['ok' => true];
    $status = 200;

    if ($bookingRoute) {
        $booking = native_ops_booking(native_query(native_ops_booking_sql() . ' WHERE b.public_reference = ? ORDER BY bt.id', [$bookingMatch[1]])->fetchAll());
        if ($booking === null) native_error('Booking not found.', 404);
        $data = ['booking' => $booking];
    } elseif ($key === 'GET /api/operations/ticket-lookup') {
        $search = native_string($query['q'] ?? '', 'lookup', 0, 254);
        if (mb_strlen($search) < 3) native_error('Enter a booking reference, ticket code, or customer email.', 400);
        $rows = native_query(native_ops_booking_sql() . ' WHERE b.public_reference = ? OR bt.ticket_code = ? OR b.customer_email = ? ORDER BY b.created_at DESC, bt.id LIMIT 100', [$search, $search, strtolower($search)])->fetchAll();
        $groups = [];
        foreach ($rows as $row) $groups[$row['id']][] = $row;
        $data = ['bookings' => array_values(array_map('native_ops_booking', $groups))];
    } elseif ($key === 'POST /api/operations/scan-ticket') {
        $code = trim((string) preg_replace('/^SVT:/i', '', native_string($body['code'] ?? null, 'ticket code', 4, 80)));
        $data = native_transaction(function (PDO $pdo) use ($code, $user): array {
            $ticket = native_ops_statement('SELECT bt.id, bt.ticket_code, bt.checked_in_at, rs.seat_code,
                b.public_reference, b.customer_name, b.customer_email, s.title, p.starts_at, r.name AS room_name
                FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id
                JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id
                JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
                JOIN performance_seats ps ON ps.id = bt.performance_seat_id JOIN room_seats rs ON rs.id = ps.room_seat_id
                WHERE bt.ticket_code = ? AND b.booking_status = \'confirmed\' AND ' . native_ops_active_ticket() . ' LIMIT 1 FOR UPDATE', [$code], $pdo)->fetch();
            if (!$ticket) native_error('Ticket not found or not valid for entry.', 404);
            if ($ticket['checked_in_at'] !== null) return ['status' => 'used', 'ticket' => $ticket];
            native_ops_statement('UPDATE booking_tickets SET checked_in_at = CURRENT_TIMESTAMP, checked_in_by = ? WHERE id = ?', [$user['id'], $ticket['id']], $pdo);
            $ticket['checked_in_at'] = native_ops_statement('SELECT checked_in_at FROM booking_tickets WHERE id = ?', [$ticket['id']], $pdo)->fetchColumn();
            return ['status' => 'accepted', 'ticket' => $ticket];
        });
    } elseif ($ticketRoute) {
        $id = native_int($ticketMatch[1], 'ticket ID');
        $count = native_query('UPDATE booking_tickets bt JOIN bookings b ON b.id = bt.booking_id
            JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id
            JOIN shows s ON s.id = p.show_id SET bt.checked_in_at = CURRENT_TIMESTAMP, bt.checked_in_by = ?
            WHERE bt.id = ? AND bt.checked_in_at IS NULL AND b.booking_status = \'confirmed\' AND ' . native_ops_active_ticket(), [$user['id'], $id])->rowCount();
        if (!$count) native_error('This ticket is already used, cancelled, finished, or its movie is no longer available.', 409);
    } elseif ($key === 'GET /api/admin/bookings') {
        $data = ['bookings' => native_query("SELECT b.id, b.public_reference, b.customer_name, b.customer_email,
            b.customer_phone, b.total_amount, b.created_at, GROUP_CONCAT(rs.seat_code ORDER BY rs.seat_code SEPARATOR ', ') AS seats
            FROM bookings b LEFT JOIN booking_tickets bt ON bt.booking_id = b.id
            LEFT JOIN performance_seats ps ON ps.id = bt.performance_seat_id LEFT JOIN room_seats rs ON rs.id = ps.room_seat_id
            GROUP BY b.id ORDER BY b.created_at DESC LIMIT 100")->fetchAll()];
    } elseif ($key === 'GET /api/admin/summary') {
        $summary = native_query("SELECT
            (SELECT COUNT(*) FROM performances WHERE status = 'scheduled' AND DATE(starts_at) = CURRENT_DATE) AS screenings_today,
            (SELECT COUNT(*) FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id
                JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id
                WHERE b.booking_status = 'confirmed' AND p.status = 'scheduled' AND DATE(p.starts_at) = CURRENT_DATE) AS seats_booked_today,
            (SELECT COUNT(*) FROM booking_tickets bt JOIN bookings b ON b.id = bt.booking_id
                JOIN booking_items bi ON bi.id = bt.booking_item_id JOIN performances p ON p.id = bi.performance_id
                WHERE b.booking_status = 'confirmed' AND bt.checked_in_at IS NOT NULL
                AND p.status = 'scheduled' AND DATE(p.starts_at) = CURRENT_DATE) AS seats_checked_in_today")->fetch();
        $next = native_query("SELECT p.starts_at, s.title, r.name AS room_name FROM performances p
            JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
            WHERE p.status = 'scheduled' AND p.starts_at >= CURRENT_TIMESTAMP ORDER BY p.starts_at LIMIT 1")->fetch();
        $data = ['summary' => $summary];
        if ($next) $data['nextScreening'] = $next;
    } elseif ($key === 'GET /api/admin/screenings') {
        $data = ['screenings' => native_query('SELECT ' . native_ops_screening_fields() . ' FROM performances p
            JOIN shows s ON s.id = p.show_id JOIN rooms r ON r.id = p.room_id
            WHERE p.history_deleted_at IS NULL ORDER BY p.starts_at DESC')->fetchAll()];
    } elseif ($key === 'POST /api/admin/screenings') {
        $data = native_ops_create_screening($body);
        $status = 201;
    } elseif ($screeningRoute) {
        native_ops_update_screening(native_int($screeningMatch[1], 'screening ID'), $body);
    } elseif ($key === 'DELETE /api/admin/screenings') {
        $data = native_ops_delete_screenings($body, $user);
    } elseif ($key === 'GET /api/admin/accounts') {
        $data = ['accounts' => native_query('SELECT id, display_name, email, role, account_status, date_of_birth,
            onboarding_complete, last_login_at, created_at FROM users WHERE google_subject IS NOT NULL ORDER BY last_login_at DESC, created_at DESC')->fetchAll()];
    } elseif ($accountRoute) {
        native_ops_change_account(native_int($accountMatch[1], 'account ID'), $body, $user);
    } elseif ($key === 'GET /api/admin/rooms') {
        $data = ['rooms' => native_query('SELECT id, code, name, room_type, capacity, active FROM rooms ORDER BY code')->fetchAll()];
    } elseif ($key === 'GET /api/admin/movies') {
        native_ops_archive_finished();
        $data = ['movies' => native_query('SELECT ' . native_ops_movie_fields() . ' FROM shows ORDER BY created_at DESC')->fetchAll()];
    } elseif ($key === 'POST /api/admin/movies') {
        $data = native_ops_create_movie($body);
        $status = 201;
    } elseif ($movieRoute && $method === 'PATCH') {
        native_ops_update_movie(native_int($movieMatch[1], 'movie ID'), $body);
    } elseif ($movieRoute && $method === 'DELETE') {
        $id = native_int($movieMatch[1], 'movie ID');
        native_transaction(function (PDO $pdo) use ($id): void {
            $movie = native_ops_statement('SELECT id FROM shows WHERE id = ? FOR UPDATE', [$id], $pdo)->fetch();
            if (!$movie) native_error('Movie not found.', 404);
            if (native_ops_statement('SELECT id FROM performances WHERE show_id = ? LIMIT 1 FOR UPDATE', [$id], $pdo)->fetch()) {
                native_error('This movie has screening history. Archive it instead so bookings remain auditable.', 409);
            }
            native_ops_statement('DELETE FROM shows WHERE id = ?', [$id], $pdo);
        });
    }
    return ['status' => $status, 'data' => native_ops_response($data)];
}
