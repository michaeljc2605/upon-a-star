<?php
declare(strict_types=1);

/**
 * Keep the demonstration programme bookable as its seeded dates pass.
 *
 * Run from the command line only. It adds screenings and their seat maps for
 * the next seven days without moving, cancelling, or replacing existing ones.
 * Running it again is safe: a show with a future screening on a date is left
 * alone, so existing booking references and occupied seats remain attached to
 * the original screening.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Singapore'));
$earliest = new DateTimeImmutable('+90 minutes', new DateTimeZone('Asia/Singapore'));
$slotHours = [10, 14, 18];
$added = 0;
$seatMaps = 0;

$lock = $pdo->query("SELECT GET_LOCK('silver_village_base_schedule', 5)")->fetchColumn();
if ((int) $lock !== 1) {
    fwrite(STDERR, "Could not lock the schedule for updating. Try again shortly.\n");
    exit(1);
}

try {
    $rooms = $pdo->query(<<<'SQL'
        SELECT r.id, r.name, r.room_type, COUNT(rs.id) AS seat_count
          FROM rooms r
          JOIN room_seats rs ON rs.room_id = r.id
         GROUP BY r.id, r.name, r.room_type
         ORDER BY r.id
    SQL)->fetchAll();
    if (!$rooms) {
        throw new RuntimeException('The cinema has no room seat maps.');
    }

    $shows = $pdo->query(<<<'SQL'
        SELECT id, title, duration_minutes, run_start_date, run_end_date
          FROM shows
         WHERE status = 'published'
         ORDER BY id
    SQL)->fetchAll();
    $lastRoom = $pdo->prepare('SELECT room_id, language FROM performances WHERE show_id = ? AND room_id IS NOT NULL ORDER BY starts_at DESC, id DESC LIMIT 1');
    $hasScreening = $pdo->prepare(<<<'SQL'
        SELECT 1 FROM performances
         WHERE show_id = ? AND starts_at >= ? AND starts_at < ?
           AND starts_at > ? AND status = 'scheduled' AND history_deleted_at IS NULL
         LIMIT 1
    SQL);
    $roomBusy = $pdo->prepare(<<<'SQL'
        SELECT 1 FROM performances p JOIN shows s ON s.id = p.show_id
         WHERE p.room_id = ? AND p.status = 'scheduled'
           AND p.history_deleted_at IS NULL
           AND p.starts_at < ?
           AND TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at) > ?
         LIMIT 1
    SQL);
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

    $pdo->beginTransaction();
    foreach ($shows as $show) {
        $showId = (int) $show['id'];
        $lastRoom->execute([$showId]);
        $previous = $lastRoom->fetch() ?: [];
        $preferredRoom = (int) ($previous['room_id'] ?? 0);
        $language = (string) ($previous['language'] ?? 'English');
        usort($rooms, static function (array $a, array $b) use ($preferredRoom): int {
            return ((int) $b['id'] === $preferredRoom) <=> ((int) $a['id'] === $preferredRoom)
                ?: ((int) $a['id'] <=> (int) $b['id']);
        });

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $day = $today->modify('+' . $dayOffset . ' days');
            if ($dayOffset === 0 && $day->setTime(18, 0) <= $earliest) {
                continue;
            }
            $date = $day->format('Y-m-d');
            if (($show['run_start_date'] !== null && $date < $show['run_start_date'])
                || ($show['run_end_date'] !== null && $date > $show['run_end_date'])) {
                continue;
            }

            $tomorrow = $day->modify('+1 day')->format('Y-m-d 00:00:00');
            $hasScreening->execute([$showId, $date . ' 00:00:00', $tomorrow, $earliest->format('Y-m-d H:i:s')]);
            if ($hasScreening->fetchColumn()) {
                continue;
            }

            // Spread each film's single daily screening among morning,
            // afternoon, and evening. Check room occupancy before adding it.
            $chosen = null;
            for ($slotOffset = 0; $slotOffset < count($slotHours) && $chosen === null; $slotOffset++) {
                $slot = $slotHours[($showId + $dayOffset + $slotOffset) % count($slotHours)];
                $start = $day->setTime($slot, 0);
                if ($start <= $earliest || $slot * 60 + (int) $show['duration_minutes'] > 22 * 60 + 30) {
                    continue;
                }
                foreach ($rooms as $room) {
                    $roomBusy->execute([
                        (int) $room['id'],
                        $start->modify('+' . ((int) $show['duration_minutes'] + 60) . ' minutes')->format('Y-m-d H:i:s'),
                        $start->format('Y-m-d H:i:s'),
                    ]);
                    if (!$roomBusy->fetchColumn()) {
                        $chosen = [$start, $room];
                        break;
                    }
                }
            }
            if ($chosen === null) {
                throw new RuntimeException('No free room slot is available for ' . $show['title'] . ' on ' . $date . '.');
            }

            [$start, $room] = $chosen;
            $roomType = (string) $room['room_type'];
            $format = $roomType === 'premier'
                ? ((int) $room['id'] === 11 ? 'IMAX' : 'Dolby Atmos')
                : 'Standard';
            $price = $roomType === 'premier'
                ? ((int) $room['id'] === 11 ? '150000.00' : '135000.00')
                : ($roomType === 'small' ? '60000.00' : '80000.00');
            $insertScreening->execute([
                $showId, (int) $room['id'], $start->format('Y-m-d H:i:s'),
                (string) $room['name'], $format, $language, $price,
                (int) $room['seat_count'],
            ]);
            $performanceId = (int) $pdo->lastInsertId();
            $insertSeats->execute([$performanceId, (int) $room['id']]);
            if ($insertSeats->rowCount() !== (int) $room['seat_count']) {
                throw new RuntimeException('The seat map could not be created for ' . $show['title'] . '.');
            }
            $added++;
            $seatMaps += (int) $room['seat_count'];
        }
    }
    $pdo->commit();
    echo "Added {$added} screenings and {$seatMaps} selectable seats through "
        . $today->modify('+7 days')->format('Y-m-d') . ".\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Schedule update failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('silver_village_base_schedule')");
}
