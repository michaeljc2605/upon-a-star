<?php
declare(strict_types=1);

/** Optional visitor-driven programme; its SQL migration is the only opt-in. */
function cinema_rolling_settings(PDO $pdo): ?array
{
    try {
        $row = $pdo->query('SELECT * FROM rolling_programme_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $error) {
        // Existing installations have no rolling tables and keep their behaviour.
        if ((string) $error->getCode() === '42S02') return null;
        throw $error;
    }
}

function cinema_rolling_lock_name(PDO $pdo): string
{
    return 'uas_rolling_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
}

function cinema_rolling_query(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach (array_values($values) as $index => $value) {
        $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();
    return $statement;
}

/** Delete only unbooked, scheduler-created history, never imported/manual rows. */
function cinema_rolling_prune(PDO $pdo, DateTimeImmutable $now, int $retentionDays): int
{
    $cutoff = $now->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');
    $current = $now->format('Y-m-d H:i:s');
    $unused = "p.seats_booked = 0 AND NOT EXISTS (SELECT 1 FROM booking_items bi WHERE bi.performance_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM booking_tickets bt JOIN performance_seats ps ON ps.id = bt.performance_seat_id WHERE ps.performance_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM seat_holds sh JOIN performance_seats ps ON ps.id = sh.performance_seat_id
            WHERE ps.performance_id = p.id AND sh.expires_at > ?)";
    $pdo->beginTransaction();
    try {
        $settings = $pdo->query('SELECT enabled, prune_enabled FROM rolling_programme_settings WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$settings || !(int) $settings['enabled'] || !(int) $settings['prune_enabled']) {
            $pdo->rollBack();
            return 0;
        }
        $ids = cinema_rolling_query($pdo, "SELECT p.id FROM rolling_programme_slots managed
            JOIN performances p ON p.id = managed.performance_id JOIN shows s ON s.id = p.show_id
            WHERE TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) < ? AND $unused
            ORDER BY p.id LIMIT 36 FOR UPDATE", [$cutoff, $current])->fetchAll(PDO::FETCH_COLUMN);
        $removed = 0;
        foreach ($ids as $id) {
            // Recheck after locking; FK cascades remove only this empty seat map,
            // expired holds and its generated-slot marker.
            $removed += cinema_rolling_query($pdo, "DELETE p FROM performances p
                JOIN rolling_programme_slots managed ON managed.performance_id = p.id
                WHERE p.id = ? AND $unused", [(int) $id, $current])->rowCount();
        }
        $pdo->commit();
        return $removed;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/**
 * At most two new calendar days (36 screenings) and 36 empty-history removals
 * per visit. An explicit clock is for isolated tests, never a browser parameter.
 */
function cinema_rolling_refresh(PDO $pdo, ?DateTimeImmutable $clock = null): array
{
    $result = ['status' => 'disabled', 'generated' => 0, 'pruned' => 0,
        'processedDays' => 0, 'generatedThrough' => null, 'horizonEnd' => null];
    $settings = cinema_rolling_settings($pdo);
    if (!$settings || !(int) $settings['enabled']) return $result;
    $zone = new DateTimeZone('Asia/Singapore');
    $now = $clock === null
        ? new DateTimeImmutable((string) $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn(), $zone)
        : $clock->setTimezone($zone);
    $today = $now->setTime(0, 0);
    $horizonDays = max(1, min(60, (int) $settings['horizon_days']));
    $horizon = min($today->modify('+' . ($horizonDays - 1) . ' days')->format('Y-m-d'), '2099-12-31');
    $result['generatedThrough'] = $settings['generated_through'];
    $result['horizonEnd'] = $horizon;
    $result['status'] = 'complete';
    if ($today->format('Y-m-d') > '2099-12-31') return $result;
    if (($settings['generated_through'] ?? '') >= $horizon
        && substr((string) ($settings['last_maintained_at'] ?? ''), 0, 10) === $today->format('Y-m-d')) return $result;

    $lockName = cinema_rolling_lock_name($pdo);
    $locked = (int) cinema_rolling_query($pdo, 'SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn() === 1;
    if (!$locked) { $result['status'] = 'busy'; return $result; }
    try {
        $settings = cinema_rolling_settings($pdo);
        if (!$settings || !(int) $settings['enabled']) { $result['status'] = 'disabled'; return $result; }
        $horizonDays = max(1, min(60, (int) $settings['horizon_days']));
        $horizon = min($today->modify('+' . ($horizonDays - 1) . ' days')->format('Y-m-d'), '2099-12-31');
        $result['horizonEnd'] = $horizon;
        $batchDays = max(1, min(2, (int) $settings['batch_days']));
        $leadMinutes = max(15, min(180, (int) $settings['lead_minutes']));
        $earliest = $now->modify('+' . $leadMinutes . ' minutes');
        $cursor = empty($settings['generated_through']) ? $today
            : new DateTimeImmutable($settings['generated_through'] . ' 00:00:00', $zone);
        if (!empty($settings['generated_through'])) $cursor = $cursor->modify('+1 day');
        if ($cursor < $today) $cursor = $today; // No missed-day backfill after a quiet period.
        $templates = $pdo->query('SELECT show_id FROM rolling_programme_templates WHERE enabled = 1 ORDER BY show_id')->fetchAll(PDO::FETCH_COLUMN);
        if (count($templates) > 18) throw new RuntimeException('Review programme capacity before adding more templates.');

        for ($dayIndex = 0; $dayIndex < $batchDays && $cursor->format('Y-m-d') <= $horizon; $dayIndex++, $cursor = $cursor->modify('+1 day')) {
            $day = $cursor->format('Y-m-d');
            $nextDay = $cursor->modify('+1 day')->format('Y-m-d');
            $created = 0;
            $pdo->beginTransaction();
            try {
                // Serialize setting changes with the day's final watermark.
                $active = $pdo->query('SELECT enabled FROM rolling_programme_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
                if (!(int) $active) { $pdo->rollBack(); $result['status'] = 'disabled'; break; }
                foreach ($templates as $showId) {
                    // Match the owner scheduling order: movie, then room, then
                    // conflicting screenings. A concurrent owner edit is honoured.
                    $show = cinema_rolling_query($pdo, 'SELECT id, status, duration_minutes, run_start_date, run_end_date FROM shows WHERE id = ? FOR UPDATE', [(int) $showId])->fetch(PDO::FETCH_ASSOC);
                    $template = cinema_rolling_query($pdo, 'SELECT * FROM rolling_programme_templates WHERE show_id = ? AND enabled = 1 FOR UPDATE', [(int) $showId])->fetch(PDO::FETCH_ASSOC);
                    if (!$show || !$template || $show['status'] !== 'published'
                        || ($show['run_start_date'] !== null && $day < $show['run_start_date'])
                        || ($show['run_end_date'] !== null && $day > $show['run_end_date'])) continue;
                    $start = new DateTimeImmutable($day . ' ' . $template['start_time'], $zone);
                    if ($start < $earliest || $start->format('Y-m-d') !== $day) continue;
                    // A marker survives owner rescheduling/cancellation. Also
                    // retain every imported/manual screening, in any status.
                    if (cinema_rolling_query($pdo, 'SELECT performance_id FROM rolling_programme_slots WHERE template_show_id = ? AND slot_date = ?', [(int) $showId, $day])->fetchColumn() !== false) continue;
                    if (cinema_rolling_query($pdo, 'SELECT id FROM performances WHERE show_id = ? AND starts_at >= ? AND starts_at < ? LIMIT 1 FOR UPDATE', [(int) $showId, $day . ' 00:00:00', $nextDay . ' 00:00:00'])->fetchColumn() !== false) continue;
                    $room = cinema_rolling_query($pdo, 'SELECT id, name, capacity, active FROM rooms WHERE id = ? FOR UPDATE', [(int) $template['room_id']])->fetch(PDO::FETCH_ASSOC);
                    if (!$room || !(int) $room['active'] || (int) $room['capacity'] < 1) continue;
                    $seatCount = (int) cinema_rolling_query($pdo, 'SELECT COUNT(*) FROM room_seats WHERE room_id = ?', [(int) $room['id']])->fetchColumn();
                    if ($seatCount !== (int) $room['capacity']) continue;
                    $available = $start->modify('+' . ((int) $show['duration_minutes'] + 60) . ' minutes');
                    $conflict = cinema_rolling_query($pdo, "SELECT p.id FROM performances p JOIN shows s ON s.id = p.show_id
                        WHERE p.room_id = ? AND p.status = 'scheduled' AND p.starts_at < ?
                          AND TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at) > ? LIMIT 1 FOR UPDATE",
                        [(int) $room['id'], $available->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')])->fetchColumn();
                    if ($conflict !== false) continue;
                    cinema_rolling_query($pdo, "INSERT INTO performances (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'scheduled')", [(int) $showId, (int) $room['id'], $start->format('Y-m-d H:i:s'),
                        $room['name'], $template['format'], $template['language'], $template['unit_price'], (int) $room['capacity']]);
                    $performanceId = (int) $pdo->lastInsertId();
                    cinema_rolling_query($pdo, 'INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [$performanceId, (int) $room['id']]);
                    cinema_rolling_query($pdo, 'INSERT INTO rolling_programme_slots (template_show_id, slot_date, performance_id) VALUES (?, ?, ?)', [(int) $showId, $day, $performanceId]);
                    $created++;
                }
                cinema_rolling_query($pdo, 'UPDATE rolling_programme_settings SET generated_through = ?, last_maintained_at = ? WHERE id = 1', [$day, $now->format('Y-m-d H:i:s')]);
                $pdo->commit();
                $result['generated'] += $created;
                $result['processedDays']++;
                $result['generatedThrough'] = $day;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        }
        if ($result['status'] === 'disabled') return $result;
        if ((int) $settings['prune_enabled']) {
            $result['pruned'] = cinema_rolling_prune($pdo, $now, max(1, min(90, (int) $settings['retention_days'])));
        }
        cinema_rolling_query($pdo, 'UPDATE rolling_programme_settings SET last_maintained_at = ? WHERE id = 1 AND enabled = 1', [$now->format('Y-m-d H:i:s')]);
        if ($result['status'] !== 'disabled') $result['status'] = $result['processedDays'] > 0 ? 'refreshed' : 'complete';
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Rolling programme maintenance failed (' . get_class($error) . ').');
        $result['status'] = 'unavailable';
        return $result; // Existing programme remains usable; the next visit retries.
    } finally {
        try { cinema_rolling_query($pdo, 'SELECT RELEASE_LOCK(?)', [$lockName]); }
        catch (Throwable $error) { error_log('Rolling programme lock release failed.'); }
    }
}

/** Keep managed films available across quiet periods; owner expiry still wins. */
function cinema_rolling_archive_sql(PDO $pdo): string
{
    $settings = cinema_rolling_settings($pdo);
    $managed = $settings && (int) $settings['enabled']
        ? 'AND NOT EXISTS (SELECT 1 FROM rolling_programme_templates managed WHERE managed.show_id = s.id AND managed.enabled = 1)' : '';
    return "UPDATE shows s SET s.status = 'archived' WHERE s.status = 'published' AND (
        (s.run_end_date IS NOT NULL AND s.run_end_date < CURRENT_DATE)
        OR ((s.run_start_date IS NULL OR s.run_start_date <= CURRENT_DATE) $managed
            AND EXISTS (SELECT 1 FROM performances past WHERE past.show_id = s.id AND past.status = 'scheduled'
                AND past.history_deleted_at IS NULL AND TIMESTAMPADD(MINUTE, s.duration_minutes, past.starts_at) <= CURRENT_TIMESTAMP)
            AND NOT EXISTS (SELECT 1 FROM performances upcoming WHERE upcoming.show_id = s.id AND upcoming.status = 'scheduled'
                AND upcoming.history_deleted_at IS NULL AND TIMESTAMPADD(MINUTE, s.duration_minutes, upcoming.starts_at) > CURRENT_TIMESTAMP)))";
}

function cinema_rolling_archive_finished(PDO $pdo): void
{
    static $maintained = [];
    $identity = spl_object_id($pdo);
    if (!isset($maintained[$identity])) {
        $maintained[$identity] = true;
        cinema_rolling_refresh($pdo);
    }
    $pdo->exec(cinema_rolling_archive_sql($pdo));
}
