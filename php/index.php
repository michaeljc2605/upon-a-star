<?php
declare(strict_types=1);

require_once __DIR__ . '/_start.php';
$user = php_current_user();
if (is_array($user)) {
    $confirmationReference = php_resumable_confirmation_reference($user);
    if (!$user['onboardingComplete'] && ($confirmationReference !== null || isset($_SESSION['php_booking_performance_id']))) {
        header('Location: ' . base_url('profile.php'), true, 303);
        exit;
    }
    if ($user['onboardingComplete']) {
        $confirmationUrl = php_take_email_resume_url($user);
        if ($confirmationUrl !== null) {
            header('Location: ' . $confirmationUrl, true, 303);
            exit;
        }
        $bookingUrl = php_take_booking_intent_url();
        if ($bookingUrl !== null) {
            header('Location: ' . $bookingUrl, true, 303);
            exit;
        }
    }
}
require dirname(__DIR__) . '/base/index.php';
