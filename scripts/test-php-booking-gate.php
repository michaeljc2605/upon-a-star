<?php
declare(strict_types=1);

/**
 * Read-only localhost regression check for the enhanced PHP booking sign-in gate.
 * Run with: /Applications/XAMPP/xamppfiles/bin/php scripts/test-php-booking-gate.php
 *
 * The POST deliberately omits required booking fields. It must stop at the
 * identity gate; this script never submits a valid reservation or OAuth login.
 */

const SITE_ORIGIN = 'http://localhost';
const SITE_PREFIX = '/SilverVillage';
const DATABASE_NAME = 'silver_screen_base';

function gate_check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS  {$description}\n";
}

function gate_database(): PDO
{
    $name = getenv('BASE_DB_NAME');
    if ($name !== false && $name !== DATABASE_NAME) {
        throw new RuntimeException('This check only uses silver_screen_base.');
    }
    $host = getenv('BASE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BASE_DB_PORT') ?: '3306';
    if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('BASE_DB_PORT is invalid.');
    }
    $password = getenv('BASE_DB_PASSWORD');
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DATABASE_NAME . ';charset=utf8mb4',
        getenv('BASE_DB_USER') ?: 'root',
        $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

function gate_screening_id(PDO $pdo): int
{
    $id = $pdo->query(<<<'SQL'
SELECT p.id
  FROM performances p
  JOIN shows s ON s.id = p.show_id
 WHERE p.status = 'scheduled' AND p.history_deleted_at IS NULL
   AND p.starts_at > CURRENT_TIMESTAMP AND s.status = 'published'
   AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
   AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
   AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
   AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date)
   AND p.seats_booked < p.capacity
   AND EXISTS (
       SELECT 1 FROM performance_seats ps
       LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
       LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
       WHERE ps.performance_id = p.id AND bt.id IS NULL AND sh.id IS NULL
   )
 ORDER BY p.starts_at, p.id
 LIMIT 1
SQL)->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('No currently bookable screening exists for the gate check.');
    }
    return (int) $id;
}

/** @return array{status:int,body:string,location:string} */
function gate_request(string $path, string $cookieJar, ?array $fields = null): array
{
    $handle = curl_init(SITE_ORIGIN . $path);
    if ($handle === false) {
        throw new RuntimeException('Could not create a localhost request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_USERAGENT => 'SilverVillageBookingGateCheck/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ];
    if ($fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER] = [
            'Accept: text/html',
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    if (!is_string($raw)) {
        curl_close($handle);
        throw new RuntimeException('The localhost site did not respond.');
    }
    $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $headers = substr($raw, 0, $headerLength);
    $location = '';
    if (preg_match_all('/^Location:\s*(\S.*?)\s*$/im', $headers, $matches)) {
        $location = trim((string) end($matches[1]));
    }
    return ['status' => $status, 'body' => substr($raw, $headerLength), 'location' => $location];
}

function gate_html(string $source): DOMXPath
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
        throw new RuntimeException('A booking page returned invalid HTML.');
    }
    return new DOMXPath($document);
}

function gate_count_bookings(PDO $pdo, string $email): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE customer_email = ?');
    $query->execute([$email]);
    return (int) $query->fetchColumn();
}

$cookieJar = tempnam(sys_get_temp_dir(), 'sv-booking-gate-');
if ($cookieJar === false) {
    fwrite(STDERR, "FAIL  Could not create an isolated browser cookie jar.\n");
    exit(1);
}

$failed = false;
try {
    $pdo = gate_database();
    $performanceId = gate_screening_id($pdo);
    $probeEmail = 'gate-probe-' . bin2hex(random_bytes(8)) . '@example.invalid';
    gate_check(gate_count_bookings($pdo, $probeEmail) === 0, 'Probe email has no existing booking');

    $basePath = SITE_PREFIX . '/base/book.php?performance_id=' . $performanceId;
    $base = gate_request($basePath, $cookieJar);
    $baseHtml = gate_html($base['body']);
    gate_check($base['status'] === 200, 'Standalone base guest booking page returns HTTP 200');
    gate_check($baseHtml->query('//form[@id="booking-form"]')->length === 1
        && $baseHtml->query('//input[@name="seat_ids[]"]')->length > 0,
        'Standalone base still shows its guest seat form');

    $phpPath = SITE_PREFIX . '/book.php?performance_id=' . $performanceId;
    $php = gate_request($phpPath, $cookieJar);
    $phpHtml = gate_html($php['body']);
    gate_check($php['status'] === 401, 'Signed-out PHP booking request returns HTTP 401');
    gate_check($phpHtml->query('//a[contains(@href, "/api/auth/google?client=php")]')->length > 0,
        'Signed-out PHP booking page offers Google sign-in');
    gate_check($phpHtml->query('//form[@id="booking-form"] | //input[@name="seat_ids[]"]')->length === 0,
        'Signed-out PHP booking page exposes no seat form');

    $post = gate_request(SITE_PREFIX . '/process-booking.php', $cookieJar, [
        'performance_id' => (string) $performanceId,
        'customer_email' => $probeEmail,
    ]);
    gate_check($post['status'] === 303
        && $post['location'] === $phpPath,
        'Signed-out invalid POST redirects to the selected PHP booking gate');
    $afterPost = gate_request($post['location'], $cookieJar);
    $afterPostHtml = gate_html($afterPost['body']);
    gate_check($afterPost['status'] === 401
        && $afterPostHtml->query('//form[@id="booking-form"]')->length === 0,
        'Redirect after invalid POST remains at sign-in gate without seat form');
    gate_check(gate_count_bookings($pdo, $probeEmail) === 0,
        'Invalid signed-out POST creates no booking');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL  ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    @unlink($cookieJar);
}
if ($failed) {
    exit(1);
}
