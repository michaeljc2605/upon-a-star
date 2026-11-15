<?php
declare(strict_types=1);
require_once __DIR__ . '/_start.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo 'Your session expired. Return to your profile and try again.';
    exit;
}
if (php_auth_cookie() !== null) {
    $result = php_api_request('POST', '/api/auth/logout');
    if ($result['status'] !== 204) {
        http_response_code(503);
        header('Cache-Control: private, no-store');
        require_once __DIR__ . '/../base/includes/layout.php';
        page_header('Sign out', 'booking');
        echo '<main class="section booking-page booking-lookup"><div class="booking-layout"><section class="panel booking-panel">';
        echo '<p class="eyebrow">Your account</p><h1>Sign out is unavailable.</h1>';
        echo '<p>Your account session could not be closed yet. Please try again.</p>';
        echo '<form method="post" action="' . h(base_url('logout.php')) . '">';
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
        echo '<button class="primary" type="submit">Try signing out again →</button></form>';
        echo '</section></div></main>';
        page_footer();
        exit;
    }
}
$_SESSION = [];
session_regenerate_id(true);
setcookie('silver_session', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
unset($_COOKIE['silver_session']);
header('Location: ' . base_url(), true, 303);
exit;
