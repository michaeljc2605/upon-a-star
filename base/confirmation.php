<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

header('Cache-Control: private, no-store');
$reference = $_GET['reference'] ?? '';
if (!is_string($reference) || !booking_reference_is_valid($reference)) {
    header('Location: ' . base_url('find-booking.php'), true, 302);
    exit;
}
$access = $_SESSION['booking_access'] ?? [];
$email = is_array($access) ? ($access[$reference] ?? null) : null;
if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ' . base_url('find-booking.php'), true, 302);
    exit;
}

$query = db()->prepare(<<<'SQL'
    SELECT b.id, b.public_reference, b.customer_name, b.customer_email,
           b.total_amount, b.booking_status, b.created_at,
           b.acknowledgement_sent_at,
           s.title, s.slug, s.poster_url, p.starts_at, p.format,
           p.status AS screening_status,
           r.name AS room_name, rs.seat_code, bt.ticket_code, bt.checked_in_at
      FROM bookings b
      JOIN booking_items bi ON bi.booking_id = b.id
      JOIN performances p ON p.id = bi.performance_id
      JOIN shows s ON s.id = p.show_id
      JOIN rooms r ON r.id = p.room_id
      JOIN booking_tickets bt ON bt.booking_item_id = bi.id
      JOIN performance_seats ps ON ps.id = bt.performance_seat_id
      JOIN room_seats rs ON rs.id = ps.room_seat_id
     WHERE b.public_reference = ? AND LOWER(b.customer_email) = ?
     ORDER BY p.starts_at, rs.row_label, rs.seat_number
SQL);
$query->execute([$reference, $email]);
$rows = $query->fetchAll();
if (!$rows) {
    unset($_SESSION['booking_access'][$reference]);
    header('Location: ' . base_url('find-booking.php'), true, 302);
    exit;
}
$booking = $rows[0];
$seats = array_column($rows, 'seat_code');
$cancelled = $booking['booking_status'] === 'cancelled';
$enhancedConfirmation = defined('CINEMA_PHP_ENHANCED') && CINEMA_PHP_ENHANCED;
$emailReady = false;
$emailCurrent = false;
if ($enhancedConfirmation) {
    require_once dirname(__DIR__) . '/php/includes/email.php';
    $reservationEmailConfig = php_email_config();
    $emailReady = $reservationEmailConfig !== null;
    if ($emailReady) {
        $emailCurrent = php_reservation_email_current((int) $booking['id'], $reservationEmailConfig['template_id']);
    }
}
$showSeatTickets = !$cancelled && $booking['screening_status'] === 'scheduled';
$emailFlash = '';
$canResendEmail = false;
if ($enhancedConfirmation) {
    $emailFlash = (string) ($_SESSION['booking_email_flash'][$reference] ?? '');
    unset($_SESSION['booking_email_flash'][$reference]);
    $currentUser = function_exists('php_current_user') ? php_current_user() : null;
    $canResendEmail = $emailReady && !$cancelled && !$emailCurrent
        && is_array($currentUser)
        && strtolower((string) $currentUser['email']) === strtolower((string) $booking['customer_email']);
    if (!$cancelled && !$emailCurrent && $currentUser === null) {
        // The OAuth callback lands at the main site. Keep this already-authorized
        // reservation available for the signed-in return journey.
        $_SESSION['php_email_resume_reference'] = $reference;
    } elseif (($_SESSION['php_email_resume_reference'] ?? null) === $reference) {
        unset($_SESSION['php_email_resume_reference']);
    }
}

page_header('Reservation ' . $reference, 'booking');
?>
<main class="section booking-page booking-confirmation">
  <div class="booking-confirmation-hero">
    <div>
      <p class="eyebrow"><?= $cancelled ? 'Reservation update' : 'Your seats are saved' ?></p>
      <h1><?= $cancelled ? 'Reservation <em>cancelled.</em>' : 'See you at the <em>movies.</em>' ?></h1>
      <p><?= $cancelled
          ? 'This reservation is no longer active. Speak to our cinema team if you need help.'
          : ($enhancedConfirmation
              ? 'Your seats are booked. Show the QR code for each seat at the cinema entrance.'
              : 'Your seats are booked. Keep your booking reference and the ticket code for each seat.') ?></p>
    </div>
    <img src="<?= h(movie_asset($booking['poster_url'])) ?>" alt="<?= h($booking['title']) ?> poster">
  </div>

  <div class="booking-layout">
    <section class="panel booking-panel booking-receipt" aria-labelledby="booking-receipt-title">
      <p class="eyebrow">Your booking</p>
      <h2 id="booking-receipt-title"><?= h($booking['title']) ?></h2>
      <p class="booking-reference-label">Booking reference</p>
      <strong class="booking-reference"><?= h($reference) ?></strong>
      <?php if ($showSeatTickets): foreach ($rows as $seatTicket): ?>
        <div class="ticket-qr-card <?= $enhancedConfirmation ? '' : 'ticket-code-card' ?>">
          <?php if ($enhancedConfirmation): ?>
            <div class="qr-image" data-qr-value="<?= h($seatTicket['ticket_code']) ?>" data-qr-label="<?= h('Ticket QR for seat ' . $seatTicket['seat_code']) ?>"><span>Ticket <?= h($seatTicket['ticket_code']) ?></span></div>
          <?php endif; ?>
          <div><strong>Seat <?= h($seatTicket['seat_code']) ?></strong><p><?= h($seatTicket['ticket_code']) ?></p><p><?= $seatTicket['checked_in_at'] !== null ? 'Already checked in' : ($enhancedConfirmation ? 'Present this QR at entry' : 'Keep this code for your visit') ?></p></div>
        </div>
      <?php endforeach; endif; ?>
      <dl>
        <div><dt>Name</dt><dd><?= h($booking['customer_name']) ?></dd></div>
        <div><dt>Showtime</dt><dd><?= h(booking_datetime($booking['starts_at'])) ?></dd></div>
        <div><dt>Room</dt><dd><?= h($booking['room_name']) ?></dd></div>
        <div><dt>Format</dt><dd><?= h($booking['format']) ?></dd></div>
        <div><dt>Seats</dt><dd><?= h(implode(', ', $seats)) ?></dd></div>
        <div><dt>Total</dt><dd><?= h(booking_money($booking['total_amount'])) ?></dd></div>
      </dl>
      <?php if ($enhancedConfirmation && !$cancelled): ?>
        <?php if ($emailCurrent): ?>
          <p class="notice" role="status">Your ticket email was accepted for delivery. Check the inbox and spam folder for the email address on this booking.</p>
        <?php else: ?>
          <p class="notice" role="status">Your seats are booked. Your ticket email has not been sent yet; the seat QR codes on this page are ready to use.</p>
          <?php if ($canResendEmail): ?>
            <form method="post" action="<?= h(base_url('resend-email.php')) ?>">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="reference" value="<?= h($reference) ?>">
              <button class="text-link" type="submit">Send the ticket email →</button>
            </form>
          <?php elseif ($currentUser === null): ?>
            <p><a class="text-link" href="<?= h(php_signin_url()) ?>">Sign in with the Google account matching this reservation email to return here and retry →</a></p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($emailFlash === 'expired'): ?>
          <p class="notice" role="status">Your session expired before the email was sent. Sign in again, then retry from this page.</p>
        <?php elseif ($emailFlash === 'wait'): ?>
          <p class="notice" role="status">Please wait a minute before trying again.</p>
        <?php elseif ($emailFlash === 'busy'): ?>
          <p class="notice" role="status">An email attempt is already in progress. Refresh this page shortly.</p>
        <?php elseif ($emailFlash === 'unavailable'): ?>
          <p class="notice" role="status">A ticket email template is not configured. Your seat QR codes are still available above.</p>
        <?php elseif ($emailFlash === 'failed'): ?>
          <p class="notice" role="status">We could not send the email right now. Your seat QR codes are still available above.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <aside class="panel booking-panel booking-next-steps">
      <p class="eyebrow">Before the opening scene</p>
      <h2>Make yourself at home.</h2>
      <p>Arrive about 20 minutes before showtime with <?= $enhancedConfirmation ? 'the QR code for each seat' : 'your booking reference and each seat code' ?>. Our team can help with seating or accessibility questions at the desk.</p>
      <a class="primary" href="<?= h(base_url('movies.php')) ?>">Browse more movies →</a>
      <a class="text-link" href="<?= h(base_url('find-booking.php')) ?>">Find a reservation later →</a>
    </aside>
  </div>
</main>
<?php if ($showSeatTickets && $enhancedConfirmation): ?>
<script defer src="<?= h(base_url('qr-assets/qr-renderer.js')) ?>"></script>
<?php endif; ?>
<?php page_footer(); ?>
