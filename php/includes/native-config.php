<?php
declare(strict_types=1);

/** Optional private settings remain request-local: shared hosts disable putenv. */
function php_load_private_config(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) return;
    $settings = require $path;
    if (!is_array($settings)) throw new RuntimeException('The private site configuration is invalid.');
    $allowed = [
        'PHP_BACKEND', 'PHP_SITE_PATH', 'PHP_DB_NAME', 'PHP_PUBLIC_URL', 'PUBLIC_URL',
        'BASE_DB_HOST', 'BASE_DB_PORT', 'BASE_DB_USER', 'BASE_DB_PASSWORD',
        'MYSQL_SSL', 'MYSQL_SSL_CA', 'SESSION_TTL_MINUTES', 'CLIENT_ORIGIN',
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'GOOGLE_OWNER_EMAILS',
        'EMAILJS_SERVICE_ID', 'EMAILJS_TEMPLATE_ID', 'EMAILJS_RESERVATION_TEMPLATE_ID',
        'EMAILJS_PUBLIC_KEY', 'EMAILJS_PRIVATE_KEY', 'PHP_RATE_LIMIT_DIR',
    ];
    foreach ($settings as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowed, true) || !is_string($value)
            || str_contains($value, "\0")) {
            throw new RuntimeException('The private site configuration contains an invalid setting.');
        }
    }
    $GLOBALS['php_private_settings'] = $settings;
}

/** Process settings retain priority; a missing setting remains distinguishable from ''. */
function php_setting(string $name): string|false
{
    if (array_key_exists($name, $GLOBALS['php_setting_overrides'] ?? [])) return $GLOBALS['php_setting_overrides'][$name];
    $process = function_exists('getenv') ? getenv($name) : false;
    if ($process !== false) return $process;
    return $GLOBALS['php_private_settings'][$name] ?? false;
}

/** Internal aliases used by the complete PHP site, never populated from a request. */
function php_configure_site(): void
{
    $path = php_setting('PHP_SITE_PATH');
    $database = php_setting('PHP_DB_NAME');
    $GLOBALS['php_setting_overrides']['BASE_PATH'] = $path === false ? '/SilverVillage' : $path;
    $GLOBALS['php_setting_overrides']['BASE_DB_NAME'] = $database === false ? 'silver_screen' : $database;
}

function php_native_enabled(): bool
{
    return php_setting('PHP_BACKEND') === 'native';
}

php_load_private_config();
if (php_native_enabled()) {
    // A host-level error page may still be needed if PHP itself is unavailable,
    // but application failures must never render connection details or traces.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
