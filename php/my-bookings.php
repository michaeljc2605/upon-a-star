<?php
declare(strict_types=1);

require_once __DIR__ . '/_start.php';
require_once dirname(__DIR__) . '/base/includes/layout.php';

header('Cache-Control: private, no-store');
$user = php_current_user();
$baseBookings = [];
$itemsByBooking = [];
$baseError = false;
$totalBaseBookings = 0;
$page = 1;
$pageCount = 1;

if (is_array($user)) {
    $email = is_string($user['email'] ?? null) ? strtolower(trim($user['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $countQuery = db()->prepare('SELECT COUNT(*) FROM bookings WHERE LOWER(customer_email) = ?');
            $countQuery->execute([$email]);
            $totalBaseBookings = (int) $countQuery->fetchColumn();
            $pageCount = max(1, (int) ceil($totalBaseBookings / 20));
            $requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $page = min($requestedPage ?: 1, $pageCount);

            $bookingQuery = db()->prepare(<<<'SQL'
                SELECT id, public_reference, customer_name, total_amount,
                       booking_status, created_at
                  FROM bookings
                 WHERE LOWER(customer_email) = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ? OFFSET ?
            SQL);
            $bookingQuery->bindValue(1, $email, PDO::PARAM_STR);
            $bookingQuery->bindValue(2, 20, PDO::PARAM_INT);
            $bookingQuery->bindValue(3, ($page - 1) * 20, PDO::PARAM_INT);
            $bookingQuery->execute();
            $baseBookings = $bookingQuery->fetchAll();

            // A verified account may reopen its own reservation receipt. The
            // receipt still checks this session grant and the saved email.
            if (!isset($_SESSION['booking_access']) || !is_array($_SESSION['booking_access'])) {
                $_SESSION['booking_access'] = [];
            }
            foreach ($baseBookings as $savedBooking) {
                $savedReference = $savedBooking['public_reference'] ?? null;
                if (is_string($savedReference) && booking_reference_is_valid($savedReference)) {
                    $_SESSION['booking_access'][$savedReference] = $email;
                }
            }

            if ($baseBookings) {
                $ids = array_map(static fn(array $booking): int => (int) $booking['id'], $baseBookings);
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $itemQuery = db()->prepare("SELECT bi.booking_id, bi.id AS item_id, s.title, s.poster_url,
                    p.starts_at, p.format, r.name AS room_name, rs.seat_code,
                    bt.ticket_code, bt.checked_in_at,
                    IF(s.status = 'published' AND p.status = 'scheduled'
                        AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
                        AND CURRENT_TIMESTAMP < TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at), 1, 0) AS ticket_available
                    FROM booking_items bi
                    JOIN performances p ON p.id = bi.performance_id
                    JOIN shows s ON s.id = p.show_id
                    JOIN rooms r ON r.id = p.room_id
                    JOIN booking_tickets bt ON bt.booking_item_id = bi.id
                    JOIN performance_seats ps ON ps.id = bt.performance_seat_id
                    JOIN room_seats rs ON rs.id = ps.room_seat_id
                    WHERE bi.booking_id IN ({$placeholders})
                    ORDER BY p.starts_at, rs.row_label, rs.seat_number");
                $itemQuery->execute($ids);
                foreach ($itemQuery->fetchAll() as $row) {
                    $bookingId = (int) $row['booking_id'];
                    $itemId = (int) $row['item_id'];
                    if (!isset($itemsByBooking[$bookingId][$itemId])) {
                        $itemsByBooking[$bookingId][$itemId] = [
                            'title' => $row['title'],
                            'poster_url' => $row['poster_url'],
                            'starts_at' => $row['starts_at'],
                            'format' => $row['format'],
                            'room_name' => $row['room_name'],
                            'seats' => [],
                            'tickets' => [],
                            'ticket_available' => (bool) $row['ticket_available'],
                        ];
                    }
                    $itemsByBooking[$bookingId][$itemId]['seats'][] = $row['seat_code'];
                    $itemsByBooking[$bookingId][$itemId]['tickets'][] = [
                        'seat' => $row['seat_code'],
                        'code' => $row['ticket_code'],
                        'checked_in_at' => $row['checked_in_at'],
                    ];
                }
            }
        } catch (PDOException $exception) {
            error_log('Enhanced PHP booking history error: ' . $exception->getMessage());
            $baseError = true;
        }
    } else {
        $baseError = true;
    }

}

page_header('My Bookings', 'booking');
?>
<main class="section booking-page booking-history">
  <div class="heading">
    <div>
      <p class="eyebrow">Your cinema nights</p>
      <h1>My <em>bookings.</em></h1>
      <span>See your showtimes, booked seats, and entry QR codes in one place.</span>
    </div>
  </div>

  <?php if (!is_array($user)): ?>
    <div class="booking-layout">
      <section class="panel booking-panel">
        <p class="eyebrow">Welcome back</p>
        <h2>Sign in to see your bookings.</h2>
        <p>Use the account connected to your reservation. You can still find a guest booking with its reference and email address.</p>
        <a class="primary" href="<?= h(php_signin_url()) ?>">Continue with Google →</a>
        <p><a class="text-link" href="<?= h(base_url('find-booking.php')) ?>">Find a booking by reference →</a></p>
      </section>
      <aside class="panel booking-panel booking-lookup-art">
        <img src="<?= h(base_url('assets/movies/up/backdrop.webp')) ?>" alt="Adventure film artwork from Up">
        <h2>Every story has its seat.</h2>
        <p>Keep your reference handy and arrive a little early to enjoy the whole cinema experience.</p>
      </aside>
    </div>
  <?php else: ?>
    <section aria-labelledby="booking-history-title">
      <p class="eyebrow">Your tickets</p>
      <h2 id="booking-history-title">Bookings and tickets</h2>
      <p>Everything linked to your verified account email is here, including one QR code for each booked seat.</p>
      <?php if ($baseError): ?>
        <p class="notice booking-alert booking-error" role="alert">We could not load these reservations right now. Please try again later.</p>
      <?php elseif (!$baseBookings): ?>
        <div class="panel booking-panel booking-lookup-art">
          <img src="<?= h(base_url('assets/movies/up/backdrop.webp')) ?>" alt="Adventure film artwork from Up">
          <p>No bookings are linked to this email yet.</p>
          <a class="text-link" href="<?= h(base_url('showtimes.php')) ?>">Explore showtimes →</a>
        </div>
      <?php else: ?>
        <div class="ticket-results">
          <?php foreach ($baseBookings as $booking): ?>
            <article class="panel booking-panel booking-history-card">
              <p class="eyebrow">Booking <?= h($booking['public_reference']) ?></p>
              <h3><?= h($booking['customer_name']) ?></h3>
              <?php foreach ($itemsByBooking[(int) $booking['id']] ?? [] as $item): ?>
                <div class="booking-history-item">
                  <img src="<?= h(movie_asset($item['poster_url'], 'poster')) ?>" alt="<?= h($item['title']) ?> poster" loading="lazy">
                  <div>
                    <h4><?= h($item['title']) ?></h4>
                    <p><?= h(booking_datetime($item['starts_at'])) ?> · <?= h($item['room_name']) ?> · <?= h($item['format']) ?></p>
                    <p><strong>Seats:</strong> <?= h(implode(', ', $item['seats'])) ?></p>
                  </div>
                </div>
                <?php if ($booking['booking_status'] === 'confirmed' && $item['ticket_available']): ?>
                  <?php foreach ($item['tickets'] as $ticket): ?>
                    <div class="ticket-qr-card">
                      <div class="qr-image" data-qr-value="<?= h($ticket['code']) ?>" data-qr-label="<?= h('Ticket QR code for seat ' . $ticket['seat']) ?>">
                        <span>Ticket code <?= h($ticket['code']) ?></span>
                      </div>
                      <div><strong>Seat <?= h($ticket['seat']) ?></strong><p><?= h($ticket['code']) ?></p><p><?= $ticket['checked_in_at'] !== null ? 'Already checked in' : 'Present this QR at entry' ?></p></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endforeach; ?>
              <p class="booking-history-total"><strong>Total:</strong> <?= h(booking_money($booking['total_amount'])) ?> · <strong>Status:</strong> <?= h(ucfirst($booking['booking_status'])) ?></p>
              <a class="text-link" href="<?= h(base_url('confirmation.php?reference=' . rawurlencode($booking['public_reference']))) ?>">Open booking and seat QR codes →</a>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($pageCount > 1): ?>
          <nav class="booking-pagination" aria-label="Reservation pages">
            <?php if ($page > 1): ?><a href="<?= h(base_url('my-bookings.php?page=' . ($page - 1))) ?>">← Newer</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?><a href="<?= h(base_url('my-bookings.php?page=' . ($page + 1))) ?>">Older →</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>

  <?php endif; ?>
</main>
<script defer src="<?= h(base_url('qr-assets/qr-renderer.js')) ?>"></script>
<?php page_footer(); ?>
