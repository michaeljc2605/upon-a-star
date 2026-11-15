<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/native/shared.php';
require_once dirname(__DIR__) . '/php/native/auth.php';

$checks = 0;
function check_native(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function native_rejected(callable $operation, int $status): void
{
    try { $operation(); }
    catch (NativeApiError $error) {
        check_native($error->status === $status, 'Incorrect rejection status.');
        return;
    }
    throw new RuntimeException('An invalid operation was accepted.');
}

$token = native_random_token();
check_native(strlen($token) === 43 && native_google_state_valid($token, $token), 'OAuth state should round-trip.');
foreach ([null, '', $token . 'x', str_repeat('a', 43), ['state' => $token]] as $badState) {
    check_native(!native_google_state_valid($badState, $token), 'A missing, altered or malformed OAuth state was accepted.');
}
foreach ([false, 'true', 1, null] as $unverified) {
    native_rejected(static fn() => native_google_user(['sub' => 'identity', 'email' => 'person@example.invalid', 'email_verified' => $unverified]), 401);
}
native_rejected(static fn() => native_google_user(['sub' => '', 'email' => 'person@example.invalid', 'email_verified' => true]), 401);
native_rejected(static fn() => native_google_user(['sub' => 'identity', 'email' => 'invalid', 'email_verified' => true]), 401);
native_rejected(static fn() => native_public_url(), 503);
putenv('PHP_PUBLIC_URL=https://cinema.example.invalid');
check_native(native_public_url() === 'https://cinema.example.invalid', 'Configured origin changed.');
foreach (['http://cinema.example.invalid', 'https://attacker:secret@cinema.example.invalid', 'https://cinema.example.invalid?next=bad'] as $url) {
    putenv('PHP_PUBLIC_URL=' . $url);
    native_rejected(static fn() => native_public_url(), 503);
}
putenv('PHP_PUBLIC_URL=https://cinema.example.invalid');
$cookie = native_cookie('silver_session', $token, time() + 60);
check_native($cookie['options']['secure'] && $cookie['options']['httponly'] && $cookie['options']['samesite'] === 'Lax', 'Public auth cookie protections changed.');

$GLOBALS['native_request_context'] = ['cookies' => [], 'server' => ['REMOTE_ADDR' => '192.0.2.10']];
native_rejected(static fn() => native_require_user(), 401);
native_rejected(static fn() => native_require_csrf(false), 403);
$GLOBALS['native_request_context']['cookies']['silver_csrf'] = $token;
$GLOBALS['native_request_context']['server']['HTTP_X_CSRF_TOKEN'] = $token . 'x';
native_rejected(static fn() => native_require_csrf(false), 403);
$GLOBALS['native_request_context']['server']['HTTP_X_CSRF_TOKEN'] = $token;
native_require_csrf(false);
check_native(true, 'Matching CSRF token was rejected.');
native_rejected(static fn() => native_owner(['role' => 'staff']), 403);
native_rejected(static fn() => native_staff(['role' => 'customer']), 403);
native_staff(['role' => 'manager']);
native_owner(['role' => 'owner']);
check_native(native_date('2024-02-29', 'date') === '2024-02-29', 'Valid leap date rejected.');
native_rejected(static fn() => native_date('2025-02-29', 'date'), 400);
native_rejected(static fn() => native_int(true, 'identifier'), 400);
native_rejected(static fn() => native_int('1e2', 'identifier'), 400);

// Changing a browser cookie or a claimed forwarded IP cannot reset the actual
// peer's shared request limit. Each run uses and removes its own directory.
$directory = sys_get_temp_dir() . '/upon-a-star-auth-test-' . bin2hex(random_bytes(8));
putenv('PHP_RATE_LIMIT_DIR=' . $directory);
try {
    native_rate_limit('test', 2);
    $GLOBALS['native_request_context']['cookies']['silver_session'] = native_random_token();
    $GLOBALS['native_request_context']['server']['HTTP_X_FORWARDED_FOR'] = '192.0.2.99';
    native_rate_limit('test', 2);
    $GLOBALS['native_request_context']['cookies']['silver_session'] = native_random_token();
    native_rejected(static fn() => native_rate_limit('test', 2), 429);
    $GLOBALS['native_request_context']['server']['REMOTE_ADDR'] = '192.0.2.11';
    native_rate_limit('test', 2);
    check_native(true, 'Distinct peer should have its own allowance.');
    require_once dirname(__DIR__) . '/php/native/api.php';
    $context = ['cookies' => [], 'server' => ['REMOTE_ADDR' => '192.0.2.11']];
    for ($attempt = 0; $attempt < 300; $attempt++) native_rate_limit('api', 300);
    check_native(native_dispatch('GET', '/api/health', null, false, $context)['status'] === 429, 'Public API limit was bypassed.');
    check_native(native_dispatch('POST', '/api/auth/logout', [], true, $context)['status'] === 429, 'Internal mutation bypassed the shared limit.');
    check_native(native_dispatch('GET', '/api/auth/me', null, true, $context)['status'] === 401, 'Internal account reads were masked by throttling.');
    check_native(native_dispatch('PUT', '/api/profile', [], true, $context)['status'] === 401, 'Internal missing identity should be reported before a rate error.');
} finally {
    foreach (glob($directory . '/*.json') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
    putenv('PHP_RATE_LIMIT_DIR');
    unset($GLOBALS['native_request_context']);
}
echo "Native auth helpers: {$checks} checks passed.\n";
