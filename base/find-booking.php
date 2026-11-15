<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = strtoupper(is_string($_POST['reference'] ?? null) ? trim($_POST['reference']) : '');
    $email = strtolower(is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '');
    $lookup = ['reference' => $reference, 'email' => $email];
    $error = '';

    $attempts = $_SESSION['booking_lookup_attempts'] ?? [];
    $attempts = is_array($attempts) ? array_values(array_filter($attempts, static function ($time): bool {
        return is_int($time) && $time > time() - 600;
    })) : [];
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } elseif (count($attempts) >= 10) {
        $error = 'Please wait a few minutes before trying again.';
    } elseif (!booking_reference_is_valid($reference) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid booking reference and email address.';
    } else {
        $attempts[] = time();
        $_SESSION['booking_lookup_attempts'] = $attempts;
        try {
            $query = db()->prepare('SELECT customer_email FROM bookings WHERE public_reference = ? AND LOWER(customer_email) = ? LIMIT 1');
            $query->execute([$reference, $email]);
            if ($query->fetch()) {
                $_SESSION['booking_access'][$reference] = $email;
                header('Location: ' . base_url('confirmation.php?reference=' . rawurlencode($reference)), true, 303);
                exit;
            }
            $error = 'We could not find a reservation with those details. Check the reference and email, then try again.';
        } catch (PDOException $exception) {
            error_log('Base booking lookup error: ' . $exception->getMessage());
            $error = 'We could not check reservations right now. Please try again shortly.';
        }
    }
    $_SESSION['booking_lookup_flash'] = ['error' => $error, 'old' => $lookup];
    header('Location: ' . base_url('find-booking.php'), true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$flash = $_SESSION['booking_lookup_flash'] ?? ['error' => '', 'old' => []];
unset($_SESSION['booking_lookup_flash']);
$error = is_array($flash) && is_string($flash['error'] ?? null) ? $flash['error'] : '';
$old = is_array($flash) && is_array($flash['old'] ?? null) ? $flash['old'] : [];

page_header('Find a Reservation', 'booking');
?>
<main class="section booking-page booking-lookup">
  <div class="heading">
    <div>
      <p class="eyebrow">Your night at Upon a Star</p>
      <h1>Find your <em>reservation.</em></h1>
      <span>Have your booking reference and the email used for your reservation ready.</span>
    </div>
  </div>

  <div class="booking-layout">
    <section class="panel booking-panel" aria-labelledby="lookup-title">
      <p class="eyebrow">A seat with your name on it</p>
      <h2 id="lookup-title">Look up your booking</h2>
      <p>Enter both details to see your movie, showtime, seat numbers, and ticket codes. Your reference appears on the confirmation page shown after booking.</p>
      <?php if ($error): ?><p class="notice booking-alert booking-error" role="alert"><?= h($error) ?></p><?php endif; ?>
      <form method="post" action="<?= h(base_url('find-booking.php')) ?>" class="booking-lookup-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <label for="lookup-reference">Booking reference</label>
        <input id="lookup-reference" name="reference" type="text" required minlength="27" maxlength="27" pattern="[Ss][Vv]-[A-Fa-f0-9]{24}" title="Use SV- followed by 24 characters using 0–9 and A–F" autocomplete="off" placeholder="SV-XXXXXXXXXXXXXXXXXXXXXXXX" value="<?= h($old['reference'] ?? '') ?>">
        <label for="lookup-email">Email address</label>
        <input id="lookup-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= h($old['email'] ?? '') ?>">
        <button class="primary" type="submit">Find reservation →</button>
      </form>
      <p>Need help? Bring your name and showtime to the cinema desk. Our team can check the booking with you in person.</p>
    </section>

    <aside class="panel booking-panel booking-lookup-art">
      <img src="<?= h(base_url('assets/movies/up/backdrop.webp')) ?>" alt="Colorful adventure scene from Up">
      <p class="eyebrow">A little cinema magic</p>
      <h2>The good part is still ahead.</h2>
      <p>Find your reservation now, then arrive early for the trailers, snacks, and a screen full of stories.</p>
      <a href="<?= h(base_url('showtimes.php')) ?>">Explore showtimes →</a>
    </aside>
  </div>
</main>
<?php page_footer(); ?>
