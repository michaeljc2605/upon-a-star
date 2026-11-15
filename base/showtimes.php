<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$publicMovies = cinema_public_movies(true);
$requestedMovie = is_string($_GET['movie'] ?? null) ? trim($_GET['movie']) : '';
$selectedMovie = null;
foreach ($publicMovies as $movie) {
    if ($requestedMovie !== '' && $movie['slug'] === $requestedMovie) {
        $selectedMovie = $movie;
        break;
    }
}
$movieId = $selectedMovie === null ? null : (int) $selectedMovie['id'];
$movieSlug = $selectedMovie === null ? null : (string) $selectedMovie['slug'];
$scheduleDays = cinema_schedule_days($movieId);
$availableDates = array_column($scheduleDays, 'screening_date');
$monthFirstDates = [];
foreach ($availableDates as $date) {
    $month = substr((string) $date, 0, 7);
    if (!isset($monthFirstDates[$month])) {
        $monthFirstDates[$month] = (string) $date;
    }
}
$totalScreenings = array_sum(array_map(static fn (array $row): int => (int) $row['screening_count'], $scheduleDays));
$viewAll = ($_GET['view'] ?? null) === 'all';
$requestedDate = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
$selectedDate = $viewAll || !$availableDates ? null : (
    in_array($requestedDate, $availableDates, true) ? $requestedDate : (string) $availableDates[0]
);
$selectedDateIndex = $selectedDate === null ? false : array_search($selectedDate, $availableDates, true);
$previousDate = is_int($selectedDateIndex) && $selectedDateIndex > 0 ? (string) $availableDates[$selectedDateIndex - 1] : null;
$nextDate = is_int($selectedDateIndex) && $selectedDateIndex < count($availableDates) - 1 ? (string) $availableDates[$selectedDateIndex + 1] : null;
$dateNotice = !$viewAll && $requestedDate !== '' && !in_array($requestedDate, $availableDates, true)
    ? 'That date has no upcoming screenings. Showing the earliest available date instead.'
    : '';
$screenings = $scheduleDays ? cinema_upcoming_screenings($movieId, $selectedDate) : [];
$selectedDateLabel = $selectedDate === null ? '' : cinema_date($selectedDate, 'l, d F Y');
$timeSlots = [];
if ($selectedDate !== null) {
    foreach ($screenings as $screening) {
        $timeSlots[cinema_date((string) $screening['starts_at'], 'H:i')][] = $screening;
    }
}
$scheduleUrl = static function (?string $date, ?string $movie, bool $all = false): string {
    $query = [];
    if ($movie !== null) {
        $query['movie'] = $movie;
    }
    if ($all) {
        $query['view'] = 'all';
    } elseif ($date !== null) {
        $query['date'] = $date;
    }
    return base_url('showtimes.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '')) . '#schedule-calendar';
};

page_header('Showtimes', 'showtimes');
?>
<main>
<section class="section showtimes-page" aria-labelledby="showtimes-heading">
  <div class="heading"><div><p>Your next cinema night</p><h1 id="showtimes-heading">Movie <em>showtimes</em></h1></div><span>Explore the calendar by date, then compare films, rooms, seats, and prices. Seats are assigned when you book.</span></div>
  <?php if ($scheduleDays): ?>
    <div class="schedule-summary"><strong><?= $totalScreenings ?> upcoming <?= $totalScreenings === 1 ? 'screening' : 'screenings' ?></strong><span>All times shown in the cinema’s local time. Availability may change while you browse.</span></div>

    <div class="schedule-calendar" id="schedule-calendar">
      <div class="schedule-calendar-heading">
        <div><p class="eyebrow">Pick your movie night</p><h2><?= $viewAll ? 'The full <em>programme.</em>' : h($selectedDateLabel) ?></h2><span><?= $viewAll ? 'Every upcoming screening, in time order.' : count($screenings) . ' ' . (count($screenings) === 1 ? 'screening' : 'screenings') . ' on this date · ' . $totalScreenings . ' across the full programme' ?></span></div>
        <form class="schedule-film-filter" method="get" action="<?= h(base_url('showtimes.php')) ?>#schedule-calendar">
          <label for="schedule-film">Film</label>
          <select id="schedule-film" name="movie">
            <option value="">All films</option>
            <?php foreach ($publicMovies as $movie): ?>
              <option value="<?= h($movie['slug']) ?>"<?= $movieSlug === $movie['slug'] ? ' selected' : '' ?>><?= h($movie['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Show dates</button>
        </form>
      </div>
      <?php if (count($monthFirstDates) > 1): ?>
        <nav class="schedule-month-jumps" aria-label="Jump to a screening month">
          <span>Jump to</span>
          <?php foreach ($monthFirstDates as $month => $firstDate): ?>
            <a class="<?= $selectedDate !== null && substr($selectedDate, 0, 7) === $month ? 'is-current' : '' ?>" href="<?= h($scheduleUrl($firstDate, $movieSlug)) ?>"><?= h(cinema_date($firstDate, 'F Y')) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <div class="schedule-date-controls">
        <nav class="schedule-date-rail" aria-label="Available screening dates" data-schedule-dates>
          <?php $lastMonth = ''; foreach ($scheduleDays as $day): $date = (string) $day['screening_date']; $month = cinema_date($date, 'F Y'); ?>
            <?php if ($month !== $lastMonth): ?><span class="schedule-month-break"><?= h($month) ?></span><?php $lastMonth = $month; endif; ?>
            <a class="schedule-date-choice<?= $date === $selectedDate ? ' is-current' : '' ?>" href="<?= h($scheduleUrl($date, $movieSlug)) ?>"<?= $date === $selectedDate ? ' aria-current="date"' : '' ?>>
              <small><?= h(cinema_date($date, 'D')) ?></small><strong><?= h(cinema_date($date, 'd')) ?></strong><span><?= h(cinema_date($date, 'M')) ?></span><em><?= (int) $day['screening_count'] ?> <?= (int) $day['screening_count'] === 1 ? 'show' : 'shows' ?></em>
            </a>
          <?php endforeach; ?>
        </nav>
        <a class="schedule-all-dates<?= $viewAll ? ' is-current' : '' ?>" href="<?= h($scheduleUrl(null, $movieSlug, true)) ?>"<?= $viewAll ? ' aria-current="page"' : '' ?>>All upcoming <span aria-hidden="true">↗</span></a>
      </div>
      <?php if ($selectedDate !== null): ?>
        <div class="schedule-date-steps">
          <?php if ($previousDate !== null): ?><a href="<?= h($scheduleUrl($previousDate, $movieSlug)) ?>">← Previous screening day</a><?php endif; ?>
          <?php if ($nextDate !== null): ?><a href="<?= h($scheduleUrl($nextDate, $movieSlug)) ?>">Next screening day →</a><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($dateNotice !== ''): ?><p class="schedule-date-notice" role="status"><?= h($dateNotice) ?></p><?php endif; ?>
    </div>

    <?php if ($selectedDate !== null): ?>
      <section class="schedule-timeline" aria-labelledby="schedule-timeline-heading">
        <div class="schedule-section-heading"><div><p class="eyebrow">Your day at a glance</p><h2 id="schedule-timeline-heading">By <em>showtime.</em></h2></div><span>Films at the same time use different rooms.</span></div>
        <div class="schedule-timeline-rows">
          <?php foreach ($timeSlots as $time => $slot): ?>
            <div class="schedule-timeline-row">
              <div class="schedule-timeline-time"><time datetime="<?= h($selectedDate . 'T' . $time) ?>"><?= h($time) ?></time></div>
              <div class="schedule-timeline-events">
                <?php foreach ($slot as $screening): ?>
                  <article class="schedule-timeline-card">
                    <a class="schedule-timeline-film" href="<?= h(base_url('movie.php?slug=' . rawurlencode((string) $screening['slug']))) ?>"><img src="<?= h(movie_asset((string) $screening['poster_url'], 'poster')) ?>" alt="" loading="lazy"><span><?= h($screening['title']) ?></span></a>
                    <p>Until <?= h(cinema_date((string) $screening['ends_at'], 'H:i')) ?> · <?= h($screening['room_name']) ?> · <?= h($screening['format']) ?> · <?= h(cinema_money($screening['unit_price'])) ?></p>
                    <small><?= (int) $screening['seats_available'] ?> seats available</small>
                    <?php if ((int) $screening['booking_open'] === 1): ?>
                      <a class="schedule-timeline-book" href="<?= h(base_url('book.php?performance_id=' . (int) $screening['id'])) ?>">Choose seats <span aria-hidden="true">→</span></a>
                    <?php else: ?>
                      <span class="table-unavailable"><?= (int) $screening['seats_available'] < 1 ? 'Sold out' : 'Sales open soon' ?></span>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="schedule-details" aria-labelledby="schedule-details-heading">
      <div class="schedule-section-heading"><div><p class="eyebrow">Every detail</p><h2 id="schedule-details-heading">Compare <em>screenings.</em></h2></div><span><?= $viewAll ? 'All dates' : h($selectedDateLabel) ?> · <?= count($screenings) ?> <?= count($screenings) === 1 ? 'screening' : 'screenings' ?></span></div>
      <details class="schedule-comparison" open>
        <summary>Show the detailed screening table</summary>
      <div class="schedule-table-wrap">
        <table class="schedule-table">
          <caption><?= $viewAll ? 'All upcoming Upon a Star screenings' : 'Upon a Star screenings on ' . h($selectedDateLabel) ?></caption>
          <thead><tr><th scope="col">Movie</th><th scope="col">Date &amp; time</th><th scope="col">Cinema room</th><th scope="col">Format &amp; seats</th><th scope="col">Ticket price</th><th scope="col">Tickets</th></tr></thead>
          <tbody>
            <?php foreach ($screenings as $screening): ?>
              <tr>
                <th scope="row"><a class="schedule-movie" href="<?= h(base_url('movie.php?slug=' . rawurlencode((string) $screening['slug']))) ?>"><img src="<?= h(movie_asset((string) $screening['poster_url'], 'poster')) ?>" alt="<?= h($screening['title']) ?> poster" loading="lazy"><span><?= h($screening['title']) ?></span></a></th>
                <td data-label="Date &amp; time"><strong><?= h(cinema_date((string) $screening['starts_at'], 'D, d M Y')) ?></strong><small><?= h(cinema_date((string) $screening['starts_at'], 'H:i')) ?>–<?= h(cinema_date((string) $screening['ends_at'], 'H:i')) ?></small></td>
                <td data-label="Cinema room"><?= h($screening['room_name']) ?><small><?= h(ucfirst((string) $screening['room_type'])) ?> room</small></td>
                <td data-label="Format &amp; seats"><?= h($screening['format']) ?><small><?= (int) $screening['seats_available'] ?> seats available</small></td>
                <td data-label="Ticket price"><?= h(cinema_money($screening['unit_price'])) ?></td>
                <td data-label="Tickets">
                  <?php if ((int) $screening['booking_open'] === 1): ?>
                    <a class="table-book" href="<?= h(base_url('book.php?performance_id=' . (int) $screening['id'])) ?>">Choose seats →</a>
                  <?php else: ?>
                    <span class="table-unavailable"><?= (int) $screening['seats_available'] < 1 ? 'Sold out' : 'Sales open soon' ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </details>
    </section>
  <?php else: ?>
    <div class="empty-state"><h2><?= $selectedMovie === null ? 'New times are coming.' : 'No times for this film yet.' ?></h2><p><?= $selectedMovie === null ? 'The programme is being scheduled. Explore the current film selection and return soon to reserve a seat.' : 'Try all films to see the available dates, or come back when this film receives a new screening.' ?></p><a class="text-link" href="<?= h(base_url($selectedMovie === null ? 'movies.php' : 'showtimes.php')) ?>"><?= $selectedMovie === null ? 'Browse movies' : 'Show all films' ?> →</a></div>
  <?php endif; ?>
</section>
</main>
<script defer src="<?= h(base_url('assets/schedule.js?v=' . filemtime(__DIR__ . '/assets/schedule.js'))) ?>"></script>
<?php page_footer(); ?>
