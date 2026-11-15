<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$slug = filter_input(INPUT_GET, 'slug', FILTER_UNSAFE_RAW);
$slug = is_string($slug) && preg_match('/^[a-z0-9-]{1,200}$/', $slug) ? $slug : '';
$movie = null;
if ($slug !== '') {
    foreach (cinema_public_movies() as $candidate) {
        if ($candidate['slug'] === $slug) {
            $movie = $candidate;
            break;
        }
    }
    if ($movie === null) {
        foreach (cinema_archived_movies() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $movie = $candidate;
                break;
            }
        }
    }
}
if ($movie === null) {
    http_response_code(404);
    page_header('Movie not found', 'movies');
    ?>
    <main>
    <section class="section empty-state"><h1>This movie is not available.</h1><p>It may have left the current programme. Explore the published films and their latest showtimes.</p><a class="primary" href="<?= h(base_url('movies.php')) ?>">Browse movies →</a></section>
    </main>
    <?php
    page_footer();
    exit;
}
$archived = ($movie['status'] ?? '') === 'archived';
if ($archived) {
    header('Cache-Control: private, no-store');
}
$screenings = $archived ? [] : cinema_upcoming_screenings((int) $movie['id']);
$byDate = [];
foreach ($screenings as $screening) {
    $byDate[substr((string) $screening['starts_at'], 0, 10)][] = $screening;
}

page_header((string) $movie['title'], 'movies');
?>
<main>
<section class="movie-detail" aria-labelledby="movie-heading">
  <a class="text-link" href="<?= h(base_url('movies.php')) ?>">← All movies</a>
  <div class="detail-grid">
    <img src="<?= h(movie_asset((string) $movie['poster_url'], 'poster')) ?>" alt="<?= h($movie['title']) ?> film poster">
    <div>
      <p class="eyebrow"><?= h($movie['genre']) ?> · <?= h($movie['age_rating']) ?></p>
      <h1 id="movie-heading"><?= cinema_title((string) $movie['title']) ?></h1>
      <?php cinema_movie_badge($movie); ?>
      <span class="movie-synopsis"><?= h($movie['synopsis']) ?></span>
      <small class="movie-duration"><?= (int) $movie['duration_minutes'] ?> minutes</small>
      <?php if ($archived): ?>
        <h2>Previously shown</h2>
        <p class="screening-help">This film is part of our earlier programme. It has no bookable screenings at the moment.</p>
      <?php else: ?>
      <h2>Choose a screening</h2>
      <?php if (!$screenings): ?>
        <p class="screening-help">This film has no upcoming screenings yet. The full cinema schedule shows times and available seats for other films.</p>
      <?php else: ?>
        <p class="screening-help">Each time shows its cinema room, available seats, and price. Choose a screening to select your seats.</p>
        <?php foreach ($byDate as $date => $dayScreenings): ?>
          <div class="screening-day">
            <h3><?= h(cinema_date($date, 'l, d F Y')) ?></h3>
            <div class="screening-list">
              <?php foreach ($dayScreenings as $screening): ?>
                <?php if ((int) $screening['booking_open'] === 1): ?>
                  <a class="showtime" href="<?= h(base_url('book.php?performance_id=' . (int) $screening['id'])) ?>"><b><?= h(cinema_date((string) $screening['starts_at'], 'H:i')) ?> · <?= h($screening['format']) ?></b><span><?= h($screening['room_name']) ?> · <?= (int) $screening['seats_available'] ?> seats left · <?= h(cinema_money($screening['unit_price'])) ?></span><strong>Choose seats →</strong></a>
                <?php else: ?>
                  <div class="showtime disabled"><b><?= h(cinema_date((string) $screening['starts_at'], 'H:i')) ?> · <?= h($screening['format']) ?></b><span><?= h($screening['room_name']) ?> · <?= h(cinema_money($screening['unit_price'])) ?></span><small><?= (int) $screening['seats_available'] < 1 ? 'Sold out' : 'Ticket sales open ' . h(cinema_date((string) $movie['sales_open_date'], 'd M Y')) ?></small></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php endif; ?>
      <p><a class="text-link" href="<?= h(base_url('showtimes.php')) ?>">View the full cinema schedule →</a></p>
    </div>
  </div>
</section>
</main>
<?php page_footer(); ?>
