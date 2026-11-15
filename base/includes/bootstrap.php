<?php
declare(strict_types=1);

/** Shared functions for the standalone PHP/MySQL base version. */

date_default_timezone_set('Asia/Singapore');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_name('silver_village_base');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    // Apache's process environment can override these defaults. No .env file is
    // placed below the document root or read from the existing Node application.
    $host = getenv('BASE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BASE_DB_PORT') ?: '3306';
    $name = getenv('BASE_DB_NAME') ?: 'silver_screen_base';
    $user = getenv('BASE_DB_USER') ?: 'root';
    $password = getenv('BASE_DB_PASSWORD');
    if ($password === false) {
        $password = '';
    }
    if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('The database port is invalid.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    // Apache receives the same TLS settings as Node through its environment.
    // Local XAMPP connections stay unchanged unless MYSQL_SSL is enabled.
    $sslSetting = strtolower(trim((string) (getenv('MYSQL_SSL') ?: '')));
    if (!in_array($sslSetting, ['', 'false', '0', 'true', '1'], true)) {
        throw new RuntimeException('MYSQL_SSL must be true or false.');
    }
    if (in_array($sslSetting, ['true', '1'], true)) {
        $ca = trim((string) (getenv('MYSQL_SSL_CA') ?: ''));
        if ($ca === '') {
            $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
            foreach ([$locations['default_cert_file'] ?? '', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $candidate) {
                if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                    $ca = $candidate;
                    break;
                }
            }
        }
        if ($ca === '' || !is_file($ca) || !is_readable($ca)) {
            throw new RuntimeException('MYSQL_SSL_CA must name a readable CA certificate bundle.');
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $connection = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        (string) $user,
        (string) $password,
        $options
    );
    // Screening DATETIME values are in Singapore local time. Keep MySQL's
    // CURRENT_TIMESTAMP/CURRENT_DATE checks aligned on remote UTC hosts.
    $connection->exec("SET time_zone = '+08:00'");
    return $connection;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $configured = getenv('BASE_PATH');
    $prefix = $configured === false ? '/SilverVillage/base' : (string) $configured;
    $prefix = '/' . trim($prefix, '/');
    if ($prefix === '/') {
        $prefix = '';
    }
    if (str_contains($prefix, '\\') || str_contains($prefix, '//') || in_array('..', explode('/', $prefix), true)) {
        throw new RuntimeException('BASE_PATH is invalid.');
    }
    if (str_contains($path, '\\') || str_contains($path, '//') || in_array('..', explode('/', $path), true)) {
        throw new InvalidArgumentException('The local URL path is invalid.');
    }
    return $prefix . '/' . ltrim($path, '/');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function verify_csrf($token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function booking_flash(string $message, array $old = []): void
{
    $_SESSION['booking_flash'] = ['message' => $message, 'old' => $old];
}

function take_booking_flash(): array
{
    $flash = $_SESSION['booking_flash'] ?? ['message' => '', 'old' => []];
    unset($_SESSION['booking_flash']);
    return is_array($flash) ? $flash : ['message' => '', 'old' => []];
}

function booking_money($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function booking_datetime(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('D, j M Y · g:i A');
    } catch (Exception $exception) {
        return $value;
    }
}

/** Turn the database's DECIMAL(10,2) into integer cents before multiplication. */
function booking_price_cents(string $price): int
{
    if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $price, $parts)) {
        throw new RuntimeException('The ticket price is invalid.');
    }
    return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
}

function booking_reference_is_valid(string $reference): bool
{
    return (bool) preg_match('/^SV-[A-F0-9]{24}$/D', $reference);
}
