<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/native-config.php';

class NativeApiError extends RuntimeException
{
    public array $cookies = [];
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

function native_error(string $message, int $status = 400): never
{
    throw new NativeApiError($message, $status);
}

/** @return array{cookies:array,server:array} */
function native_request(): array
{
    return $GLOBALS['native_request_context'] ?? ['cookies' => $_COOKIE, 'server' => $_SERVER];
}

function native_query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    foreach (array_values($params) as $index => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue($index + 1, $value, $type);
    }
    $statement->execute();
    return $statement;
}

function native_transaction(callable $callback): mixed
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $result = $callback($connection);
        $connection->commit();
        return $result;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}

function native_int(mixed $value, string $name, int $min = 1, int $max = PHP_INT_MAX): int
{
    if (is_bool($value) || (!is_int($value) && !is_string($value))
        || filter_var($value, FILTER_VALIDATE_INT) === false
        || (int) $value < $min || (int) $value > $max) {
        native_error('Enter a valid ' . $name . '.');
    }
    return (int) $value;
}

function native_string(mixed $value, string $name, int $min = 0, int $max = PHP_INT_MAX): string
{
    if (!is_string($value)) native_error('Enter a valid ' . $name . '.');
    $value = trim($value);
    if (mb_strlen($value) < $min || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        native_error('Enter a valid ' . $name . '.');
    }
    return $value;
}

function native_date(mixed $value, string $name): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) native_error('Enter a valid ' . $name . '.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) native_error('Enter a valid ' . $name . '.');
    return $value;
}

function native_require_csrf(bool $internal): void
{
    // Only trusted in-process PHP callers can supply this argument. The bridge
    // checks the existing PHP form token before enabling this path.
    if ($internal) return;
    $request = native_request();
    $cookie = $request['cookies']['silver_csrf'] ?? null;
    $header = $request['server']['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($cookie) || !is_string($header) || strlen($cookie) < 32 || strlen($cookie) > 128
        || !hash_equals($cookie, $header)) native_error('CSRF validation failed', 403);
}

function native_owner(array $user): void
{
    if (($user['role'] ?? null) !== 'owner') native_error('Owner access required', 403);
}

function native_staff(array $user): void
{
    if (!in_array($user['role'] ?? null, ['owner', 'staff', 'manager'], true)) native_error('Staff access required', 403);
}

function native_session_ttl(): int
{
    $configured = php_setting('SESSION_TTL_MINUTES') ?: '60';
    return ctype_digit($configured) && (int) $configured > 0 && (int) $configured <= 1440 ? (int) $configured : 60;
}

function native_secure_cookie(): bool
{
    $url = php_setting('PHP_PUBLIC_URL') ?: php_setting('PUBLIC_URL') ?: '';
    return str_starts_with($url, 'https://')
        || (!empty(native_request()['server']['HTTPS']) && native_request()['server']['HTTPS'] !== 'off');
}

function native_cookie(string $name, string $value, int $expires, bool $httpOnly = true, string $path = '/'): array
{
    return ['name' => $name, 'value' => $value, 'options' => [
        'expires' => $expires, 'path' => $path, 'secure' => native_secure_cookie(),
        'httponly' => $httpOnly, 'samesite' => 'Lax',
    ]];
}

function native_random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** Shared-host limits use a locked server-side file, never a resettable browser cookie. */
function native_rate_limit(string $bucket, int $limit, int $window = 900): void
{
    $ip = native_request()['server']['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
    $directory = php_setting('PHP_RATE_LIMIT_DIR') ?: sys_get_temp_dir() . '/upon-a-star-' . substr(hash('sha256', __DIR__), 0, 20);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) native_error('Request protection is temporarily unavailable.', 503);
    $path = $directory . '/' . hash('sha256', $bucket . '|' . $ip) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) native_error('Request protection is temporarily unavailable.', 503);
    try {
        if (!flock($handle, LOCK_EX)) native_error('Request protection is temporarily unavailable.', 503);
        $state = json_decode(stream_get_contents($handle) ?: '{}', true);
        $now = time();
        if (!is_array($state) || (int) ($state['until'] ?? 0) <= $now) $state = ['until' => $now + $window, 'count' => 0];
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) === false) native_error('Request protection is temporarily unavailable.', 503);
        fflush($handle);
        if ($state['count'] > $limit) native_error('Too many requests. Please try again shortly.', 429);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
