<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$pdo = db();
$list = $pdo->query(<<<'SQL'
    SELECT p.id, p.show_id, p.starts_at, p.unit_price, p.format, p.language,
           s.title, s.slug, s.poster_url, s.synopsis, s.genre, s.age_rating,
           r.name AS room_name, r.room_type,
           (SELECT COUNT(*)
              FROM performance_seats ps
              LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
              LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
             WHERE ps.performance_id = p.id AND bt.id IS NULL AND sh.id IS NULL) AS seats_available
      FROM performances p
      JOIN shows s ON s.id = p.show_id
      JOIN rooms r ON r.id = p.room_id
     WHERE p.status = 'scheduled' AND p.history_deleted_at IS NULL
       AND p.starts_at > CURRENT_TIMESTAMP AND s.status = 'published'
       AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
       AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
       AND (s.run_end_date IS NULL OR CURRENT_DATE <= s.run_end_date)
       AND (s.sales_open_date IS NULL OR CURRENT_DATE >= s.sales_open_date)
     ORDER BY p.starts_at, s.title
SQL)->fetchAll();

$requestedId = filter_input(INPUT_GET, 'performance_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$defaultId = (int) ($list[0]['id'] ?? 0);
foreach ($list as $screening) {
    if ((int) $screening['seats_available'] > 0) {
        $defaultId = (int) $screening['id'];
        break;
    }
}
$selected = null;
foreach ($list as $screening) {
    if ((int) $screening['id'] === ($requestedId ?: $defaultId)) {
        $selected = $screening;
        break;
    }
}
$screeningsForMovie = [];
if ($selected) {
    foreach ($list as $screening) {
        if ((int) $screening['show_id'] === (int) $selected['show_id']) {
            $screeningsForMovie[] = $screening;
        }
    }
}
$flash = take_booking_flash();
$old = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$signedInUser = function_exists('php_current_user') ? php_current_user() : null;
$verifiedName = is_array($signedInUser) && is_string($signedInUser['displayName'] ?? null)
    ? trim($signedInUser['displayName']) : '';
$verifiedEmail = is_array($signedInUser) && is_string($signedInUser['email'] ?? null)
    ? strtolower(trim($signedInUser['email'])) : '';
$customerName = $old['customer_name'] ?? $verifiedName;
$customerEmail = is_array($signedInUser) ? $verifiedEmail : ($old['customer_email'] ?? '');
$selectedOldSeats = $selected && (int) ($old['performance_id'] ?? 0) === (int) $selected['id']
    ? array_map('intval', (array) ($old['seat_ids'] ?? [])) : [];
$seatsByRow = [];
$availableIds = [];
if ($selected) {
    $seatQuery = $pdo->prepare(<<<'SQL'
        SELECT ps.id, rs.seat_code, rs.row_label, rs.seat_number, rs.block_number,
               IF(bt.id IS NULL AND sh.id IS NULL, 1, 0) AS available
          FROM performance_seats ps
          JOIN room_seats rs ON rs.id = ps.room_seat_id
          LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
          LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
         WHERE ps.performance_id = ?
         ORDER BY rs.row_label, rs.block_number, rs.seat_number
    SQL);
    $seatQuery->execute([(int) $selected['id']]);
    foreach ($seatQuery->fetchAll() as $seat) {
        $seatsByRow[$seat['row_label']][] = $seat;
        if ((int) $seat['available']) {
            $availableIds[] = (int) $seat['id'];
        }
    }
    $selectedOldSeats = array_values(array_intersect($selectedOldSeats, $availableIds));
}

page_header('Book Tickets', 'showtimes');
$enhancedBooking = defined('CINEMA_PHP_ENHANCED') && CINEMA_PHP_ENHANCED;
?>
<main class="section booking-page">
  <div class="heading">
    <div>
      <p class="eyebrow">The next chapter starts here</p>
      <h1>Choose your <em>seats.</em></h1>
      <span>Pick a screening and choose your seats. Each booked seat gets its own <?= $enhancedBooking ? 'entry QR code' : 'ticket code' ?>.</span>
    </div>
  </div>

  <?php if ($flash['message']): ?>
    <p class="notice booking-alert booking-error" role="alert"><?= h($flash['message']) ?></p>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="panel booking-panel">
      <h2>New stories are on their way.</h2>
      <p>No screenings are currently open for booking. Explore the programme and check back for newly released seats.</p>
      <a class="primary" href="<?= h(base_url('movies.php')) ?>">Explore movies →</a>
    </div>
  <?php elseif (!$selected): ?>
    <div class="panel booking-panel">
      <h2>That screening is unavailable.</h2>
      <p>It may have started, sold out, or passed its booking window. Choose another showtime to continue.</p>
      <a class="primary" href="<?= h(base_url('book.php')) ?>">Choose a screening →</a>
    </div>
  <?php else: ?>
    <div class="booking-layout">
      <div class="booking-main">
        <div class="panel booking-panel" id="screening-picker">
          <p class="eyebrow">01 / Your screening</p>
          <form method="get" action="<?= h(base_url('book.php#screening-picker')) ?>" class="booking-screening-picker">
            <label for="performance_id">Showtime for <?= h($selected['title']) ?></label>
            <div class="booking-picker-controls">
              <select id="performance_id" name="performance_id" required>
                <?php foreach ($screeningsForMovie as $screening): ?>
                  <option value="<?= (int) $screening['id'] ?>" <?= (int) $screening['id'] === (int) $selected['id'] ? 'selected' : '' ?> <?= (int) $screening['seats_available'] === 0 ? 'disabled' : '' ?>>
                    <?= h(booking_datetime($screening['starts_at']) . ' · ' . $screening['room_name'] . ' · ' . $screening['seats_available'] . ' seats left') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="button-secondary">Show seats</button>
            </div>
          </form>
        </div>

        <div class="panel booking-panel">
          <p class="eyebrow">02 / Your places</p>
          <h2>Pick your seats</h2>
          <p id="seat-help">The screen is at the front. Choose up to 12 available seats; darker seats are already reserved. On a phone, scroll the seat map sideways to see every seat.</p>
          <div class="booking-screen" aria-hidden="true">Screen</div>

          <form id="booking-form" class="booking-form" method="post" action="<?= h(base_url('process-booking.php')) ?>" data-booking-form>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="performance_id" value="<?= (int) $selected['id'] ?>">
            <fieldset class="booking-seat-map" aria-describedby="seat-help seat-error">
              <legend class="visually-hidden">Available seats for <?= h($selected['title']) ?></legend>
              <?php foreach ($seatsByRow as $row => $seats): ?>
                <div class="booking-seat-row">
                  <strong><?= h($row) ?></strong>
                  <div class="booking-seat-strip">
                    <?php $previousBlock = null; foreach ($seats as $seat): ?>
                      <label class="booking-seat <?= $previousBlock !== null && $previousBlock !== $seat['block_number'] ? 'block-start' : '' ?>">
                        <input type="checkbox" name="seat_ids[]" value="<?= (int) $seat['id'] ?>"
                          aria-label="Seat <?= h($seat['seat_code']) ?><?= (int) $seat['available'] ? '' : ', unavailable' ?>"
                          <?= (int) $seat['available'] ? '' : 'disabled' ?>
                          <?= (int) $seat['available'] && in_array((int) $seat['id'], $selectedOldSeats, true) ? 'checked' : '' ?>>
                        <span><?= (int) $seat['seat_number'] ?></span>
                      </label>
                    <?php $previousBlock = $seat['block_number']; endforeach; ?>
                  </div>
                  <strong><?= h($row) ?></strong>
                </div>
              <?php endforeach; ?>
            </fieldset>
            <p id="seat-error" class="booking-error" role="alert" hidden></p>
            <div class="booking-seat-key" aria-hidden="true"><span>Available</span><span>Selected</span><span>Reserved</span></div>

            <div class="booking-customer-fields">
              <p class="eyebrow">03 / Your details</p>
              <h2>Who is joining us?</h2>
              <p>We will hold this reservation under your name. Keep your reference and <?= $enhancedBooking ? 'seat QR codes' : 'seat codes' ?> for your visit. Payment takes place outside this site.</p>
              <?php if (is_array($signedInUser)): ?>
                <p class="notice">Your signed-in email is used for this reservation so it appears in your booking history.</p>
              <?php endif; ?>
              <label for="customer_name">Full name</label>
              <input id="customer_name" name="customer_name" type="text" autocomplete="name" minlength="2" maxlength="160" required value="<?= h($customerName) ?>">
              <label for="customer_email">Email address</label>
              <input id="customer_email" name="customer_email" type="email" autocomplete="email" maxlength="254" required value="<?= h($customerEmail) ?>" <?= is_array($signedInUser) ? 'readonly aria-readonly="true"' : '' ?>>
              <label for="customer_phone">Phone number</label>
              <input id="customer_phone" name="customer_phone" type="tel" autocomplete="tel" minlength="6" maxlength="40" pattern="[+0-9(). -]{6,40}" title="Use 6 to 40 characters: digits, spaces, +, brackets, dots or dashes" required value="<?= h($old['customer_phone'] ?? '') ?>">
            </div>

            <div class="booking-summary">
              <p><span>Price per seat</span><strong><?= h(booking_money($selected['unit_price'])) ?></strong></p>
              <p><span>Seats selected</span><strong data-seat-count><?= count($selectedOldSeats) ?></strong></p>
              <p class="booking-total"><span>Reservation total</span><strong data-booking-total data-unit-cents="<?= booking_price_cents((string) $selected['unit_price']) ?>"><?= h(booking_money((float) $selected['unit_price'] * count($selectedOldSeats))) ?></strong></p>
              <small>This site does not process or verify payments. Each seat receives <?= $enhancedBooking ? 'an entry QR code' : 'a ticket code' ?> when the booking is saved.</small>
            </div>
            <button class="primary booking-submit" type="submit" <?= (int) $selected['seats_available'] === 0 ? 'disabled' : '' ?>>Reserve selected seats →</button>
          </form>
        </div>
      </div>

      <aside class="panel booking-panel booking-film">
        <img class="booking-poster" src="<?= h(movie_asset($selected['poster_url'])) ?>" alt="<?= h($selected['title']) ?> poster">
        <p class="eyebrow">Your cinema night</p>
        <h2><?= h($selected['title']) ?></h2>
        <p><?= h($selected['synopsis']) ?></p>
        <dl>
          <div><dt>Showtime</dt><dd><?= h(booking_datetime($selected['starts_at'])) ?></dd></div>
          <div><dt>Room</dt><dd><?= h($selected['room_name']) ?></dd></div>
          <div><dt>Experience</dt><dd><?= h($selected['format'] . ' · ' . $selected['language']) ?></dd></div>
          <div><dt>Rating</dt><dd><?= h($selected['age_rating']) ?></dd></div>
        </dl>
        <a href="<?= h(base_url('movie.php?slug=' . rawurlencode($selected['slug']))) ?>">More about this movie →</a>
      </aside>
    </div>
  <?php endif; ?>
</main>
<script src="<?= h(base_url('assets/booking-validation.js?v=' . filemtime(__DIR__ . '/assets/booking-validation.js'))) ?>" defer></script>
<?php page_footer(); ?>
