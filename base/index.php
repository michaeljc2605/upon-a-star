<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$movies = cinema_public_movies();
$screenings = cinema_upcoming_screenings();
$firstScreening = [];
foreach ($screenings as $screening) {
    if ((int) $screening['booking_open'] === 1) {
        $firstScreening[(int) $screening['show_id']] ??= $screening;
    }
}
// Keep the editorial film order while showing bookable titles first.
$previews = [];
foreach ($movies as $movie) {
    $showId = (int) $movie['id'];
    if (isset($firstScreening[$showId])) {
        $previews[$showId] = $movie;
    }
    if (count($previews) >= 6) {
        break;
    }
}
foreach ($movies as $movie) {
    $showId = (int) $movie['id'];
    if (count($previews) >= 6) {
        break;
    }
    $previews[$showId] ??= $movie;
}
$previews = array_values($previews);

page_header('Home', 'home');
?>
<main class="home-page">
<?php if ($previews): ?>
<div class="hero-carousel" data-hero-carousel aria-label="Featured films">
  <?php foreach ($previews as $index => $movie): ?>
    <section class="hero hero-<?= h(str_replace('_', '-', (string) $movie['theme_key'])) ?>" data-hero-slide<?= $index === 0 ? '' : ' hidden' ?> style="--hero-backdrop: url('<?= h(movie_asset((string) $movie['backdrop_url'])) ?>')">
      <?php cinema_movie_magic((string) $movie['theme_key']); ?>
      <div class="hero-content">
        <p>Tonight’s enchanted story</p>
        <?php cinema_movie_badge($movie); ?>
        <h1><?= cinema_title((string) $movie['title']) ?></h1>
        <span><?= h($movie['synopsis']) ?></span>
        <div class="hero-actions">
          <a class="primary" href="<?= h(base_url('movie.php?slug=' . rawurlencode((string) $movie['slug']))) ?>">Discover this movie →</a>
          <a class="hero-all-movies" href="<?= h(base_url('movies.php')) ?>">View all movies</a>
        </div>
        <?php if (count($previews) > 1): ?>
          <div class="hero-switcher" aria-label="Featured movie controls">
            <button class="hero-arrow" type="button" data-slide-direction="-1" aria-label="Previous featured movie">←</button>
            <div class="hero-dots">
              <?php foreach ($previews as $dotIndex => $dotMovie): ?>
                <button type="button" data-slide-index="<?= $dotIndex ?>" class="<?= $index === $dotIndex ? 'active' : '' ?>" aria-label="Show <?= h($dotMovie['title']) ?>"<?= $index === $dotIndex ? ' aria-current="true"' : '' ?>></button>
              <?php endforeach; ?>
            </div>
            <button class="hero-arrow" type="button" data-slide-direction="1" aria-label="Next featured movie">→</button>
            <small><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?> / <?= str_pad((string) count($previews), 2, '0', STR_PAD_LEFT) ?></small>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php else: ?>
<section class="hero"><div class="hero-content"><p>Movies under the stars</p><h1>Stories worth <em>staying for.</em></h1><span>Our next programme is being prepared. Explore the cinema and return soon for new showtimes.</span><a class="primary" href="<?= h(base_url('visit.php')) ?>">Plan your visit →</a></div></section>
<?php endif; ?>

<section class="section" aria-labelledby="programme-heading">
  <div class="heading"><div><p>The programme</p><h2 id="programme-heading">Now showing &amp; <em>arriving soon</em></h2></div><a class="text-link" href="<?= h(base_url('movies.php')) ?>">See more movies →</a></div>
  <?php if ($previews): ?>
    <div class="movie-grid">
      <?php foreach ($previews as $movie) cinema_movie_card($movie, $firstScreening[(int) $movie['id']] ?? null); ?>
    </div>
  <?php else: ?>
    <p class="empty-state">The programme is being refreshed. Check back for the next story.</p>
  <?php endif; ?>
</section>

<?php if ($screenings): ?>
<section class="section next-screenings" aria-labelledby="next-screenings-heading">
  <div class="heading"><div><p>Pick your moment</p><h2 id="next-screenings-heading">The next <em>showtimes.</em></h2></div><span>Book an assigned seat for a scheduled screening and keep your reference for your visit.</span></div>
  <div class="quick-showtimes">
    <?php foreach (array_slice($screenings, 0, 3) as $screening): ?>
      <article>
        <img src="<?= h(movie_asset((string) $screening['poster_url'], 'poster')) ?>" alt="<?= h($screening['title']) ?> poster" loading="lazy">
        <div><b><?= h($screening['title']) ?></b><span><?= h(cinema_date((string) $screening['starts_at'])) ?></span><small><?= h($screening['room_name']) ?> · <?= (int) $screening['seats_available'] ?> seats left · <?= h(cinema_money($screening['unit_price'])) ?></small></div>
        <?php if ((int) $screening['booking_open'] === 1): ?>
          <a href="<?= h(base_url('book.php?performance_id=' . (int) $screening['id'])) ?>">Choose seats →</a>
        <?php else: ?>
          <span class="showtime-status"><?= h(cinema_screening_status($screening)) ?></span>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <a class="text-link" href="<?= h(base_url('showtimes.php')) ?>">See the full schedule →</a>
</section>
<?php endif; ?>

<section class="section" id="cinema-rooms" aria-labelledby="rooms-heading">
  <div class="heading"><div><p>Inside Upon a Star</p><h2 id="rooms-heading">Choose your cinema <em>room.</em></h2></div><span>Each screening shows its exact room before you choose seats.</span></div>
  <div class="room-guide">
    <article><b>Normal</b><p>Our everyday cinema room: 120 seats, a full-size screen, and the widest choice of showtimes.</p></article>
    <article><b>Premier</b><p>A smaller 36-seat room for a more intimate experience, premium sound, and selected special screenings.</p></article>
    <article><b>Small</b><p>A relaxed 60-seat room for documentaries, family films, and quieter showings.</p></article>
  </div>
</section>

<section class="section village-story" aria-labelledby="story-heading">
  <div class="heading"><div><p>The Upon a Star standard</p><h2 id="story-heading">Good films deserve a good <em>room.</em></h2></div><span>From the programme to your chosen seat, every detail is designed to make a cinema night feel considered.</span></div>
  <div class="story-grid">
    <article><b>Thoughtfully selected</b><p>Discover a changing mix of family favourites, adventures, documentaries, and independent stories.</p></article>
    <article><b>Made for the big screen</b><p>Clear sound, comfortable seating, and room types that suit both blockbusters and intimate dramas.</p></article>
    <article><b>A warm local cinema</b><p>Simple booking, assigned seating, and a friendly team from the first showtime to the credits.</p></article>
  </div>
</section>

<section class="section visit-card" aria-labelledby="visit-heading">
  <div><p>Plan your visit</p><h2 id="visit-heading">Everything for a smooth night <em>out.</em></h2><span>Arrive a little early, collect snacks, and settle into your assigned seat before the trailers begin.</span><a class="text-link" href="<?= h(base_url('visit.php')) ?>">Explore the cinema →</a></div>
  <div class="visit-details"><article><b>Opening hours</b><span>Daily · 10:00–22:30<br>Last entry follows the final scheduled screening.</span></article><article><b>Need a hand?</b><span>Speak to the team at the cinema desk about booking questions, accessibility, or group visits.</span></article><article><b>Before you arrive</b><span>Keep your booking reference handy and arrive 20 minutes before showtime.</span></article></div>
</section>

<section class="cinema-finale"><div class="finale-rings" aria-hidden="true"></div><div class="finale-content"><b class="finale-mark" aria-hidden="true">✦</b><p>Every story starts somewhere</p><h2>Your next film <em>is waiting.</em></h2><a class="primary" href="<?= h(base_url('movies.php')) ?>">Discover your next story →</a></div></section>
</main>
<?php page_footer(); ?>
