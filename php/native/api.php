<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/native-config.php';
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/customer.php';
require_once __DIR__ . '/operations.php';

/**
 * In-process adapter and HTTP dispatcher. Context is a PHP-only argument used by
 * tests; neither it nor the trusted internal flag is read from browser input.
 *
 * @return array{status:int,data:?array,cookies?:array,location?:string}
 */
function native_dispatch(string $method, string $path, ?array $body = null, bool $internal = false, ?array $context = null): array
{
    $previous = $GLOBALS['native_request_context'] ?? null;
    $GLOBALS['native_request_context'] = $context ?? ['cookies' => $_COOKIE, 'server' => $_SERVER];
    try {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) native_error('Method not allowed.', 405);
        $parts = parse_url($path);
        if (!$parts || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])
            || !preg_match('~^/api/[A-Za-z0-9/_-]+$~D', $parts['path'] ?? '')) native_error('Invalid API path.');
        $route = $parts['path'];
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        if (count($query) > 12) native_error('Too many query parameters.');
        foreach ($query as $value) if (!is_string($value) || strlen($value) > 4096) native_error('Invalid query parameter.');
        // Keep PHP's account/dashboard reads out of the browser request budget,
        // while mutations share the same real-peer limit as direct API calls.
        // An expired internal session must still yield 401, not a throttle error.
        if ($internal && $method !== 'GET' && $route !== '/api/auth/logout') native_require_user();
        if (!$internal || $method !== 'GET') {
            native_rate_limit('api', 300);
            if ($route === '/api/auth/google' || $route === '/api/auth/google/callback') native_rate_limit('google', 40);
        }
        foreach (['native_auth_route', 'native_customer_route', 'native_operations_route'] as $handler) {
            $result = $handler($method, $route, $query, $body, $internal);
            if ($result !== null) return $result;
        }
        return ['status' => 404, 'data' => ['error' => 'API route not found.']];
    } catch (NativeApiError $error) {
        return ['status' => $error->status, 'data' => ['error' => $error->getMessage()], 'cookies' => $error->cookies];
    } catch (Throwable $error) {
        // Never return SQL, credentials, provider responses or private records.
        error_log('Native API operation failed (' . get_class($error) . ').');
        if ($error instanceof PDOException && in_array((string) $error->getCode(), ['23000', '40001'], true)) {
            return ['status' => 409, 'data' => ['error' => 'That change conflicts with an existing record. Refresh and try again.']];
        }
        return ['status' => 503, 'data' => ['error' => 'The cinema service is temporarily unavailable. Please try again.']];
    } finally {
        if ($previous === null) unset($GLOBALS['native_request_context']);
        else $GLOBALS['native_request_context'] = $previous;
    }
}

function native_emit_cookies(array $result): void
{
    foreach ($result['cookies'] ?? [] as $cookie) setcookie($cookie['name'], $cookie['value'], $cookie['options']);
}
