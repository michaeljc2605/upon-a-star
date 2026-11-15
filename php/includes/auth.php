<?php
declare(strict_types=1);

require_once __DIR__ . '/native-config.php';

/** The default authority remains Express; shared hosting can opt into native PHP. */
function php_auth_cookie(): ?string
{
    $token = $_COOKIE['silver_session'] ?? null;
    return is_string($token) && preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $token) ? $token : null;
}

/** @return array{status:int,data:?array} */
function php_api_request(string $method, string $path, ?array $body = null): array
{
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
        || !preg_match('~^/api/[A-Za-z0-9/_-]+(?:\?[A-Za-z0-9_]+=[A-Za-z0-9%._-]*)?$~D', $path)) {
        throw new InvalidArgumentException('The local API request is invalid.');
    }
    if (php_native_enabled()) {
        // Internal writes originate only in existing PHP forms. Never infer
        // this privilege from a browser header, JSON field or query parameter.
        if ($method !== 'GET' && (!function_exists('verify_csrf') || !verify_csrf($_POST['csrf_token'] ?? null))) {
            return ['status' => 403, 'data' => ['error' => 'Your form session expired. Please try again.']];
        }
        require_once dirname(__DIR__) . '/native/api.php';
        $result = native_dispatch($method, $path, $body, true);
        native_emit_cookies($result);
        return ['status' => $result['status'], 'data' => $result['data']];
    }
    $token = php_auth_cookie();
    if ($token === null) {
        return ['status' => 401, 'data' => null];
    }
    $origin = rtrim(php_setting('PHP_AUTH_API_ORIGIN') ?: 'http://127.0.0.1:4000', '/');
    $parts = parse_url($origin);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
        || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('PHP_AUTH_API_ORIGIN must be a local HTTP origin.');
    }
    $request = curl_init($origin . $path);
    if ($request === false) {
        return ['status' => 0, 'data' => null];
    }
    $cookie = 'silver_session=' . $token;
    $headers = ['Accept: application/json'];
    if ($method !== 'GET') {
        // The browser posts to PHP with a PHP CSRF token first. Express also
        // requires its double-submit CSRF header on this server-to-server call.
        $csrf = bin2hex(random_bytes(24));
        $cookie .= '; silver_csrf=' . $csrf;
        $headers[] = 'X-CSRF-Token: ' . $csrf;
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method !== 'GET') {
        curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if (!is_string($response)) {
        return ['status' => 0, 'data' => null];
    }
    $decoded = json_decode($response, true);
    return ['status' => $status, 'data' => is_array($decoded) ? $decoded : null];
}

function php_api_get(string $path): ?array
{
    $response = php_api_request('GET', $path);
    return $response['status'] === 200 ? $response['data'] : null;
}

/** @return ?array{id:int,displayName:string,email:string,role:string,onboardingComplete:bool,dateOfBirth:?string} */
function php_current_user(): ?array
{
    static $checked = false;
    static $user = null;
    if ($checked) {
        return $user;
    }
    $checked = true;
    $result = php_api_get('/api/auth/me');
    $candidate = $result['user'] ?? null;
    if (!is_array($candidate)) {
        return null;
    }
    $id = filter_var($candidate['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $email = $candidate['email'] ?? null;
    if ($id === false || !is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $name = trim(is_string($candidate['displayName'] ?? null) ? $candidate['displayName'] : '');
    $user = [
        'id' => $id,
        'displayName' => $name !== '' ? mb_substr($name, 0, 160) : $email,
        'email' => strtolower($email),
        'role' => is_string($candidate['role'] ?? null) ? $candidate['role'] : 'customer',
        'onboardingComplete' => !empty($candidate['onboardingComplete']),
        'dateOfBirth' => is_string($candidate['dateOfBirth'] ?? null) ? substr($candidate['dateOfBirth'], 0, 10) : null,
    ];
    return $user;
}

function php_signin_url(): string
{
    if (php_native_enabled()) return base_url('api/auth/google') . '?client=php';
    // Use localhost in the browser: OAuth's host-only cookie is shared with
    // the PHP site across ports, but not with 127.0.0.1.
    $origin = rtrim(php_setting('PHP_AUTH_BROWSER_ORIGIN') ?: 'http://localhost:4000', '/');
    return $origin . '/api/auth/google?client=php';
}

function php_booking_requested_performance_id(): ?int
{
    $raw = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['performance_id'] ?? null)
        : ($_GET['performance_id'] ?? null);
    if (!is_string($raw)) {
        return null;
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function php_remember_booking_intent(): void
{
    $id = php_booking_requested_performance_id();
    if ($id !== null) {
        $_SESSION['php_booking_performance_id'] = $id;
    }
}

function php_take_booking_intent_url(): ?string
{
    $stored = $_SESSION['php_booking_performance_id'] ?? null;
    unset($_SESSION['php_booking_performance_id']);
    if (!is_int($stored) || $stored < 1) {
        return null;
    }
    return base_url('book.php?performance_id=' . $stored);
}

function php_resumable_confirmation_reference(array $user): ?string
{
    $reference = $_SESSION['php_email_resume_reference'] ?? null;
    if ($reference === null) {
        return null;
    }
    $access = $_SESSION['booking_access'] ?? null;
    $accessEmail = is_array($access) && is_string($reference) ? ($access[$reference] ?? null) : null;
    if (!is_string($reference) || !booking_reference_is_valid($reference)
        || !is_string($accessEmail)
        || !hash_equals(strtolower($user['email']), strtolower($accessEmail))) {
        unset($_SESSION['php_email_resume_reference']);
        return null;
    }
    return $reference;
}

function php_take_email_resume_url(array $user): ?string
{
    $reference = php_resumable_confirmation_reference($user);
    if ($reference === null) {
        return null;
    }
    unset($_SESSION['php_email_resume_reference']);
    return base_url('confirmation.php?reference=' . rawurlencode($reference));
}

function php_require_booking_identity(): void
{
    $user = php_current_user();
    if ($user === null || !$user['onboardingComplete']) {
        php_remember_booking_intent();
    } else {
        unset($_SESSION['php_booking_performance_id']);
    }
    if (php_auth_cookie() !== null && $user === null) {
        // A browser carrying an account cookie must not silently become a
        // guest booking if the account service is unavailable or the session
        // has expired. No reservation has been written at this point.
        require_once __DIR__ . '/../../base/includes/layout.php';
        http_response_code(503);
        header('Cache-Control: private, no-store');
        page_header('Reconnect your account', 'booking');
        echo '<main class="section booking-page booking-lookup"><div class="booking-layout"><section class="panel booking-panel">';
        echo '<p class="eyebrow">Your account</p><h1>Reconnect before booking.</h1>';
        echo '<p>We could not verify your Google session, so no reservation was saved. Sign in again, then choose your seats.</p>';
        echo '<a class="primary" href="' . h(php_signin_url()) . '">Sign in with Google →</a>';
        echo '</section></div></main>';
        page_footer();
        exit;
    }
    if ($user === null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $destination = base_url('book.php');
            $id = php_booking_requested_performance_id();
            if ($id !== null) {
                $destination .= '?performance_id=' . $id;
            }
            header('Location: ' . $destination, true, 303);
            exit;
        }
        require_once __DIR__ . '/../../base/includes/layout.php';
        http_response_code(401);
        header('Cache-Control: private, no-store');
        page_header('Sign in to book', 'showtimes');
        echo '<main class="section booking-page booking-lookup"><div class="booking-layout">';
        echo '<section class="panel booking-panel"><p class="eyebrow">Your cinema night</p>';
        echo '<h1>Sign in to choose your seats.</h1>';
        echo '<p>Use your Google account to reserve tickets and receive your booking details. Your selected screening will be ready when you return.</p>';
        echo '<a class="primary" href="' . h(php_signin_url()) . '">Sign in with Google →</a>';
        echo '<p><a class="text-link" href="' . h(base_url('showtimes.php')) . '">Browse showtimes →</a></p></section>';
        echo '<aside class="panel booking-panel booking-lookup-art"><img src="' . h(base_url('assets/movies/up/backdrop.webp')) . '" alt="Colorful adventure film artwork from Up">';
        echo '<h2>Your next story awaits.</h2><p>Choose your seats after signing in.</p></aside>';
        echo '</div></main>';
        page_footer();
        exit;
    }
    if (!$user['onboardingComplete']) {
        header('Location: ' . base_url('profile.php'), true, 303);
        exit;
    }
}

/** Optional header extension; the assessed /base/ pages never define it. */
function cinema_extra_header_actions(): void
{
    $user = php_current_user();
    if ($user === null) {
        echo '<a class="php-auth-link" href="' . h(php_signin_url()) . '">Sign in with Google</a>';
        return;
    }
    echo '<a class="php-auth-link" href="' . h(base_url('my-bookings.php')) . '">My tickets</a>';
    echo '<a class="php-auth-link" href="' . h(base_url('profile.php')) . '">Profile</a>';
    echo '<form class="php-auth-form" method="post" action="' . h(base_url('logout.php')) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    echo '<button class="php-auth-logout" type="submit">Sign out</button></form>';
}
