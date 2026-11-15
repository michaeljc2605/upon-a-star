<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$movies = cinema_public_movies(true);
$archivedMovies = cinema_archived_movies();
if ($archivedMovies) {
    header('Cache-Control: private, no-store');
}
$screenings = cinema_upcoming_screenings();
$firstScreening = [];
foreach ($screenings as $screening) {
    $firstScreening[(int) $screening['show_id']] ??= $screening;
}
usort($movies, static function (array $left, array $right) use ($firstScreening): int {
    $leftStart = $firstScreening[(int) $left['id']]['starts_at'] ?? null;
    $rightStart = $firstScreening[(int) $right['id']]['starts_at'] ?? null;
    if ($leftStart === null || $rightStart === null) {
        if ($leftStart === null && $rightStart !== null) return 1;
        if ($rightStart === null && $leftStart !== null) return -1;
    }
    return strcmp((string) $leftStart, (string) $rightStart)
        ?: strcasecmp((string) $left['title'], (string) $right['title'])
        ?: ((int) $left['id'] <=> (int) $right['id']);
});

page_header('Movies', 'movies');
?>
<main>
<section class="section catalogue-page" aria-labelledby="catalogue-heading">
  <div class="heading"><div><p>The complete collection</p><h1 id="catalogue-heading">All <em>movies</em></h1></div><span>Find the next film to screen and explore its showtimes.<?= $archivedMovies ? ' Owners can also review the private archive.' : '' ?></span></div>
  <?php if ($archivedMovies): ?><nav class="catalogue-jump-links" aria-label="Movie sections"><a href="#screening-soon">Screening soon (<?= count($movies) ?>)</a><a href="#previously-shown">Owner archive (<?= count($archivedMovies) ?>)</a></nav><?php endif; ?>
  <div class="catalogue-section-heading" id="screening-soon"><div><p class="eyebrow">Current programme</p><h2>Screening soon <small>(<?= count($movies) ?>)</small></h2></div><span>Films are ordered by their next showtime.</span></div>
  <?php if ($movies): ?>
    <div class="movie-grid">
      <?php foreach ($movies as $movie) cinema_movie_card($movie, $firstScreening[(int) $movie['id']] ?? null); ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><h2>The next programme is on its way.</h2><p>There are no published films in their current run yet. Our team is preparing the schedule.</p><a class="text-link" href="<?= h(base_url('visit.php')) ?>">Plan a future visit →</a></div>
  <?php endif; ?>
  <?php if ($archivedMovies): ?>
    <div class="catalogue-section-heading catalogue-archive-heading" id="previously-shown"><div><p class="eyebrow">Private owner library</p><h2>Archived movies <small>(<?= count($archivedMovies) ?>)</small></h2></div><span>Only cinema owners can view these movies. They have no bookable screenings.</span></div>
    <div class="movie-grid archive-movie-grid">
      <?php foreach ($archivedMovies as $movie) cinema_movie_card($movie); ?>
    </div>
  <?php endif; ?>
</section>
</main>
<?php page_footer(); ?>
