<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

try {
    require_once __DIR__ . '/../includes/native-config.php';
    if (!php_native_enabled()) {
        http_response_code(404);
        echo json_encode(['error' => 'API route not found.']);
        exit;
    }
    // API dispatch applies its request guard before any catalogue maintenance.
    // Canonical PHP pages retain their existing on-visit archive behaviour.
    define('CINEMA_NATIVE_API_REQUEST', true);
    require_once __DIR__ . '/../_start.php';
    require_once __DIR__ . '/api.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $raw = file_get_contents('php://input', false, null, 0, 102401);
        if (!is_string($raw) || strlen($raw) > 102400) native_error('Request body is too large.', 413);
        if ($raw !== '') {
            if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) native_error('Send a JSON request body.', 415);
            try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { native_error('The request contains invalid JSON.'); }
            if (!is_array($body) || ($body !== [] && array_is_list($body))) native_error('Send a JSON object.');
        }
    }
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $prefix = rtrim(base_url(), '/');
    if ($prefix !== '' && str_starts_with($path, $prefix . '/api/')) $path = substr($path, strlen($prefix));
    $result = native_dispatch($method, $path, $body);
    native_emit_cookies($result);
    http_response_code($result['status']);
    if (isset($result['location'])) {
        header('Location: ' . $result['location']);
    } elseif ($result['status'] !== 204) {
        echo json_encode($result['data'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    $status = $error instanceof NativeApiError ? $error->status : 503;
    http_response_code($status);
    if ($status === 503) error_log('Native API startup failed (' . get_class($error) . ').');
    echo json_encode(['error' => $error instanceof NativeApiError ? $error->getMessage() : 'The cinema service is temporarily unavailable. Please try again.']);
}
