<?php
declare(strict_types=1);
require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/../base/includes/layout.php';

header('Cache-Control: private, no-store');
$user = php_current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user === null) {
        header('Location: ' . base_url('profile.php'), true, 303);
        exit;
    }
    $name = is_string($_POST['display_name'] ?? null) ? trim($_POST['display_name']) : '';
    $date = is_string($_POST['date_of_birth'] ?? null) ? trim($_POST['date_of_birth']) : '';
    $birthday = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $validDate = $birthday instanceof DateTimeImmutable
        && $birthday->format('Y-m-d') === $date
        && $birthday <= new DateTimeImmutable('today');
    $error = '';
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 160 || !preg_match('/\p{L}/u', $name)
        || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        $error = 'Enter a name of 2 to 160 characters with at least one letter.';
    } elseif (!$validDate) {
        $error = 'Choose a valid date of birth no later than today.';
    } else {
        $saved = php_api_request('PUT', '/api/profile', ['displayName' => $name, 'dateOfBirth' => $date]);
        if ($saved['status'] === 200) {
            $_SESSION['php_profile_flash'] = ['success' => 'Your profile has been saved.'];
            header('Location: ' . base_url('profile.php'), true, 303);
            exit;
        }
        $error = 'Your profile could not be saved. Check the details and try again.';
    }
    $_SESSION['php_profile_flash'] = ['error' => $error, 'name' => $name, 'date' => $date];
    header('Location: ' . base_url('profile.php'), true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (is_array($user) && $user['onboardingComplete']) {
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
$flash = $_SESSION['php_profile_flash'] ?? [];
unset($_SESSION['php_profile_flash']);
$flash = is_array($flash) ? $flash : [];
$nameValue = is_string($flash['name'] ?? null) ? $flash['name'] : ($user['displayName'] ?? '');
$dateValue = is_string($flash['date'] ?? null) ? $flash['date'] : ($user['dateOfBirth'] ?? '');

page_header($user === null ? 'Sign in' : 'Your profile', 'booking');
?>
<main class="section booking-page booking-lookup">
  <div class="heading"><div><p class="eyebrow">Your night at Upon a Star</p>
    <h1><?= $user === null ? 'Sign in with <em>Google.</em>' : 'Your <em>profile.</em>' ?></h1>
    <span><?= $user === null ? 'Use your Google account to keep your cinema plans together.' : 'Your Google account connects your profile, reservations, tickets and staff access.' ?></span>
  </div></div>
  <div class="booking-layout">
    <section class="panel booking-panel">
      <?php if ($user === null): ?>
        <p class="eyebrow">One account, your cinema night</p>
        <h2>Welcome back</h2>
        <p>Use your Google account to keep your cinema plans together on Upon a Star.</p>
        <a class="primary" href="<?= h(php_signin_url()) ?>">Sign in with Google →</a>
      <?php else: ?>
        <p class="eyebrow"><?= $user['onboardingComplete'] ? 'Your account' : 'One last step' ?></p>
        <h2><?= $user['onboardingComplete'] ? 'Your details' : 'Tell us about you' ?></h2>
        <p><?= $user['onboardingComplete'] ? 'Update the name and date of birth shown on your account.' : 'Please complete your profile before continuing with your cinema plans.' ?></p>
        <?php if (is_string($flash['error'] ?? null)): ?><p class="notice booking-alert booking-error" role="alert"><?= h($flash['error']) ?></p><?php endif; ?>
        <?php if (is_string($flash['success'] ?? null)): ?><p class="notice" role="status"><?= h($flash['success']) ?></p><?php endif; ?>
        <form class="booking-lookup-form" method="post" action="<?= h(base_url('profile.php')) ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <label for="display_name">Name</label>
          <input id="display_name" name="display_name" type="text" minlength="2" maxlength="160" autocomplete="name" required value="<?= h($nameValue) ?>">
          <label for="date_of_birth">Date of birth</label>
          <input id="date_of_birth" name="date_of_birth" type="date" max="<?= h(date('Y-m-d')) ?>" required value="<?= h($dateValue) ?>">
          <label for="google_email">Google email</label>
          <input id="google_email" type="email" disabled value="<?= h($user['email']) ?>">
          <button class="primary" type="submit">Save profile →</button>
        </form>
        <p><a class="text-link" href="<?= h(base_url('my-bookings.php')) ?>">View my bookings and tickets →</a></p>
        <?php if (in_array($user['role'], ['owner', 'staff', 'manager'], true)): ?>
          <p><a class="text-link" href="<?= h(base_url('staff.php')) ?>">Open the staff dashboard →</a></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <aside class="panel booking-panel booking-lookup-art">
      <img src="<?= h(base_url('assets/movies/up/backdrop.webp')) ?>" alt="Colorful adventure film artwork from Up">
      <p class="eyebrow">Made for your next visit</p>
      <h2>A little cinema magic.</h2>
      <p>Explore the programme, choose your seats, and keep the details ready for your trip to Upon a Star.</p>
      <a href="<?= h(base_url('showtimes.php')) ?>">Explore showtimes →</a>
    </aside>
  </div>
</main>
<?php page_footer(); ?>
