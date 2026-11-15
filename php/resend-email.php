<?php
declare(strict_types=1);

require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/includes/email.php';

header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo 'Use the reservation page to retry the email.';
    exit;
}
$reference = $_POST['reference'] ?? null;
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    if (is_string($reference) && booking_reference_is_valid($reference)) {
        $_SESSION['booking_email_flash'][$reference] = 'expired';
        header('Location: ' . base_url('confirmation.php?reference=' . rawurlencode($reference)), true, 303);
    } else {
        http_response_code(403);
        echo 'Your form session expired. Return to your reservation and try again.';
    }
    exit;
}

$user = php_current_user();
$access = $_SESSION['booking_access'] ?? [];
$accessEmail = is_array($access) && is_string($reference) ? ($access[$reference] ?? null) : null;
if (!is_string($reference) || !booking_reference_is_valid($reference)
    || !is_array($user) || !$user['onboardingComplete']
    || !is_string($accessEmail)
    || !hash_equals(strtolower((string) $user['email']), strtolower($accessEmail))) {
    http_response_code(403);
    echo 'You cannot resend email for this reservation.';
    exit;
}

$query = db()->prepare('SELECT id FROM bookings WHERE public_reference = ? AND LOWER(customer_email) = ? AND booking_status = ? LIMIT 1');
$query->execute([$reference, strtolower((string) $user['email']), 'confirmed']);
$bookingId = $query->fetchColumn();
if ($bookingId === false) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

$lastAttempt = $_SESSION['booking_email_attempted_at'][$reference] ?? 0;
if (is_int($lastAttempt) && time() - $lastAttempt < 60) {
    $_SESSION['booking_email_flash'][$reference] = 'wait';
} else {
    $_SESSION['booking_email_attempted_at'][$reference] = time();
    $_SESSION['booking_email_flash'][$reference] = php_send_reservation_acknowledgement((int) $bookingId);
}
header('Location: ' . base_url('confirmation.php?reference=' . rawurlencode($reference)), true, 303);
exit;
