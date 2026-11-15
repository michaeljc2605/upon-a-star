<?php
declare(strict_types=1);

/** The token format and binary hash match the original Node session authority. */
function native_session_token(): ?string
{
    $token = native_request()['cookies']['silver_session'] ?? null;
    return is_string($token) && preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $token) ? $token : null;
}

function native_require_user(): array
{
    $token = native_session_token();
    if ($token === null) native_error('Authentication required', 401);
    $ttl = native_session_ttl();
    $row = native_query("SELECT s.id AS session_id, s.user_id, u.username, u.display_name,
        u.email, u.role, u.date_of_birth, u.onboarding_complete
        FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.expires_at > CURRENT_TIMESTAMP
          AND s.last_seen_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$ttl} MINUTE)
          AND u.account_status = 'active' LIMIT 1", [hash('sha256', $token, true)])->fetch();
    if (!$row) native_error('Authentication required', 401);
    native_query('UPDATE sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [(int) $row['session_id']]);
    return [
        'id' => (int) $row['user_id'], 'username' => $row['username'],
        'displayName' => $row['display_name'], 'email' => $row['email'], 'role' => $row['role'],
        'dateOfBirth' => $row['date_of_birth'], 'onboardingComplete' => (bool) $row['onboarding_complete'],
    ];
}

function native_google_settings(): array
{
    $settings = [];
    foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'] as $name) {
        $value = php_setting($name);
        if (!is_string($value) || trim($value) === '') native_error('Google sign-in has not been configured yet.', 503);
        $settings[$name] = trim($value);
    }
    $public = native_public_url();
    if ($settings['GOOGLE_REDIRECT_URI'] !== $public . '/api/auth/google/callback') {
        native_error('Google sign-in redirect configuration does not match the public site.', 503);
    }
    return $settings;
}

function native_public_url(): string
{
    $url = rtrim((string) (php_setting('PHP_PUBLIC_URL') ?: php_setting('PUBLIC_URL') ?: ''), '/');
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['scheme'], ['http', 'https'], true)
        || ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
        native_error('Configure the HTTPS public site address before enabling Google sign-in.', 503);
    }
    return $url;
}

function native_google_state_valid(mixed $state, mixed $expected): bool
{
    return is_string($state) && is_string($expected)
        && preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) === 1
        && hash_equals($expected, $state);
}

/** Only fixed Google endpoints are accepted; redirects and unverified TLS are forbidden. */
function native_google_http(string $url, ?array $form = null, ?string $accessToken = null): array
{
    if (!in_array($url, ['https://oauth2.googleapis.com/token', 'https://openidconnect.googleapis.com/v1/userinfo'], true)) {
        throw new LogicException('Unexpected OAuth endpoint.');
    }
    $request = curl_init($url);
    if ($request === false) native_error('Google sign-in is temporarily unavailable.', 503);
    $headers = ['Accept: application/json'];
    if ($accessToken !== null) $headers[] = 'Authorization: Bearer ' . $accessToken;
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($request, $options);
    $raw = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($body)) native_error('Google sign-in could not be completed. Please try again.', 401);
    return $body;
}

function native_google_user(array $profile): array
{
    $subject = $profile['sub'] ?? null;
    $email = is_string($profile['email'] ?? null) ? strtolower(trim($profile['email'])) : '';
    if (!is_string($subject) || $subject === '' || strlen($subject) > 255
        || ($profile['email_verified'] ?? false) !== true
        || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        native_error('A verified Google email address is required.', 401);
    }
    $name = mb_substr(trim(is_string($profile['name'] ?? null) ? $profile['name'] : explode('@', $email)[0]), 0, 160);
    if ($name === '') $name = $email;
    $owners = array_filter(array_map(static fn(string $value): string => strtolower(trim($value)), explode(',', (string) (php_setting('GOOGLE_OWNER_EMAILS') ?: ''))));
    $owner = in_array($email, $owners, true);
    return native_transaction(static function (PDO $connection) use ($subject, $email, $name, $owner): array {
        $matches = native_query('SELECT id, google_subject, role, account_status, onboarding_complete, display_name FROM users WHERE google_subject = ? OR email = ? FOR UPDATE', [$subject, $email])->fetchAll();
        // Do not merge two accounts or silently relink an existing Google identity.
        if (count($matches) > 1 || (count($matches) === 1 && $matches[0]['google_subject'] !== null && $matches[0]['google_subject'] !== $subject)) {
            native_error('This Google identity conflicts with an existing account. Contact the cinema.', 409);
        }
        if ($matches) {
            $user = $matches[0];
            if ($user['account_status'] !== 'active') native_error('This account has been disabled. Please contact the cinema.', 403);
            native_query('UPDATE users SET display_name = ?, email = ?, google_subject = ?, role = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [
                trim($user['display_name']) !== '' ? $user['display_name'] : $name,
                $email, $subject, $owner ? 'owner' : $user['role'], (int) $user['id'],
            ]);
            return ['id' => (int) $user['id'], 'onboardingComplete' => (bool) $user['onboarding_complete']];
        }
        native_query('INSERT INTO users (username, display_name, email, google_subject, password_hash, role, last_login_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)', [
            'google-' . substr(hash('sha256', $subject), 0, 32), $name, $email, $subject,
            bin2hex(random_bytes(32)), $owner ? 'owner' : 'customer',
        ]);
        return ['id' => (int) $connection->lastInsertId(), 'onboardingComplete' => false];
    });
}

function native_create_session(int $userId): array
{
    $token = native_random_token();
    $ttl = native_session_ttl();
    $server = native_request()['server'];
    $ip = is_string($server['REMOTE_ADDR'] ?? null) && filter_var($server['REMOTE_ADDR'], FILTER_VALIDATE_IP) ? $server['REMOTE_ADDR'] : null;
    $agent = is_string($server['HTTP_USER_AGENT'] ?? null) ? mb_substr($server['HTTP_USER_AGENT'], 0, 255) : null;
    native_query("INSERT INTO sessions (token_hash, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$ttl} MINUTE))", [
        hash('sha256', $token, true), $userId, $ip, $agent,
    ]);
    return native_cookie('silver_session', $token, time() + $ttl * 60);
}

function native_auth_route(string $method, string $route, array $query, ?array $body, bool $internal): ?array
{
    if ($method === 'GET' && $route === '/api/health') return ['status' => 200, 'data' => ['ok' => true, 'service' => 'silver-village-api']];
    if ($method === 'GET' && $route === '/api/auth/csrf') {
        $token = native_random_token();
        return ['status' => 200, 'data' => ['token' => $token], 'cookies' => [native_cookie('silver_csrf', $token, time() + 3600, false)]];
    }
    if ($method === 'GET' && $route === '/api/auth/me') return ['status' => 200, 'data' => ['user' => native_require_user()]];
    if ($method === 'POST' && $route === '/api/auth/logout') {
        native_require_csrf($internal);
        $token = native_session_token();
        if ($token !== null) native_query('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token, true)]);
        return ['status' => 204, 'data' => null, 'cookies' => [native_cookie('silver_session', '', time() - 3600)]];
    }
    if ($method === 'PUT' && $route === '/api/profile') {
        $user = native_require_user();
        native_require_csrf($internal);
        $name = native_string($body['displayName'] ?? null, 'name', 2, 160);
        if (!preg_match('/\p{L}/u', $name) || preg_match('/[\x00-\x1F\x7F]/', $name)) native_error('Enter a name with at least one letter and no control characters.');
        $birthday = native_date($body['dateOfBirth'] ?? null, 'date of birth');
        if ($birthday > date('Y-m-d')) native_error('Date of birth cannot be in the future.');
        native_query('UPDATE users SET display_name = ?, date_of_birth = ?, onboarding_complete = 1 WHERE id = ?', [$name, $birthday, $user['id']]);
        return ['status' => 200, 'data' => ['user' => array_replace($user, ['displayName' => $name, 'dateOfBirth' => $birthday, 'onboardingComplete' => true])]];
    }
    if ($method !== 'GET' || !in_array($route, ['/api/auth/google', '/api/auth/google/callback'], true)) return null;
    $settings = native_google_settings();
    $cookiePath = base_url('api/auth/google');
    if ($route === '/api/auth/google') {
        $client = ($query['client'] ?? 'php') === 'react' ? 'react' : 'php';
        $state = native_random_token();
        $params = [
            'client_id' => $settings['GOOGLE_CLIENT_ID'], 'redirect_uri' => $settings['GOOGLE_REDIRECT_URI'],
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account',
        ];
        return ['status' => 302, 'data' => null, 'location' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'cookies' => [
            native_cookie('silver_google_state', $state, time() + 600, true, $cookiePath),
            native_cookie('silver_google_client', $client, time() + 600, true, $cookiePath),
        ]];
    }
    $cookies = [native_cookie('silver_google_state', '', time() - 3600, true, $cookiePath), native_cookie('silver_google_client', '', time() - 3600, true, $cookiePath)];
    try {
        $requestCookies = native_request()['cookies'];
        if (!native_google_state_valid($query['state'] ?? null, $requestCookies['silver_google_state'] ?? null)) native_error('Google sign-in could not be verified. Please try again.');
        if (isset($query['error'])) native_error('Google sign-in was cancelled.');
        $code = native_string($query['code'] ?? null, 'Google sign-in code', 1, 4096);
        $token = native_google_http('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => $settings['GOOGLE_CLIENT_ID'], 'client_secret' => $settings['GOOGLE_CLIENT_SECRET'],
            'redirect_uri' => $settings['GOOGLE_REDIRECT_URI'], 'grant_type' => 'authorization_code',
        ]);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '' || preg_match('/[\r\n]/', $token['access_token'])) native_error('Google sign-in token exchange failed.', 401);
        $profile = native_google_http('https://openidconnect.googleapis.com/v1/userinfo', null, $token['access_token']);
        $user = native_google_user($profile);
        $previous = native_session_token();
        if ($previous !== null) native_query('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $previous, true)]);
        $cookies[] = native_create_session($user['id']);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $target = native_public_url();
        $profilePath = 'profile.php';
        if (($requestCookies['silver_google_client'] ?? '') === 'react') {
            $react = rtrim((string) (php_setting('CLIENT_ORIGIN') ?: ''), '/');
            $parts = parse_url($react);
            if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) native_error('The React application address has not been configured.', 503);
            $target = $react;
            $profilePath = 'profile';
        }
        return ['status' => 302, 'data' => null, 'location' => $target . '/' . ($user['onboardingComplete'] ? '' : $profilePath), 'cookies' => $cookies];
    } catch (Throwable $error) {
        if (!$error instanceof NativeApiError) {
            error_log('Native OAuth operation failed (' . get_class($error) . ').');
            $error = new NativeApiError('Google sign-in is temporarily unavailable. Please try again.', 503);
        }
        $error->cookies = $cookies;
        throw $error;
    }
}
