<?php
declare(strict_types=1);

// Run with PHP_DB_NAME=process-precedence-test php -d disable_functions=putenv ...
if (function_exists('putenv')) throw new RuntimeException('Run this check with putenv disabled.');
if (getenv('PHP_DB_NAME') !== 'process-precedence-test') throw new RuntimeException('Set the isolated process-precedence test value.');
$root = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/upon-a-star-config-test-' . bin2hex(random_bytes(8));
$files = [];
$directories = [];
$checks = 0;
function check_private_config(bool $condition): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('Private configuration assertion failed.');
    $checks++;
}
try {
    foreach (['', '/php', '/php/includes', '/php/native', '/base', '/base/includes', '/sessions'] as $part) {
        mkdir($scratch . $part, 0700);
        $directories[] = $scratch . $part;
    }
    foreach (['php/includes/native-config.php', 'php/native/shared.php', 'php/native/auth.php', 'base/includes/bootstrap.php'] as $path) {
        copy($root . '/' . $path, $scratch . '/' . $path);
        $files[] = $scratch . '/' . $path;
    }
    $configuration = [
        'PHP_BACKEND' => 'native', 'PHP_SITE_PATH' => '/cinema', 'PHP_DB_NAME' => 'private_schema_unused',
        'PHP_PUBLIC_URL' => 'https://cinema.example.invalid/cinema', 'SESSION_TTL_MINUTES' => '90',
        'BASE_DB_HOST' => 'database.example.invalid', 'BASE_DB_USER' => 'unused_test_user',
        'BASE_DB_PASSWORD' => '', 'GOOGLE_CLIENT_ID' => 'unused-client', 'GOOGLE_CLIENT_SECRET' => 'unused-secret',
        'GOOGLE_REDIRECT_URI' => 'https://cinema.example.invalid/cinema/api/auth/google/callback',
        'GOOGLE_OWNER_EMAILS' => 'owner@example.invalid', 'EMAILJS_SERVICE_ID' => 'unused-service',
    ];
    $privatePath = $scratch . '/php/config.local.php';
    file_put_contents($privatePath, '<?php return ' . var_export($configuration, true) . ';');
    $files[] = $privatePath;
    require $scratch . '/php/includes/native-config.php';
    define('CINEMA_PHP_ENHANCED', true);
    php_configure_site();
    session_save_path($scratch . '/sessions');
    require $scratch . '/base/includes/bootstrap.php';
    require $scratch . '/php/native/shared.php';
    require $scratch . '/php/native/auth.php';
    check_private_config(php_native_enabled());
    check_private_config(base_setting('BASE_DB_NAME') === 'process-precedence-test');
    check_private_config(base_setting('BASE_DB_HOST') === $configuration['BASE_DB_HOST']);
    check_private_config(base_setting('BASE_DB_PASSWORD') === '');
    check_private_config(base_url('profile.php') === '/cinema/profile.php');
    check_private_config(php_setting('EMAILJS_SERVICE_ID') === $configuration['EMAILJS_SERVICE_ID']);
    check_private_config(native_session_ttl() === 90);
    check_private_config(native_secure_cookie());
    check_private_config(native_google_settings()['GOOGLE_REDIRECT_URI'] === $configuration['GOOGLE_REDIRECT_URI']);
    $csrf = native_auth_route('GET', '/api/auth/csrf', [], null, false);
    check_private_config(isset($csrf['data']['token']) && $csrf['data']['token'] === $csrf['cookies'][0]['value']);
    check_private_config(getenv('BASE_PATH') === false && getenv('BASE_DB_NAME') === false);
    check_private_config(php_setting('NOT_A_SETTING') === false);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (glob($scratch . '/sessions/*') ?: [] as $path) unlink($path);
    foreach (array_reverse($files) as $path) unlink($path);
    foreach (array_reverse($directories) as $path) rmdir($path);
}
echo "Private configuration with putenv disabled: {$checks} checks passed.\n";
