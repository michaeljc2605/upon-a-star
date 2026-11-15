<?php
declare(strict_types=1);

/**
 * Browser-level and database-level smoke test for the independent PHP base.
 * Run with: bash scripts/check-base.sh
 *
 * This script only connects to silver_screen_base. It creates one reservation
 * through Apache and removes that reservation in a finally block.
 */

const PROJECT_ROOT = __DIR__ . '/..';
const DATABASE_NAME = 'silver_screen_base';

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS  {$description}\n";
}

function note(string $message): void
{
    echo "INFO  {$message}\n";
}

function base_test_url(): string
{
    $url = getenv('BASE_TEST_URL') ?: 'http://localhost/SilverVillage/base/';
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('BASE_TEST_URL must be a local HTTP(S) URL.');
    }
    return rtrim($url, '/') . '/';
}

function database(): PDO
{
    $configuredName = getenv('BASE_DB_NAME');
    if ($configuredName !== false && $configuredName !== DATABASE_NAME) {
        throw new RuntimeException('The test refuses to use any database except silver_screen_base.');
    }
    $host = getenv('BASE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BASE_DB_PORT') ?: '3306';
    $user = getenv('BASE_DB_USER') ?: 'root';
    $password = getenv('BASE_DB_PASSWORD');
    if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('BASE_DB_PORT is invalid.');
    }
    return new PDO(
        "mysql:host={$host};port={$port};dbname=" . DATABASE_NAME . ';charset=utf8mb4',
        $user,
        $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function lint_php_files(): void
{
    $directory = new RecursiveDirectoryIterator(PROJECT_ROOT . '/base', FilesystemIterator::SKIP_DOTS);
    $files = [];
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    check(count($files) >= 8, 'PHP base contains the expected page and handler files');
    foreach ($files as $file) {
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        exec($command, $lines, $status);
        if ($status !== 0) {
            throw new RuntimeException('PHP lint failed for ' . $file . ': ' . implode("\n", $lines));
        }
        $lines = [];
    }
    check(true, 'All ' . count($files) . ' PHP base files pass syntax lint');
}

/** @return array{status:int,body:string,location:string,content_type:string} */
function http_request(string $url, string $cookieJar, string $method = 'GET', array $fields = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not create an HTTP request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_USERAGENT => 'SilverVillageBaseVerification/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER] = [
            'Accept: text/html',
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException("HTTP request failed for {$url}: {$error}");
    }
    $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    $headers = substr($raw, 0, $headerLength);
    $body = substr($raw, $headerLength);
    $location = '';
    if (preg_match_all('/^Location:\s*(\S.*?)\s*$/im', $headers, $matches)) {
        $location = trim((string) end($matches[1]));
    }
    return ['status' => $status, 'body' => $body, 'location' => $location, 'content_type' => $contentType];
}

function request_path(string $path, string $cookieJar, string $method = 'GET', array $fields = []): array
{
    return http_request(base_test_url() . ltrim($path, '/'), $cookieJar, $method, $fields);
}

function follow_local_redirect(array $response, string $cookieJar): array
{
    check($response['status'] === 303, 'Form response redirects with HTTP 303');
    $location = $response['location'];
    $expectedPath = (string) parse_url(base_test_url(), PHP_URL_PATH);
    $actualPath = (string) parse_url($location, PHP_URL_PATH);
    check($location !== '' && str_starts_with($actualPath, $expectedPath), 'Redirect stays inside the PHP base site');
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
        $url = $location;
    } else {
        $origin = preg_replace('~(/[^/]*)?$~', '', base_test_url());
        $parts = parse_url(base_test_url());
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $url = $origin . '/' . ltrim($location, '/');
    }
    return http_request($url, $cookieJar);
}

function html(string $source): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
        throw new RuntimeException('The response was not parseable HTML.');
    }
    return new DOMXPath($document);
}

function page_evidence(array $response, string $page, array &$titles, string $cookieJar): DOMXPath
{
    check($response['status'] === 200, "{$page} returns HTTP 200");
    $xpath = html($response['body']);
    $mainLandmarks = $xpath->query('//main');
    check($mainLandmarks !== false && $mainLandmarks->length === 1, "{$page} renders exactly one main landmark");
    $titleNodes = $xpath->query('//title');
    $title = $titleNodes && $titleNodes->length === 1 ? trim($titleNodes->item(0)->textContent) : '';
    check($title !== '' && !in_array($title, $titles, true), "{$page} has a unique, nonempty title");
    $titles[] = $title;
    $text = trim((string) preg_replace('/\s+/u', ' ', $xpath->document->textContent));
    check(mb_strlen($text, 'UTF-8') >= 150, "{$page} has substantive text");
    $images = $xpath->query('//img[@src and @alt]');
    check($images !== false && $images->length > 0, "{$page} contains accessible image content");
    $source = (string) $images->item(0)->attributes->getNamedItem('src')->nodeValue;
    if (str_starts_with($source, '/')) {
        $parts = parse_url(base_test_url());
        $url = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $source;
    } else {
        $url = base_test_url() . ltrim($source, '/');
    }
    $image = http_request($url, $cookieJar);
    $dimensions = $image['status'] === 200 ? @getimagesizefromstring($image['body']) : false;
    check($image['status'] === 200 && is_array($dimensions), "{$page} image resolves as valid local artwork");
    return $xpath;
}

function form_values(DOMXPath $xpath, int $performanceId, int $seatId): array
{
    $form = $xpath->query('//form[@id="booking-form"]')->item(0);
    check($form instanceof DOMElement, 'Booking form appears on the project site');
    check(strtolower($form->getAttribute('method')) === 'post', 'Booking form uses a normal POST');
    $action = $form->getAttribute('action');
    check(str_ends_with((string) parse_url($action, PHP_URL_PATH), '/process-booking.php'), 'Form action invokes a local PHP script');
    $controls = $xpath->query('.//input[not(@type="hidden") and not(@type="submit") and not(@disabled)] | .//select[not(@disabled)] | .//textarea[not(@disabled)]', $form);
    $names = [];
    foreach ($controls as $control) {
        if ($control instanceof DOMElement && $control->getAttribute('name') !== '') {
            $names[$control->getAttribute('name')] = true;
        }
    }
    check(count($names) >= 4, 'Booking form exposes at least four visible data fields');
    foreach (['seat_ids[]', 'customer_name', 'customer_email', 'customer_phone'] as $requiredName) {
        check(isset($names[$requiredName]), "Booking form includes {$requiredName}");
    }
    $csrf = $xpath->query('.//input[@name="csrf_token"]', $form)->item(0);
    $performance = $xpath->query('.//input[@name="performance_id"]', $form)->item(0);
    check($csrf instanceof DOMElement && strlen($csrf->getAttribute('value')) >= 32, 'Booking form supplies a session CSRF token');
    check($performance instanceof DOMElement && (int) $performance->getAttribute('value') === $performanceId, 'Booking form targets the chosen screening');
    $seat = $xpath->query('.//input[@name="seat_ids[]" and @value="' . $seatId . '" and not(@disabled)]', $form);
    check($seat && $seat->length === 1, 'Chosen available seat is shown in the seat map');
    return [
        'csrf_token' => $csrf->getAttribute('value'),
        'performance_id' => (string) $performanceId,
        'seat_ids' => [(string) $seatId],
    ];
}

function available_screening(PDO $pdo): array
{
    $query = $pdo->query(<<<'SQL'
SELECT p.id AS performance_id, p.unit_price, p.seats_booked, ps.id AS seat_id,
       s.slug, s.title
  FROM performances p
  JOIN shows s ON s.id = p.show_id
  JOIN performance_seats ps ON ps.performance_id = p.id
  LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
  LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
 WHERE p.status = 'scheduled' AND p.history_deleted_at IS NULL
   AND p.starts_at > CURRENT_TIMESTAMP AND s.status = 'published'
   AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
   AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
   AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
   AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date)
   AND p.seats_booked < p.capacity
   AND bt.id IS NULL AND sh.id IS NULL
 ORDER BY p.starts_at, ps.id
 LIMIT 1
SQL);
    $screening = $query->fetch();
    if (!$screening) {
        throw new RuntimeException('No future sale-open screening with an available seat exists in silver_screen_base.');
    }
    return $screening;
}

function booking_by_email(PDO $pdo, string $email): ?array
{
    $query = $pdo->prepare('SELECT * FROM bookings WHERE customer_email = ?');
    $query->execute([$email]);
    $rows = $query->fetchAll();
    if (count($rows) > 1) {
        throw new RuntimeException('The test email unexpectedly has multiple bookings.');
    }
    return $rows[0] ?? null;
}

function seats_booked(PDO $pdo, int $performanceId): int
{
    $query = $pdo->prepare('SELECT seats_booked FROM performances WHERE id = ?');
    $query->execute([$performanceId]);
    $value = $query->fetchColumn();
    if ($value === false) {
        throw new RuntimeException('The selected screening disappeared.');
    }
    return (int) $value;
}

function clean_test_booking(PDO $pdo, string $email, int $performanceId): void
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM bookings WHERE customer_email = ? FOR UPDATE');
        $query->execute([$email]);
        $bookings = $query->fetchAll();
        if (!$bookings) {
            $pdo->commit();
            return;
        }
        if (count($bookings) !== 1) {
            throw new RuntimeException('Cleanup stopped: more than one test-email booking exists.');
        }
        $booking = $bookings[0];
        if (!str_starts_with((string) $booking['customer_name'], 'Base Test Guest ')
            || !preg_match('/^SV-[A-F0-9]{24}$/', (string) $booking['public_reference'])
            || $booking['payment_status'] !== 'pending'
            || $booking['booking_status'] !== 'confirmed') {
            throw new RuntimeException('Cleanup stopped: the test booking changed unexpectedly.');
        }
        $itemQuery = $pdo->prepare('SELECT * FROM booking_items WHERE booking_id = ?');
        $itemQuery->execute([(int) $booking['id']]);
        $items = $itemQuery->fetchAll();
        $ticketQuery = $pdo->prepare('SELECT * FROM booking_tickets WHERE booking_id = ?');
        $ticketQuery->execute([(int) $booking['id']]);
        $tickets = $ticketQuery->fetchAll();
        if (count($items) !== 1 || (int) $items[0]['performance_id'] !== $performanceId
            || count($tickets) !== 1 || $tickets[0]['checked_in_at'] !== null) {
            throw new RuntimeException('Cleanup stopped: booking items or ticket changed unexpectedly.');
        }
        $lock = $pdo->prepare('SELECT seats_booked FROM performances WHERE id = ? FOR UPDATE');
        $lock->execute([$performanceId]);
        $count = $lock->fetchColumn();
        if ($count === false || (int) $count < 1) {
            throw new RuntimeException('Cleanup stopped: screening counter is inconsistent.');
        }
        $delete = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
        $delete->execute([(int) $booking['id']]);
        $update = $pdo->prepare('UPDATE performances SET seats_booked = seats_booked - 1 WHERE id = ? AND seats_booked >= 1');
        $update->execute([$performanceId]);
        if ($delete->rowCount() !== 1 || $update->rowCount() !== 1) {
            throw new RuntimeException('Cleanup stopped: booking or counter update did not affect one row.');
        }
        $pdo->commit();
        note('Removed this test run’s reservation and restored its seat counter.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function main(): void
{
    if (!extension_loaded('curl') || !extension_loaded('pdo_mysql') || !extension_loaded('dom')) {
        throw new RuntimeException('The XAMPP PHP CLI needs curl, pdo_mysql, and dom extensions.');
    }
    lint_php_files();
    $pdo = database();
    check((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === DATABASE_NAME, 'Database connection is isolated to silver_screen_base');
    $screening = available_screening($pdo);
    $performanceId = (int) $screening['performance_id'];
    $seatId = (int) $screening['seat_id'];
    note("Using screening {$performanceId}, seat {$seatId}, {$screening['title']}.");

    $cookieJar = tempnam(sys_get_temp_dir(), 'sv-base-cookie-');
    $privateJar = tempnam(sys_get_temp_dir(), 'sv-base-private-');
    if ($cookieJar === false || $privateJar === false) {
        throw new RuntimeException('Could not allocate temporary session cookie files.');
    }
    $email = 'sv-base-test-' . bin2hex(random_bytes(8)) . '@example.test';
    $name = 'Base Test Guest ' . strtoupper(bin2hex(random_bytes(3)));
    $baseline = seats_booked($pdo, $performanceId);
    $titles = [];
    try {
        $pages = [
            'home' => 'index.php',
            'movies' => 'movies.php',
            'movie detail' => 'movie.php?slug=' . rawurlencode((string) $screening['slug']),
            'showtimes' => 'showtimes.php',
            'visit' => 'visit.php',
            'find booking' => 'find-booking.php',
        ];
        $pageDocuments = [];
        foreach ($pages as $label => $path) {
            $pageDocuments[$label] = page_evidence(request_path($path, $cookieJar), $label, $titles, $cookieJar);
        }

        $table = $pageDocuments['showtimes']->query('//table[thead/tr/th and tbody/tr]');
        check($table && $table->length > 0, 'Showtimes page renders a populated HTML table');
        $tableText = $table->item(0)->textContent;
        check(str_contains($tableText, (string) $screening['title']), 'Showtimes table includes database movie data');

        $bookPath = 'book.php?performance_id=' . $performanceId;
        $bookResponse = request_path($bookPath, $cookieJar);
        $bookPage = page_evidence($bookResponse, 'booking', $titles, $cookieJar);
        $form = form_values($bookPage, $performanceId, $seatId);

        $contact = ['customer_name' => $name, 'customer_email' => $email, 'customer_phone' => '+65 8123 4567'];
        $invalid = $form + $contact;
        $invalid['customer_email'] = 'invalid-email';
        $invalidResult = follow_local_redirect(request_path('process-booking.php', $cookieJar, 'POST', $invalid), $cookieJar);
        check($invalidResult['status'] === 200 && stripos($invalidResult['body'], 'valid email') !== false, 'PHP rejects a malformed email submitted directly');
        check(booking_by_email($pdo, $email) === null && seats_booked($pdo, $performanceId) === $baseline, 'Invalid email creates no reservation or counter change');

        $noSeat = $form + $contact;
        unset($noSeat['seat_ids']);
        $noSeatResult = follow_local_redirect(request_path('process-booking.php', $cookieJar, 'POST', $noSeat), $cookieJar);
        check($noSeatResult['status'] === 200 && stripos($noSeatResult['body'], 'select between one and 12 seats') !== false, 'PHP rejects a booking without a seat');
        check(booking_by_email($pdo, $email) === null && seats_booked($pdo, $performanceId) === $baseline, 'Missing seat creates no reservation or counter change');

        $valid = $form + $contact;
        $success = request_path('process-booking.php', $cookieJar, 'POST', $valid);
        $confirmation = follow_local_redirect($success, $cookieJar);
        $booking = booking_by_email($pdo, $email);
        check($booking !== null, 'Valid form inserts a booking row');
        $reference = (string) $booking['public_reference'];
        check(preg_match('/^SV-[A-F0-9]{24}$/', $reference) === 1 && str_contains($success['location'], rawurlencode($reference)), 'Confirmation URL contains the stored reservation reference');
        check($booking['booking_status'] === 'confirmed' && $booking['payment_status'] === 'pending', 'Reservation is confirmed while payment remains unverified');
        check($booking['customer_name'] === $name && $booking['customer_email'] === $email && $booking['customer_phone'] === $contact['customer_phone'], 'Database stores the submitted customer data');
        check(abs((float) $booking['total_amount'] - (float) $screening['unit_price']) < 0.001, 'Booking total equals one seat at the database price');
        $itemQuery = $pdo->prepare('SELECT * FROM booking_items WHERE booking_id = ?');
        $itemQuery->execute([(int) $booking['id']]);
        $items = $itemQuery->fetchAll();
        check(count($items) === 1 && (int) $items[0]['performance_id'] === $performanceId && (int) $items[0]['ticket_quantity'] === 1, 'One booking item links the chosen screening');
        $ticketQuery = $pdo->prepare('SELECT * FROM booking_tickets WHERE booking_id = ?');
        $ticketQuery->execute([(int) $booking['id']]);
        $tickets = $ticketQuery->fetchAll();
        check(count($tickets) === 1 && (int) $tickets[0]['performance_seat_id'] === $seatId, 'One ticket claims exactly the chosen seat');
        check(seats_booked($pdo, $performanceId) === $baseline + 1, 'SQL update increments the screening seat count once');
        $confirmationPage = page_evidence($confirmation, 'confirmation', $titles, $cookieJar);
        check(str_contains($confirmation['body'], $reference) && str_contains($confirmation['body'], $name), 'Server-rendered confirmation shows this reservation');

        $duplicate = $form + ['customer_name' => $name, 'customer_email' => $email, 'customer_phone' => $contact['customer_phone']];
        $duplicateResult = follow_local_redirect(request_path('process-booking.php', $cookieJar, 'POST', $duplicate), $cookieJar);
        check($duplicateResult['status'] === 200 && stripos($duplicateResult['body'], 'reserved') !== false, 'Already claimed seat is rejected by PHP');
        check(booking_by_email($pdo, $email) !== null && seats_booked($pdo, $performanceId) === $baseline + 1, 'Duplicate request creates no extra booking or seat count');

        $private = request_path('confirmation.php?reference=' . rawurlencode($reference), $privateJar);
        check($private['status'] === 302 && !str_contains($private['body'], $email) && !str_contains($private['body'], $name), 'New browser session cannot read customer details from reference alone');

        $lookupResponse = request_path('find-booking.php', $privateJar);
        check($lookupResponse['status'] === 200, 'Reservation lookup form is accessible in a new session');
        $lookupDocument = html($lookupResponse['body']);
        $lookupForm = $lookupDocument->query('//form[@method="post" and contains(@action, "find-booking.php")]')->item(0);
        check($lookupForm instanceof DOMElement, 'Reservation lookup posts to a local PHP page');
        $lookupToken = $lookupDocument->query('.//input[@name="csrf_token"]', $lookupForm)->item(0);
        check($lookupToken instanceof DOMElement && strlen($lookupToken->getAttribute('value')) >= 32, 'Reservation lookup has CSRF protection');
        $wrongLookup = follow_local_redirect(request_path('find-booking.php', $privateJar, 'POST', [
            'csrf_token' => $lookupToken->getAttribute('value'),
            'reference' => $reference,
            'email' => 'wrong-' . $email,
        ]), $privateJar);
        check($wrongLookup['status'] === 200 && stripos($wrongLookup['body'], 'could not find') !== false, 'Lookup rejects the wrong email for a real reference');
        $stillPrivate = request_path('confirmation.php?reference=' . rawurlencode($reference), $privateJar);
        check($stillPrivate['status'] === 302, 'Failed lookup does not grant reservation access');
        $found = follow_local_redirect(request_path('find-booking.php', $privateJar, 'POST', [
            'csrf_token' => $lookupToken->getAttribute('value'),
            'reference' => $reference,
            'email' => $email,
        ]), $privateJar);
        check($found['status'] === 200 && str_contains($found['body'], $reference) && str_contains($found['body'], $name), 'Correct reference and email reveal the saved reservation');

        $root = preg_replace('~/base/?$~', '/', base_test_url());
        if (is_string($root)) {
            foreach (['server/.env', 'silver_screen.sql', 'database/silver_screen_public.sql', 'base/includes/bootstrap.php', 'base/database/catalogue.sql', 'base/database/install.sh'] as $privatePath) {
                $blocked = http_request($root . $privatePath, $privateJar);
                check(in_array($blocked['status'], [403, 404], true), "HTTP blocks {$privatePath}");
            }
        }

        note('All PHP base HTTP, database, validation, privacy, and source-exposure checks passed.');
    } finally {
        try {
            clean_test_booking($pdo, $email, $performanceId);
        } finally {
            @unlink($cookieJar);
            @unlink($privateJar);
        }
    }
}

try {
    main();
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL  ' . $error->getMessage() . "\n");
    exit(1);
}
