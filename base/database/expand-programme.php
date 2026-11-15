<?php
declare(strict_types=1);

/**
 * Add the November 2026 cinema programme without replacing any customer data.
 *
 * Run with PHP CLI after importing catalogue.sql, or on an existing base site.
 * --dry-run performs the same checks and rolls every change back. The fixed
 * November programme moves to tomorrow on a fresh installation after it ends;
 * subsequent runs keep the original anchor and add only missing screenings.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--dry-run') {
        fwrite(STDERR, "Usage: php expand-programme.php [--dry-run]\n");
        exit(2);
    }
}

// These eight films are new to the original 27-film catalogue. Metadata is
// kept in PHP so this assessed base version needs no JavaScript or JSON runtime.
$newMovies = [
    ['Coco', 'coco', 'A young musician follows a family mystery into the Land of the Dead, where memories and music bring him closer to his ancestors.', 'Animation, Family, Fantasy, Musical', 106, 'PG', 'worlds_of_imagination'],
    ['Soul', 'soul', 'A jazz teacher finds himself in the Great Before and discovers how small moments can give life its meaning.', 'Animation, Family, Fantasy, Musical', 100, 'PG', 'worlds_of_imagination'],
    ['Luca', 'luca', 'Two young sea monsters share a summer of friendship, scooters, and new discoveries on the Italian Riviera.', 'Animation, Family, Adventure, Comedy', 95, 'PG', 'ocean_and_islands'],
    ['Encanto', 'encanto', 'Mirabel searches for a way to protect her gifted family when the magic of their Colombian home begins to fade.', 'Animation, Family, Fantasy, Musical', 102, 'PG', 'enchanted_castle'],
    ['Turning Red', 'turning-red', 'Mei tries to balance school, friends, and family after strong emotions start transforming her into a giant red panda.', 'Animation, Family, Comedy, Fantasy', 100, 'PG', 'animal_worlds'],
    ['Elemental', 'elemental', 'In a city of fire, water, earth, and air, Ember and Wade find an unexpected connection that changes their outlook.', 'Animation, Family, Comedy, Fantasy', 103, 'PG', 'worlds_of_imagination'],
    ['Onward', 'onward', 'Two elf brothers set off on a magical quest for one more day with their father and learn what family means along the way.', 'Animation, Family, Adventure, Fantasy', 102, 'PG', 'legends_and_courage'],
    ["A Bug's Life", 'a-bugs-life', 'An inventive ant recruits a circus troupe to help his colony stand up to the grasshoppers threatening their home.', 'Animation, Family, Adventure, Comedy', 95, 'G', 'animal_worlds'],
];

$timezone = new DateTimeZone('Asia/Singapore');
$today = new DateTimeImmutable('today', $timezone);
$tomorrow = $today->modify('+1 day');
$originalAnchor = new DateTimeImmutable('2026-11-06', $timezone);
$pdo = db();
$lock = $pdo->query("SELECT GET_LOCK('silver_village_base_schedule', 5)")->fetchColumn();
if ((int) $lock !== 1) {
    fwrite(STDERR, "Could not lock the schedule for updating. Try again shortly.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();

    $rooms = $pdo->query(<<<'SQL'
        SELECT r.id, r.name, r.room_type, COUNT(rs.id) AS seat_count
          FROM rooms r JOIN room_seats rs ON rs.room_id = r.id
         GROUP BY r.id, r.name, r.room_type ORDER BY r.id
    SQL)->fetchAll();
    if (count($rooms) < 18) {
        throw new RuntimeException('The expanded programme needs at least 18 rooms with seat maps.');
    }

    // A previously inserted new film is the programme marker. In particular,
    // rerunning after November must never silently move sold screenings.
    $firstSlug = $newMovies[0][1];
    $markerQuery = $pdo->prepare('SELECT run_start_date FROM shows WHERE slug = ?');
    $markerQuery->execute([$firstSlug]);
    $marker = $markerQuery->fetchColumn();
    $anchor = $marker !== false && $marker !== null
        ? new DateTimeImmutable((string) $marker, $timezone)
        : ($tomorrow > $originalAnchor ? $tomorrow : $originalAnchor);
    $novemberEnd = $anchor->modify('+20 days');
    $decemberStart = $anchor->modify('+36 days');
    $decemberEnd = $decemberStart->modify('+6 days');
    $salesOpen = $anchor->modify('-1 day')->format('Y-m-d');

    $existing = $pdo->query('SELECT id, title, slug, duration_minutes, status FROM shows ORDER BY id')->fetchAll();
    $bySlug = [];
    foreach ($existing as $show) {
        $bySlug[(string) $show['slug']] = $show;
    }
    foreach (range(18, 27) as $expectedId) {
        if (!isset($existing[$expectedId - 1]) || (int) $existing[$expectedId - 1]['id'] !== $expectedId) {
            throw new RuntimeException('The original public film IDs are missing; import the base catalogue first.');
        }
    }

    $insertMovie = $pdo->prepare(<<<'SQL'
        INSERT INTO shows
            (title, slug, synopsis, genre, duration_minutes, age_rating,
             poster_url, backdrop_url, featured, status, run_start_date,
             run_end_date, sales_open_date, theme_key, trailer_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'published', ?, ?, ?, ?, NULL)
    SQL);
    $newFilmCount = 0;
    foreach ($newMovies as [$title, $slug, $synopsis, $genre, $duration, $rating, $theme]) {
        $assetDir = dirname(__DIR__) . '/assets/movies/' . $slug;
        if (!is_file($assetDir . '/poster.webp') || !is_file($assetDir . '/backdrop.webp')) {
            throw new RuntimeException('Local poster or backdrop is missing for ' . $title . '.');
        }
        if (isset($bySlug[$slug])) {
            if ((string) $bySlug[$slug]['title'] !== $title) {
                throw new RuntimeException('A different film already uses slug ' . $slug . '.');
            }
            continue;
        }
        $asset = '/assets/movies/' . $slug . '/';
        $insertMovie->execute([
            $title, $slug, $synopsis, $genre, $duration, $rating,
            $asset . 'poster.webp', $asset . 'backdrop.webp',
            $anchor->format('Y-m-d'), $novemberEnd->format('Y-m-d'),
            $salesOpen, $theme,
        ]);
        $bySlug[$slug] = [
            'id' => (int) $pdo->lastInsertId(), 'title' => $title,
            'slug' => $slug, 'duration_minutes' => $duration, 'status' => 'published',
        ];
        $newFilmCount++;
    }

    // Historical films stay in the database for old reservations and reports.
    // Fail instead of concealing a future booking for one of those films.
    $futureLegacyBookings = (int) $pdo->query(<<<'SQL'
        SELECT COUNT(*) FROM bookings b
          JOIN booking_items bi ON bi.booking_id = b.id
          JOIN performances p ON p.id = bi.performance_id
         WHERE p.show_id BETWEEN 1 AND 17 AND p.starts_at >= NOW()
           AND b.booking_status = 'confirmed'
    SQL)->fetchColumn();
    if ($futureLegacyBookings > 0) {
        throw new RuntimeException('An original film has a future booking; it cannot be archived safely.');
    }
    $archived = $pdo->exec("UPDATE shows SET status = 'archived' WHERE id BETWEEN 1 AND 17 AND status = 'published'");

    // Preserve every existing screening. The five films previously advertised
    // for December retain their December performances and get a second week.
    $latestScreening = $pdo->prepare(<<<'SQL'
        SELECT MAX(DATE(starts_at)) FROM performances
         WHERE show_id = ? AND status = 'scheduled' AND history_deleted_at IS NULL
    SQL);
    $updateExisting = $pdo->prepare(<<<'SQL'
        UPDATE shows SET status = 'published', run_start_date = ?,
            run_end_date = ?, sales_open_date = ? WHERE id = ?
    SQL);
    foreach (range(18, 27) as $showId) {
        $latestScreening->execute([$showId]);
        $latest = $latestScreening->fetchColumn();
        $end = $showId >= 23 ? $decemberEnd->format('Y-m-d') : $novemberEnd->format('Y-m-d');
        if ($latest !== false && $latest !== null && $latest > $end) {
            $end = (string) $latest;
        }
        $updateExisting->execute([$anchor->format('Y-m-d'), $end, $salesOpen, $showId]);
    }

    $programme = [];
    foreach (range(18, 27) as $showId) {
        $programme[] = $existing[$showId - 1];
    }
    foreach ($newMovies as $movie) {
        $programme[] = $bySlug[$movie[1]];
    }

    $existingScreenings = $pdo->prepare(<<<'SQL'
        SELECT p.show_id, p.room_id, p.starts_at, s.duration_minutes
          FROM performances p JOIN shows s ON s.id = p.show_id
         WHERE p.starts_at >= ? AND p.starts_at < ?
           AND p.status = 'scheduled' AND p.history_deleted_at IS NULL
         ORDER BY p.starts_at, p.id
    SQL);
    $existingScreenings->execute([
        $anchor->format('Y-m-d 00:00:00'),
        $decemberEnd->modify('+1 day')->format('Y-m-d 00:00:00'),
    ]);
    $occupancy = [];
    $screeningsPerFilmDay = [];
    $preferredRoom = [];
    foreach ($existingScreenings->fetchAll() as $screening) {
        $date = substr((string) $screening['starts_at'], 0, 10);
        $showId = (int) $screening['show_id'];
        $roomId = (int) $screening['room_id'];
        $startTime = strtotime((string) $screening['starts_at']);
        $occupancy[$date][$roomId][] = [
            $startTime, $startTime + ((int) $screening['duration_minutes'] + 60) * 60,
        ];
        $screeningsPerFilmDay[$date][$showId] = ($screeningsPerFilmDay[$date][$showId] ?? 0) + 1;
        $preferredRoom[$showId] = $roomId;
    }

    $insertScreening = $pdo->prepare(<<<'SQL'
        INSERT INTO performances
            (show_id, room_id, starts_at, auditorium, format, language,
             unit_price, capacity, seats_booked, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'scheduled')
    SQL);
    $insertSeats = $pdo->prepare(<<<'SQL'
        INSERT INTO performance_seats (performance_id, room_seat_id)
        SELECT ?, id FROM room_seats WHERE room_id = ?
    SQL);
    $added = 0;
    $seatMaps = 0;

    $scheduleDay = static function (DateTimeImmutable $day, array $films) use (
        &$occupancy, &$screeningsPerFilmDay, $rooms, $preferredRoom,
        $insertScreening, $insertSeats, &$added, &$seatMaps, $today, $pdo
    ): void {
        $date = $day->format('Y-m-d');
        $weekend = in_array((int) $day->format('N'), [5, 6, 7], true);
        $target = $weekend ? 2 : 1;
        $dayOrdinal = (int) $day->format('z');
        foreach ($films as $index => $film) {
            $showId = (int) $film['id'];
            $duration = (int) $film['duration_minutes'];
            $present = $screeningsPerFilmDay[$date][$showId] ?? 0;
            for ($session = $present; $session < $target; $session++) {
                // A rotating early/late pattern makes the calendar useful for
                // families with different availability throughout the week.
                $earlyHours = [10, 11, 12, 13, 14];
                $lateHours = [16, 17, 18, 19, 20];
                $pool = $weekend && $session > 0 ? $lateHours
                    : ($weekend ? $earlyHours : array_merge($earlyHours, $lateHours));
                $firstSlot = ($index * 3 + $dayOrdinal * 2 + $session) % count($pool);
                $homeRoom = $preferredRoom[$showId] ?? (int) $rooms[$index % count($rooms)]['id'];
                $roomOrder = $rooms;
                usort($roomOrder, static function (array $a, array $b) use ($homeRoom): int {
                    return ((int) $b['id'] === $homeRoom) <=> ((int) $a['id'] === $homeRoom)
                        ?: ((int) $a['id'] <=> (int) $b['id']);
                });
                $chosen = null;
                for ($offset = 0; $offset < count($pool) && $chosen === null; $offset++) {
                    $hour = $pool[($firstSlot + $offset) % count($pool)];
                    $start = $day->setTime($hour, 0);
                    if ($start->format('Y-m-d') <= $today->format('Y-m-d')
                        || $hour * 60 + $duration > 22 * 60 + 30) {
                        continue;
                    }
                    $startTime = $start->getTimestamp();
                    $readyTime = $startTime + ($duration + 60) * 60;
                    foreach ($roomOrder as $room) {
                        $roomId = (int) $room['id'];
                        $free = true;
                        foreach ($occupancy[$date][$roomId] ?? [] as [$oldStart, $oldReady]) {
                            if ($oldStart < $readyTime && $startTime < $oldReady) {
                                $free = false;
                                break;
                            }
                        }
                        if ($free) {
                            $chosen = [$start, $startTime, $readyTime, $room];
                            break;
                        }
                    }
                }
                if ($chosen === null) {
                    throw new RuntimeException('No clash-free room for ' . $film['title'] . ' on ' . $date . '.');
                }
                [$start, $startTime, $readyTime, $room] = $chosen;
                $roomId = (int) $room['id'];
                $roomType = (string) $room['room_type'];
                $format = $roomType === 'premier' ? ($roomId === 11 ? 'IMAX' : 'Dolby Atmos') : 'Standard';
                $price = $roomType === 'premier' ? ($roomId === 11 ? '150000.00' : '135000.00')
                    : ($roomType === 'small' ? '60000.00' : '80000.00');
                $language = ($index + $dayOrdinal + $session) % 3 === 0 ? 'Bahasa Indonesia' : 'English';
                $insertScreening->execute([
                    $showId, $roomId, $start->format('Y-m-d H:i:s'),
                    (string) $room['name'], $format, $language, $price,
                    (int) $room['seat_count'],
                ]);
                $performanceId = (int) $pdo->lastInsertId();
                $insertSeats->execute([$performanceId, $roomId]);
                if ($insertSeats->rowCount() !== (int) $room['seat_count']) {
                    throw new RuntimeException('The seat map could not be created for ' . $film['title'] . '.');
                }
                $occupancy[$date][$roomId][] = [$startTime, $readyTime];
                $screeningsPerFilmDay[$date][$showId] = ($screeningsPerFilmDay[$date][$showId] ?? 0) + 1;
                $added++;
                $seatMaps += (int) $room['seat_count'];
            }
        }
    };

    for ($offset = 0; $offset <= 20; $offset++) {
        $scheduleDay($anchor->modify('+' . $offset . ' days'), $programme);
    }
    $decemberFilms = array_slice($programme, 5, 5);
    for ($offset = 0; $offset <= 6; $offset++) {
        $scheduleDay($decemberStart->modify('+' . $offset . ' days'), $decemberFilms);
    }

    // Audit all active rooms, including pre-existing screenings. The turnover
    // belongs to the first film, so adjacent starts must be at least its
    // runtime plus one hour apart.
    $roomClashes = $pdo->query(<<<'SQL'
        SELECT COUNT(*) FROM performances p1
          JOIN shows s1 ON s1.id = p1.show_id
          JOIN performances p2 ON p2.room_id = p1.room_id AND p2.id > p1.id
          JOIN shows s2 ON s2.id = p2.show_id
         WHERE p1.status = 'scheduled' AND p2.status = 'scheduled'
           AND p1.history_deleted_at IS NULL AND p2.history_deleted_at IS NULL
           AND p1.starts_at < TIMESTAMPADD(MINUTE, s2.duration_minutes + 60, p2.starts_at)
           AND p2.starts_at < TIMESTAMPADD(MINUTE, s1.duration_minutes + 60, p1.starts_at)
    SQL)->fetchColumn();
    if ((int) $roomClashes > 0) {
        throw new RuntimeException($roomClashes . ' scheduled room clashes remain.');
    }

    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
    echo ($dryRun ? 'Dry run: would add ' : 'Added ') . $newFilmCount
        . ' films, ' . $added . ' screenings, and ' . $seatMaps
        . ' selectable seats; archived ' . $archived . ' historical films.'
        . ' Programme: ' . $anchor->format('Y-m-d') . ' through '
        . $novemberEnd->format('Y-m-d') . "; December continuation through "
        . $decemberEnd->format('Y-m-d') . ".\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Programme expansion failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('silver_village_base_schedule')");
}
