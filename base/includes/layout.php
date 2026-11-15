<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Resolve bundled artwork or an owner-supplied HTTP(S) image. */
function movie_asset(?string $url, string $fallback = 'backdrop'): string
{
    $source = trim((string) $url);
    if (filter_var($source, FILTER_VALIDATE_URL)) {
        $parts = parse_url($source);
        if (is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])) {
            return $source;
        }
    }
    $path = parse_url($source, PHP_URL_PATH);
    if (is_string($path) && preg_match('~^/assets/movies/([a-z0-9-]+)/(poster|backdrop)\.webp$~', $path, $matches)) {
        $relative = 'assets/movies/' . $matches[1] . '/' . $matches[2] . '.webp';
        if (is_file(dirname(__DIR__) . '/' . $relative)) {
            return base_url($relative);
        }
    }
    return base_url('assets/movies/tangled/' . ($fallback === 'poster' ? 'poster' : 'backdrop') . '.webp');
}

function cinema_money(float|int|string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function cinema_date(?string $date, string $format = 'D, d M Y · H:i'): string
{
    $timestamp = $date ? strtotime($date) : false;
    return $timestamp === false ? 'Date to be announced' : date($format, $timestamp);
}

function cinema_title(string $title): string
{
    $words = preg_split('/\s+/u', trim($title)) ?: [];
    if (count($words) < 2) {
        return h($title);
    }
    $last = array_pop($words);
    return h(implode(' ', $words)) . ' <em>' . h((string) $last) . '</em>';
}

/** The same published and in-run public catalogue rule used by the modern site. */
function cinema_public_movies(bool $catalogueOrder = false): array
{
    $sql = <<<'SQL'
SELECT s.id, s.title, s.slug, s.synopsis, s.genre, s.duration_minutes,
       s.age_rating, s.poster_url, s.backdrop_url, s.featured, s.theme_key, s.status,
       s.run_start_date, s.run_end_date, s.sales_open_date,
       CASE WHEN s.run_start_date IS NOT NULL AND CURRENT_DATE < s.run_start_date
            THEN 'arriving_soon' ELSE 'ongoing' END AS lifecycle_status
FROM shows s
WHERE s.status = 'published'
  AND (
       (s.run_end_date IS NOT NULL AND s.run_end_date >= CURRENT_DATE)
       OR (s.run_end_date IS NULL AND EXISTS (
           SELECT 1 FROM performances visible
           WHERE visible.show_id = s.id
             AND visible.status = 'scheduled'
             AND visible.history_deleted_at IS NULL
             AND visible.starts_at > CURRENT_TIMESTAMP
       ))
  )
ORDER BY (CASE WHEN s.run_start_date IS NOT NULL AND CURRENT_DATE < s.run_start_date
               THEN 0 ELSE 1 END) DESC,
         s.featured DESC, s.run_start_date,
SQL;
    $sql .= $catalogueOrder ? ' s.title' : ' s.id';
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** The archive is private to authenticated owners of the enhanced PHP site. */
function cinema_can_view_archived_movies(): bool
{
    if (!defined('CINEMA_PHP_ENHANCED') || CINEMA_PHP_ENHANCED !== true || !function_exists('php_current_user')) {
        return false;
    }
    $user = php_current_user();
    return is_array($user) && ($user['role'] ?? null) === 'owner';
}

function cinema_archived_movies(): array
{
    if (!cinema_can_view_archived_movies()) {
        return [];
    }
    $sql = <<<'SQL'
SELECT s.id, s.title, s.slug, s.synopsis, s.genre, s.duration_minutes,
       s.age_rating, s.poster_url, s.backdrop_url, s.featured, s.theme_key, s.status,
       s.run_start_date, s.run_end_date, s.sales_open_date,
       'archived' AS lifecycle_status
FROM shows s
WHERE s.status = 'archived'
ORDER BY s.title, s.id
SQL;
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Dates with public screenings, without counting individual seats for every future show. */
function cinema_schedule_days(?int $showId = null): array
{
    $sql = <<<'SQL'
SELECT DATE(p.starts_at) AS screening_date, COUNT(*) AS screening_count
FROM performances p
JOIN shows s ON s.id = p.show_id
JOIN rooms r ON r.id = p.room_id
WHERE s.status = 'published'
  AND p.status = 'scheduled'
  AND p.history_deleted_at IS NULL
  AND p.starts_at > CURRENT_TIMESTAMP
  AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
  AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
  AND (s.run_end_date IS NULL OR s.run_end_date >= CURRENT_DATE)
SQL;
    if ($showId !== null) {
        $sql .= ' AND p.show_id = :show_id';
    }
    $sql .= ' GROUP BY DATE(p.starts_at) ORDER BY screening_date';
    $query = db()->prepare($sql);
    if ($showId !== null) {
        $query->bindValue(':show_id', $showId, PDO::PARAM_INT);
    }
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** Server-rendered public screenings. Unavailable and expired sessions are excluded. */
function cinema_upcoming_screenings(?int $showId = null, ?string $day = null): array
{
    $sql = <<<'SQL'
SELECT p.id, p.show_id, p.starts_at,
       DATE_ADD(p.starts_at, INTERVAL s.duration_minutes MINUTE) AS ends_at,
       p.unit_price, p.format, p.language,
       p.capacity, p.seats_booked,
       (SELECT COUNT(*)
          FROM performance_seats ps
          LEFT JOIN booking_tickets bt ON bt.performance_seat_id = ps.id
          LEFT JOIN seat_holds sh ON sh.performance_seat_id = ps.id AND sh.expires_at > CURRENT_TIMESTAMP
         WHERE ps.performance_id = p.id AND bt.id IS NULL AND sh.id IS NULL) AS seats_available,
       s.title, s.slug, s.poster_url, s.backdrop_url, s.sales_open_date,
       r.name AS room_name, r.room_type
FROM performances p
JOIN shows s ON s.id = p.show_id
JOIN rooms r ON r.id = p.room_id
WHERE s.status = 'published'
  AND p.status = 'scheduled'
  AND p.history_deleted_at IS NULL
  AND p.starts_at > CURRENT_TIMESTAMP
  AND (s.run_start_date IS NULL OR DATE(p.starts_at) >= s.run_start_date)
  AND (s.run_end_date IS NULL OR DATE(p.starts_at) <= s.run_end_date)
  AND (s.run_end_date IS NULL OR s.run_end_date >= CURRENT_DATE)
SQL;
    if ($showId !== null) {
        $sql .= ' AND p.show_id = :show_id';
    }
    if ($day !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Invalid screening date.');
        }
        $sql .= ' AND p.starts_at >= :day_start AND p.starts_at < :day_end';
    }
    $sql .= ' ORDER BY p.starts_at, s.title, p.id';
    $query = db()->prepare($sql);
    if ($showId !== null) {
        $query->bindValue(':show_id', $showId, PDO::PARAM_INT);
    }
    if ($day !== null) {
        $query->bindValue(':day_start', $date->format('Y-m-d 00:00:00'));
        $query->bindValue(':day_end', $date->modify('+1 day')->format('Y-m-d 00:00:00'));
    }
    $query->execute();
    $screenings = $query->fetchAll(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    foreach ($screenings as &$screening) {
        $screening['booking_open'] = (int) (
            (empty($screening['sales_open_date']) || $screening['sales_open_date'] <= $today)
            && (int) $screening['seats_available'] > 0
            && (int) $screening['seats_booked'] < (int) $screening['capacity']
        );
    }
    unset($screening);
    return $screenings;
}

function cinema_movie_badge(array $movie): void
{
    if (($movie['status'] ?? '') === 'archived') {
        echo '<div class="movie-lifecycle archived"><b>Archived</b><span>Owner only · no bookable screenings</span></div>';
        return;
    }
    $arriving = ($movie['lifecycle_status'] ?? '') === 'arriving_soon';
    echo '<div class="movie-lifecycle ' . ($arriving ? 'arriving-soon' : 'ongoing') . '">';
    echo '<b>' . ($arriving ? 'Arriving soon' : 'Ongoing') . '</b>';
    if ($arriving) {
        echo '<span>' . h(cinema_date((string) $movie['run_start_date'], 'd M Y'));
        if (!empty($movie['run_end_date'])) {
            echo '–' . h(cinema_date((string) $movie['run_end_date'], 'd M Y'));
        }
        echo '</span>';
        if (!empty($movie['sales_open_date']) && $movie['sales_open_date'] > date('Y-m-d')) {
            echo '<small>Tickets from ' . h(cinema_date((string) $movie['sales_open_date'], 'd M Y')) . '</small>';
        } else {
            echo '<small>Pre-sale now open</small>';
        }
    } elseif (!empty($movie['run_end_date'])) {
        echo '<span>Until ' . h(cinema_date((string) $movie['run_end_date'], 'd M Y')) . '</span>';
    }
    echo '</div>';
}

function cinema_screening_status(array $screening): string
{
    if ((int) ($screening['seats_available'] ?? 0) < 1) {
        return 'Sold out';
    }
    if (!empty($screening['sales_open_date']) && $screening['sales_open_date'] > date('Y-m-d')) {
        return 'Tickets available ' . cinema_date((string) $screening['sales_open_date'], 'd M Y');
    }
    return 'Booking unavailable';
}

/** Decorative film-specific effects, rendered by PHP and animated only with CSS. */
function cinema_movie_magic(string $theme): void
{
    $themes = [
        'enchanted_castle' => ['lantern-magic', 11],
        'ocean_and_islands' => ['ocean-magic', 16],
        'legends_and_courage' => ['snow-magic', 26],
        'hero_city' => ['hero-city-magic', 12],
        'tech_and_arcade' => ['tech-and-arcade-magic', 22],
        'worlds_of_imagination' => ['worlds-of-imagination-magic', 16],
        'sky_adventure' => ['sky-adventure-magic', 11],
        'culinary_magic' => ['culinary-magic-magic', 14],
        'animal_worlds' => ['animal-worlds-magic', 18],
    ];
    if (!isset($themes[$theme])) {
        echo '<div class="movie-magic storybook-magic" aria-hidden="true"></div>';
        return;
    }
    [$class, $count] = $themes[$theme];
    echo '<div class="movie-magic particle-magic ' . $class . '" aria-hidden="true">';
    for ($index = 0; $index < $count; $index++) {
        $left = 46 + (($index * 19) % 53);
        $top = 5 + (($index * 23) % 86);
        $size = 7 + (($index * 11) % 21);
        $speed = 7 + (($index * 5) % 10);
        $delay = -(($index * 3) % 14);
        $drift = -42 + (($index * 17) % 84);
        echo '<i style="--magic-left:' . $left . '%;--magic-top:' . $top . '%;--magic-size:' . $size . 'px;--magic-speed:' . $speed . 's;--magic-delay:' . $delay . 's;--magic-drift:' . $drift . 'px"></i>';
    }
    echo '</div>';
}

function cinema_movie_card(array $movie, ?array $screening = null): void
{
    $detail = base_url('movie.php?slug=' . rawurlencode((string) $movie['slug']));
    echo '<article class="movie-card' . (($movie['status'] ?? '') === 'archived' ? ' archived' : '') . '">';
    echo '<a class="movie-poster" href="' . h($detail) . '" aria-label="Explore ' . h($movie['title']) . '">';
    echo '<img src="' . h(movie_asset((string) $movie['poster_url'], 'poster')) . '" alt="' . h($movie['title']) . ' poster" loading="lazy"></a>';
    echo '<div class="movie-card-body">';
    cinema_movie_badge($movie);
    echo '<div class="tags"><span>' . h($movie['genre']) . '</span><span>' . h($movie['age_rating']) . '</span></div>';
    echo '<h3><a class="movie-title" href="' . h($detail) . '">' . cinema_title((string) $movie['title']) . '</a></h3>';
    echo '<p>' . h($movie['synopsis']) . '</p>';
    echo '<small>' . h((string) $movie['duration_minutes']) . ' minutes</small>';
    if ($screening !== null) {
        $open = (int) $screening['booking_open'] === 1;
        if ($open) {
            $link = base_url('book.php?performance_id=' . (int) $screening['id']);
            echo '<a class="showtime" href="' . h($link) . '">';
        } else {
            echo '<button class="showtime disabled" type="button" disabled>';
        }
        echo '<b>' . h(cinema_date((string) $screening['starts_at'])) . '</b>';
        echo '<span>' . h($screening['room_name']) . ' · ' . (int) $screening['seats_available'] . ' seats left · ' . h(cinema_money($screening['unit_price'])) . '</span>';
        if ($open) {
            echo '<strong>Choose seats →</strong>';
        }
        if (!$open) {
            echo '<small>' . h(cinema_screening_status($screening)) . '</small>';
        }
        echo $open ? '</a>' : '</button>';
    }
    echo '<a class="text-link" href="' . h($detail) . '">' . ($screening === null ? 'View movie details →' : 'View all times for this movie →') . '</a>';
    echo '</div></article>';
}

function page_header(string $title, string $active = ''): void
{
    $home = base_url();
    $movies = base_url('movies.php');
    $showtimes = base_url('showtimes.php');
    $visit = base_url('visit.php');
    $lookup = base_url('find-booking.php');
    $user = function_exists('php_current_user') ? php_current_user() : null;
    $canUseStaffTools = is_array($user) && in_array($user['role'] ?? '', ['owner', 'staff', 'manager'], true);
    $isOwner = is_array($user) && ($user['role'] ?? '') === 'owner';
    $cssVersion = (int) filemtime(dirname(__DIR__) . '/assets/base.css');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Upon a Star Cinema: discover films, explore showtimes, choose seats, and plan your visit.">
  <title><?= h($title) ?> | Upon a Star Cinema</title>
  <link rel="stylesheet" href="<?= h(base_url('assets/base.css?v=' . $cssVersion)) ?>">
  <script defer src="<?= h(base_url('assets/base.js')) ?>"></script>
</head>
<body>
<div class="cinema-shell">
  <div class="starfield" aria-hidden="true"><span class="star-layer-1"></span><span class="star-layer-2"></span><span class="star-layer-3"></span></div>
  <header class="cinema-header">
    <a class="brand" href="<?= h($home) ?>" aria-label="Upon a Star Cinema home">
      <b aria-hidden="true">✦</b><span><strong>Upon a <em>Star</em></strong><small>MOVIES UNDER THE STARS</small></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <a href="<?= h($home) ?>"<?= $active === 'home' ? ' aria-current="page"' : '' ?>>Home</a>
      <a href="<?= h($movies) ?>"<?= $active === 'movies' ? ' aria-current="page"' : '' ?>>Movies</a>
      <a href="<?= h($showtimes) ?>"<?= $active === 'showtimes' ? ' aria-current="page"' : '' ?>>Showtimes</a>
      <a href="<?= h($visit) ?>"<?= $active === 'visit' ? ' aria-current="page"' : '' ?>>Visit</a>
      <?php if ($canUseStaffTools): ?><a href="<?= h(base_url('staff.php')) ?>"<?= $active === 'staff' ? ' aria-current="page"' : '' ?>>Staff</a><?php endif; ?>
      <?php if ($isOwner): ?><a href="<?= h(base_url('attendance.php')) ?>"<?= $active === 'attendance' ? ' aria-current="page"' : '' ?>>Guest attendance</a><?php endif; ?>
    </nav>
    <div class="header-actions">
      <a class="ticket-button" href="<?= h($lookup) ?>">My booking <span aria-hidden="true">✦</span></a>
      <?php if (function_exists('cinema_extra_header_actions')) cinema_extra_header_actions(); ?>
    </div>
  </header>
  <div id="main-content">
<?php
}

function page_footer(): void
{
    ?>
  </div>
  <footer class="cinema-footer">
    <div class="footer-main">
      <div class="footer-intro">
        <a class="footer-brand" href="<?= h(base_url()) ?>"><b aria-hidden="true">✦</b><span><strong>Upon a <em>Star</em></strong><small>MOVIES UNDER THE STARS</small></span></a>
        <p>One cinema, many worlds. Discover thoughtful films, choose your screening, and settle in for a story worth remembering.</p>
      </div>
      <div class="footer-column"><p>Explore</p><a href="<?= h(base_url('movies.php')) ?>">Now showing</a><a href="<?= h(base_url('showtimes.php')) ?>">Showtimes</a><a href="<?= h(base_url('find-booking.php')) ?>">My booking</a></div>
      <div class="footer-column"><p>Visit</p><span>Open daily · 10:00–22:30</span><span>Arrive 20 minutes before showtime.</span><a href="<?= h(base_url('visit.php')) ?>">Plan your visit</a></div>
      <div class="footer-column"><p>Access</p><span>Assigned seating and booking support are available.</span><span>Speak to the cinema team for venue assistance.</span></div>
    </div>
    <div class="footer-base"><small>© <?= date('Y') ?> Upon a Star Cinema</small><?php if (defined('CINEMA_PHP_ENHANCED') && CINEMA_PHP_ENHANCED): ?><span><a href="<?= h(base_url('privacy.php')) ?>">Privacy</a> · <a href="<?= h(base_url('terms.php')) ?>">Student project &amp; terms</a></span><?php else: ?><span>Original cinema concept · Movies under the stars</span><?php endif; ?></div>
  </footer>
</div>
</body>
</html>
<?php
}
