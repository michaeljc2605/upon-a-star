/**
 * Exercise rolling maintenance against a new, disposable local database only.
 * Run: node scripts/test-rolling-programme.mjs --run
 * Defaults: 127.0.0.1:33079, root, empty password. Optional overrides are
 * ROLLING_TEST_MYSQL_HOST/PORT/USER/PASSWORD and PHP_BINARY. No .env is read.
 * Every database and scratch file created by this harness is removed in finally.
 * No provider, browser, deployed site, or existing application schema is used.
 */
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import { prepareSnapshot } from '../deployment/init-database.mjs';

if (!process.argv.includes('--run')) {
  console.error('Use --run to create and clean up an isolated local rolling-programme test schema.');
  process.exit(2);
}

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const host = process.env.ROLLING_TEST_MYSQL_HOST || '127.0.0.1';
assert(['127.0.0.1', 'localhost', '::1'].includes(host), 'Only a loopback database is allowed');
const port = Number(process.env.ROLLING_TEST_MYSQL_PORT || 33079);
assert(Number.isInteger(port) && port > 0 && port <= 65535, 'Valid local MySQL port required');
const database = `uas_rolling_test_${Date.now()}_${randomBytes(4).toString('hex')}`;
const connectionOptions = {
  host, port, user: process.env.ROLLING_TEST_MYSQL_USER || 'root',
  password: process.env.ROLLING_TEST_MYSQL_PASSWORD || '',
  timezone: '+08:00', dateStrings: true,
};
const clock = '2032-03-10T06:00:00+08:00';
const today = '2032-03-10';
const shiftDate = (date, days) => new Date(Date.parse(`${date}T12:00:00Z`) + days * 86_400_000).toISOString().slice(0, 10);
const horizonEnd = shiftDate(today, 44);
const suffix = randomBytes(4).toString('hex');
const children = new Set();
let admin, sql, scratch, fixture, created = false, cleaning, completed = 0;
let roomSequence = 0;

async function check(label, operation) {
  await operation();
  completed += 1;
  console.log(`PASS ${label}`);
}

async function cleanup() {
  if (cleaning) return cleaning;
  cleaning = (async () => {
    for (const child of children) {
      try { child.kill('SIGKILL'); } catch { /* already exited */ }
    }
    await sql?.end().catch(() => {});
    if (created && /^uas_rolling_test_\d+_[a-f0-9]{8}$/.test(database)) {
      await admin.query(`DROP DATABASE \`${database}\``);
      console.log('CLEANUP Removed the schema created by this run.');
    }
    await admin?.end().catch(() => {});
    if (scratch) await rm(scratch, { recursive: true, force: true });
  })();
  return cleaning;
}

for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => {
  cleanup().finally(() => process.exit(signal === 'SIGINT' ? 130 : 143));
});

const queryOne = async (statement, values = []) => (await sql.execute(statement, values))[0][0];
const scalar = async (statement, values = []) => Number(Object.values(await queryOne(statement, values))[0]);
const settings = () => queryOne('SELECT * FROM rolling_programme_settings WHERE id = 1');

async function setSettings(values) {
  const allowed = new Set(['enabled', 'horizon_days', 'batch_days', 'lead_minutes', 'retention_days', 'prune_enabled', 'generated_through', 'last_maintained_at']);
  assert(Object.keys(values).every((key) => allowed.has(key)), 'Known settings columns only');
  await sql.execute(`UPDATE rolling_programme_settings SET ${Object.keys(values).map((key) => `${key} = ?`).join(', ')} WHERE id = 1`, Object.values(values));
}

async function invoke(mode = 'refresh', clockValue = clock) {
  return new Promise((resolveResult, reject) => {
    const child = spawn(process.env.PHP_BINARY || 'php', ['-d', 'display_errors=0', fixture], {
      cwd: root,
      env: {
        PATH: process.env.PATH, ROLLING_FIXTURE_ROOT: root,
        ROLLING_FIXTURE_HOST: host, ROLLING_FIXTURE_PORT: String(port),
        ROLLING_FIXTURE_DATABASE: database, ROLLING_FIXTURE_USER: connectionOptions.user,
        ROLLING_FIXTURE_PASSWORD: connectionOptions.password,
      },
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    children.add(child);
    let output = '', diagnostics = '';
    const timeout = setTimeout(() => { child.kill('SIGKILL'); }, 20_000);
    child.stdout.on('data', (chunk) => { output = (output + chunk).slice(-100_000); });
    child.stderr.on('data', (chunk) => { diagnostics = (diagnostics + chunk).slice(-2000); });
    child.on('error', (error) => { clearTimeout(timeout); children.delete(child); reject(error); });
    child.on('close', (code, signal) => {
      clearTimeout(timeout);
      children.delete(child);
      if (code !== 0) {
        reject(new Error(`PHP rolling fixture failed (${signal || code}); ${diagnostics.trim() || 'no diagnostics'}`));
        return;
      }
      try {
        assert.equal(diagnostics.trim(), '', 'Rolling helper must not emit PHP diagnostics');
        resolveResult(JSON.parse(output));
      } catch (error) { reject(error); }
    });
    child.stdin.end(JSON.stringify({ mode, clock: clockValue }));
  });
}

function expectRefresh(result) {
  assert(['disabled', 'busy', 'complete', 'refreshed'].includes(result.status), 'Refresh returns a known status');
  for (const key of ['generated', 'pruned', 'processedDays']) {
    assert(Number.isInteger(result[key]) && result[key] >= 0, `${key} is a nonnegative integer`);
  }
  assert(result.processedDays <= 2, 'Each visit processes at most two dates');
  assert(result.pruned <= 36, 'Each visit prunes at most 36 generated screenings');
  assert(Object.hasOwn(result, 'generatedThrough') && Object.hasOwn(result, 'horizonEnd'), 'Refresh exposes cursor and horizon');
  return result;
}

async function refresh(clockValue = clock) {
  return expectRefresh(await invoke('refresh', clockValue));
}

async function createRoom(label) {
  roomSequence += 1;
  const code = `RT${suffix.slice(0, 6)}${roomSequence}`;
  const [result] = await sql.execute('INSERT INTO rooms (code, name, room_type, capacity, active) VALUES (?, ?, ?, ?, ?)', [code, `Rolling fixture ${label}`, 'small', 2, 1]);
  const id = Number(result.insertId);
  await sql.execute('INSERT INTO room_seats (room_id, seat_code, row_label, seat_number, block_number) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)', [id, 'A1', 'A', 1, 1, id, 'A2', 'A', 2, 1]);
  return id;
}

async function createMovie(label, { status = 'published', start = '2031-01-01', end = '2099-12-31' } = {}) {
  const [result] = await sql.execute(`INSERT INTO shows
    (title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url, status, run_start_date, run_end_date, sales_open_date, theme_key)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
  [`Rolling fixture ${label}`, `rolling-${label}-${suffix}`, 'An isolated rolling-programme test film.', 'Adventure', 90, 'PG', '/assets/movies/up/poster.webp', '/assets/movies/up/backdrop.webp', status, start, end, start, 'sky_adventure']);
  return Number(result.insertId);
}

async function template(showId, roomId, time = '18:00:00', enabled = 1) {
  await sql.execute('INSERT INTO rolling_programme_templates (show_id, room_id, start_time, format, language, unit_price, enabled) VALUES (?, ?, ?, ?, ?, ?, ?)', [showId, roomId, time, 'Standard', 'English', '17.25', enabled]);
}

async function seedPerformance(showId, roomId, date, { time = '18:00:00', status = 'scheduled', generated = false, booked = 0 } = {}) {
  const [result] = await sql.execute(`INSERT INTO performances
    (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
    SELECT ?, r.id, ?, r.name, 'Standard', 'English', 17.25, r.capacity, ?, ? FROM rooms r WHERE r.id = ?`, [showId, `${date} ${time}`, booked, status, roomId]);
  const id = Number(result.insertId);
  await sql.execute('INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?', [id, roomId]);
  if (generated) await sql.execute('INSERT INTO rolling_programme_slots (template_show_id, slot_date, performance_id) VALUES (?, ?, ?)', [showId, date, id]);
  return id;
}

async function seedBooking(performanceId, withTicket) {
  const reference = `SV-${randomBytes(12).toString('hex').toUpperCase()}`;
  const [booking] = await sql.execute('INSERT INTO bookings (public_reference, customer_name, customer_email, customer_phone, total_amount, booking_status, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?)', [reference, 'Rolling test guest', 'rolling-test@example.invalid', '+65 8123 4567', '17.25', 'confirmed', 'paid']);
  const bookingId = Number(booking.insertId);
  const [item] = await sql.execute('INSERT INTO booking_items (booking_id, performance_id, ticket_quantity, unit_price, line_total) VALUES (?, ?, 1, 17.25, 17.25)', [bookingId, performanceId]);
  if (withTicket) {
    const seat = await queryOne('SELECT id FROM performance_seats WHERE performance_id = ? ORDER BY id LIMIT 1', [performanceId]);
    await sql.execute('INSERT INTO booking_tickets (ticket_code, booking_id, booking_item_id, performance_seat_id) VALUES (?, ?, ?, ?)', [`SVT-${randomBytes(16).toString('hex').toUpperCase()}`, bookingId, item.insertId, seat.id]);
  }
  await sql.execute('INSERT INTO payment_events (provider_event_id, booking_id, provider_status, payload) VALUES (?, ?, ?, ?)', [`rolling-${reference}`, bookingId, 'paid', '{}']);
  await sql.execute('INSERT INTO email_deliveries (booking_id, recipient_email, delivery_status, provider_message, sent_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)', [bookingId, 'rolling-test@example.invalid', 'sent', 'isolated fixture']);
  return bookingId;
}

async function counts() {
  return queryOne(`SELECT (SELECT COUNT(*) FROM performances) AS performances,
    (SELECT COUNT(*) FROM performance_seats) AS seats,
    (SELECT COUNT(*) FROM rolling_programme_slots) AS slots,
    (SELECT COUNT(*) FROM bookings) AS bookings,
    (SELECT COUNT(*) FROM booking_items) AS items,
    (SELECT COUNT(*) FROM booking_tickets) AS tickets,
    (SELECT COUNT(*) FROM payment_events) AS payments,
    (SELECT COUNT(*) FROM email_deliveries) AS emails`);
}

async function boot() {
  // Read both deliverables before opening the database; missing implementation is not permission to improvise it.
  await readFile(join(root, 'php/includes/rolling-programme.php'), 'utf8');
  const migration = await readFile(join(root, 'deployment/infinityfree/rolling-programme.sql'), 'utf8');
  const directives = migration.replace(/\/\*[\s\S]*?\*\//g, '').replace(/--[^\r\n]*/g, '');
  assert(!/\b(?:USE\s+|CREATE\s+DATABASE|DROP\s+DATABASE|GRANT\s+|REVOKE\s+)/i.test(directives), 'Rolling migration must remain inside the newly created schema');
  admin = await mysql.createConnection({ ...connectionOptions, multipleStatements: true });
  await admin.query(`CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  created = true;
  await admin.query(prepareSnapshot(await readFile(join(root, 'database/submission/silver_screen.sql'), 'utf8'), database));
  sql = await mysql.createConnection({ ...connectionOptions, database, multipleStatements: true });
  await sql.query("SET time_zone = '+08:00'");
  scratch = await mkdtemp(join(dirname(root), 'upon-a-star-rolling-test-'));
  fixture = join(scratch, 'fixture.php');
  await writeFile(fixture, `<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Singapore');
try {
    $database = (string) getenv('ROLLING_FIXTURE_DATABASE');
    $host = (string) getenv('ROLLING_FIXTURE_HOST');
    if (!preg_match('/^uas_rolling_test_[0-9]+_[a-f0-9]{8}$/D', $database)
        || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Unsafe fixture target.');
    $pdo = new PDO('mysql:host=' . $host . ';port=' . getenv('ROLLING_FIXTURE_PORT') . ';dbname=' . $database . ';charset=utf8mb4',
        (string) getenv('ROLLING_FIXTURE_USER'), (string) getenv('ROLLING_FIXTURE_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+08:00'");
    require (string) getenv('ROLLING_FIXTURE_ROOT') . '/php/includes/rolling-programme.php';
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $mode = $input['mode'] ?? '';
    if ($mode === 'refresh') {
        $result = cinema_rolling_refresh($pdo, new DateTimeImmutable($input['clock'], new DateTimeZone('Asia/Singapore')));
    } elseif ($mode === 'lock_name') {
        $result = ['name' => cinema_rolling_lock_name($pdo)];
    } elseif ($mode === 'archive') {
        $result = ['changed' => $pdo->exec(cinema_rolling_archive_sql($pdo))];
    } else throw new RuntimeException('Unknown fixture action.');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
`);
  return migration;
}

async function runChecks(migration) {
  await check('Missing optional tables leave the existing programme unchanged', async () => {
    const before = await queryOne('SELECT (SELECT COUNT(*) FROM performances) AS performances, (SELECT COUNT(*) FROM performance_seats) AS seats');
    const result = await refresh();
    assert.equal(result.status, 'disabled');
    assert.deepEqual([result.generated, result.pruned, result.processedDays], [0, 0, 0]);
    assert.deepEqual(await queryOne('SELECT (SELECT COUNT(*) FROM performances) AS performances, (SELECT COUNT(*) FROM performance_seats) AS seats'), before);
  });
  const beforeMigration = await queryOne('SELECT (SELECT COUNT(*) FROM performances) AS performances, (SELECT COUNT(*) FROM bookings) AS bookings');
  await sql.query(migration);
  await check('Explicit migration enrolls the reviewed 18 films without changing existing screenings or bookings', async () => {
    const configured = await settings();
    assert.equal(Number(configured.enabled), 1);
    assert.equal(Number(configured.horizon_days), 45);
    assert.equal(Number(configured.batch_days), 2);
    assert(configured.initialized_at, 'Successful opt-in records its initialization');
    assert.equal(await scalar('SELECT COUNT(*) FROM rolling_programme_templates'), 18);
    assert.equal(await scalar("SELECT COUNT(*) FROM rolling_programme_templates t JOIN shows s ON s.id = t.show_id WHERE s.run_end_date = '2099-12-31'"), 18);
    assert.deepEqual(await queryOne('SELECT (SELECT COUNT(*) FROM performances) AS performances, (SELECT COUNT(*) FROM bookings) AS bookings'), beforeMigration);
  });
  await check('Schema defaults are disabled and an explicit owner pause makes visits inert', async () => {
    const column = await queryOne("SELECT COLUMN_DEFAULT AS value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'rolling_programme_settings' AND COLUMN_NAME = 'enabled'", [database]);
    assert.equal(Number(String(column.value).replace(/^'|'$/g, '')), 0);
    // The reviewed migration itself is an explicit opt-in for its 18-film plan.
    await setSettings({ enabled: 0 });
    const before = await counts();
    const cursor = await settings();
    assert.equal((await refresh()).status, 'disabled');
    assert.deepEqual(await counts(), before);
    assert.deepEqual(await settings(), cursor);
  });
  await check('Re-importing the migration preserves owner pauses and changed movie dates', async () => {
    const existing = await queryOne('SELECT show_id FROM rolling_programme_templates ORDER BY show_id LIMIT 1');
    await sql.execute("UPDATE shows SET status = 'archived', run_end_date = '2030-01-31' WHERE id = ?", [existing.show_id]);
    await sql.execute('UPDATE rolling_programme_templates SET enabled = 0 WHERE show_id = ?', [existing.show_id]);
    const before = await settings();
    await sql.query(migration);
    assert.deepEqual(await settings(), before);
    assert.equal(Number((await settings()).enabled), 0);
    assert.deepEqual(await queryOne('SELECT status, run_end_date FROM shows WHERE id = ?', [existing.show_id]), { status: 'archived', run_end_date: '2030-01-31' });
    assert.equal(await scalar('SELECT enabled FROM rolling_programme_templates WHERE show_id = ?', [existing.show_id]), 0);
  });
  await sql.execute('UPDATE rolling_programme_templates SET enabled = 0');
  const roomA = await createRoom('A');
  const roomB = await createRoom('B');
  const movieA = await createMovie('a');
  const movieB = await createMovie('b');
  await template(movieA, roomA);
  await template(movieB, roomB);
  const farFuture = await seedPerformance(movieA, roomA, '2099-06-01');
  await setSettings({ enabled: 1, horizon_days: 45, batch_days: 2, lead_minutes: 90, retention_days: 7, prune_enabled: 1, generated_through: '2001-01-01', last_maintained_at: null });
  await sql.execute('UPDATE rolling_programme_templates SET enabled = 0 WHERE show_id = ?', [movieB]);
  await check('A long absence starts today, ignores 2099 manual dates, and processes only two days', async () => {
    const result = await refresh();
    assert.equal(result.status, 'refreshed');
    assert.equal(result.processedDays, 2);
    assert.equal(result.generated, 2);
    assert.equal(result.generatedThrough, shiftDate(today, 1));
    assert.equal(result.horizonEnd, horizonEnd);
    const dates = (await sql.execute('SELECT slot_date FROM rolling_programme_slots WHERE template_show_id = ? ORDER BY slot_date', [movieA]))[0].map((row) => row.slot_date);
    assert.deepEqual(dates, [today, shiftDate(today, 1)]);
    assert.equal(await scalar('SELECT COUNT(*) FROM performances WHERE id = ? AND starts_at = ?', [farFuture, '2099-06-01 18:00:00']), 1);
  });
  await check('Revisited processed dates do not duplicate or replace existing screenings', async () => {
    const before = await counts();
    const rows = (await sql.execute('SELECT * FROM performances WHERE show_id = ? ORDER BY id', [movieA]))[0];
    await setSettings({ generated_through: shiftDate(today, -1), last_maintained_at: null });
    const result = await refresh();
    assert.equal(result.generated, 0);
    assert.equal(result.processedDays, 2);
    assert.equal(result.generatedThrough, shiftDate(today, 1));
    assert.deepEqual(await counts(), before);
    assert.deepEqual((await sql.execute('SELECT * FROM performances WHERE show_id = ? ORDER BY id', [movieA]))[0], rows);
  });
  await check('Repeated visits stop at the 45-day horizon, with complete real seat maps', async () => {
    let last;
    for (let attempt = 0; attempt < 24; attempt += 1) {
      await setSettings({ last_maintained_at: null });
      last = await refresh();
      assert.equal(last.horizonEnd, horizonEnd);
      if (last.generatedThrough === horizonEnd) break;
    }
    assert.equal(last.generatedThrough, horizonEnd);
    assert.equal(await scalar('SELECT COUNT(*) FROM rolling_programme_slots WHERE template_show_id = ?', [movieA]), 45);
    assert.equal(await scalar('SELECT COUNT(*) FROM rolling_programme_slots WHERE slot_date < ? OR slot_date > ?', [today, horizonEnd]), 0);
    assert.equal(await scalar(`SELECT COUNT(*) FROM rolling_programme_slots slot JOIN performances p ON p.id = slot.performance_id
      WHERE slot.template_show_id = ? AND (p.capacity != 2 OR p.seats_booked != 0 OR
      (SELECT COUNT(*) FROM performance_seats ps WHERE ps.performance_id = p.id) != p.capacity)`, [movieA]), 0);
    const before = await counts();
    const result = await refresh();
    assert.equal(result.status, 'complete');
    assert.equal(result.generated, 0);
    assert.equal(result.processedDays, 0);
    assert.deepEqual(await counts(), before);
  });
  await check('Room conflicts and owner-cancelled movie dates are left untouched', async () => {
    const blockerMovie = await createMovie('blocker');
    const blocker = await seedPerformance(blockerMovie, roomB, today, { time: '17:00:00' });
    const cancelled = await seedPerformance(movieB, roomB, shiftDate(today, 1), { status: 'cancelled' });
    const before = (await sql.execute('SELECT * FROM performances WHERE id IN (?, ?) ORDER BY id', [blocker, cancelled]))[0];
    await sql.execute('UPDATE rolling_programme_templates SET enabled = CASE WHEN show_id = ? THEN 1 ELSE 0 END', [movieB]);
    await setSettings({ generated_through: shiftDate(today, -1), last_maintained_at: null });
    const result = await refresh();
    assert.equal(result.generated, 0);
    assert.equal(result.processedDays, 2);
    assert.equal(result.generatedThrough, shiftDate(today, 1));
    assert.equal(await scalar('SELECT COUNT(*) FROM rolling_programme_slots WHERE template_show_id = ?', [movieB]), 0);
    assert.deepEqual((await sql.execute('SELECT * FROM performances WHERE id IN (?, ?) ORDER BY id', [blocker, cancelled]))[0], before);
  });
  await check('Owner archive pauses generation and owner run-end changes remain authoritative', async () => {
    await sql.execute("UPDATE shows SET status = 'archived' WHERE id = ?", [movieB]);
    const paused = await refresh();
    assert.equal(paused.generated, 0);
    assert.equal(paused.processedDays, 2);
    assert.equal((await queryOne('SELECT status FROM shows WHERE id = ?', [movieB])).status, 'archived');
    await sql.execute("UPDATE shows SET status = 'published', run_end_date = ? WHERE id = ?", [shiftDate(today, 2), movieB]);
    await setSettings({ generated_through: shiftDate(today, 1), last_maintained_at: null });
    const limited = await refresh();
    assert.equal(limited.generated, 1);
    assert.equal(limited.processedDays, 2);
    assert.deepEqual((await sql.execute('SELECT slot_date FROM rolling_programme_slots WHERE template_show_id = ?', [movieB]))[0].map((row) => row.slot_date), [shiftDate(today, 2)]);
    assert.equal((await queryOne('SELECT run_end_date FROM shows WHERE id = ?', [movieB])).run_end_date, shiftDate(today, 2));
  });
  await check('Same-day generation respects the minimum booking lead time', async () => {
    const room = await createRoom('lead');
    const movie = await createMovie('lead');
    await sql.execute('UPDATE rolling_programme_templates SET enabled = 0');
    await template(movie, room, '06:30:00');
    await setSettings({ generated_through: shiftDate(today, -1), last_maintained_at: null });
    const result = await refresh();
    assert.equal(result.processedDays, 2);
    assert.equal(result.generated, 1);
    assert.deepEqual((await sql.execute('SELECT slot_date FROM rolling_programme_slots WHERE template_show_id = ?', [movie]))[0].map((row) => row.slot_date), [shiftDate(today, 1)]);
  });
  await check('A held schema maintenance lock returns busy without waiting or writing', async () => {
    const { name } = await invoke('lock_name');
    assert(/^uas_rolling_[a-f0-9]{40}$/.test(name), 'Lock name is schema scoped');
    assert.equal(Number((await sql.execute('SELECT GET_LOCK(?, 0) AS acquired', [name]))[0][0].acquired), 1);
    try {
      const before = await counts();
      const cursor = await settings();
      const started = Date.now();
      const result = await refresh();
      assert.equal(result.status, 'busy');
      assert(Date.now() - started < 5000, 'Busy lock returns promptly');
      assert.deepEqual([result.generated, result.pruned, result.processedDays], [0, 0, 0]);
      assert.deepEqual(await counts(), before);
      assert.deepEqual(await settings(), cursor);
    } finally { await sql.execute('SELECT RELEASE_LOCK(?)', [name]); }
  });
  await check('Only active opted-in templates bypass completion archiving; manual end dates still expire', async () => {
    const actualToday = new Date(Date.now() + 8 * 3_600_000).toISOString().slice(0, 10);
    const past = shiftDate(actualToday, -10);
    const protectedMovie = await createMovie('archive-protected', { start: '2020-01-01' });
    const ordinaryMovie = await createMovie('archive-ordinary', { start: '2020-01-01' });
    const endedMovie = await createMovie('archive-ended', { start: '2020-01-01', end: shiftDate(actualToday, -1) });
    const disabledMovie = await createMovie('archive-disabled', { start: '2020-01-01' });
    await template(protectedMovie, roomA);
    await template(endedMovie, roomA);
    await template(disabledMovie, roomA, '18:00:00', 0);
    for (const id of [protectedMovie, ordinaryMovie, endedMovie, disabledMovie]) await seedPerformance(id, roomA, past);
    await invoke('archive');
    for (const [id, expected] of [[protectedMovie, 'published'], [ordinaryMovie, 'archived'], [endedMovie, 'archived'], [disabledMovie, 'archived']]) {
      assert.equal((await queryOne('SELECT status FROM shows WHERE id = ?', [id])).status, expected);
    }
    await setSettings({ enabled: 0 });
    await invoke('archive');
    assert.equal((await queryOne('SELECT status FROM shows WHERE id = ?', [protectedMovie])).status, 'archived');
    await setSettings({ enabled: 1 });
  });
  await check('Pruning preserves booked history and manual dates, deletes at most 36 managed empty slots, and cascades seats', async () => {
    await sql.execute('UPDATE rolling_programme_templates SET enabled = 0');
    await setSettings({ generated_through: horizonEnd, last_maintained_at: null });
    for (let index = 0; index < 40; index += 1) await seedPerformance(movieA, roomA, shiftDate(today, -50 + index), { generated: true });
    const ticketed = await seedPerformance(movieA, roomA, shiftDate(today, -60), { generated: true, booked: 1 });
    const itemOnly = await seedPerformance(movieA, roomA, shiftDate(today, -61), { generated: true });
    const counterOnly = await seedPerformance(movieA, roomA, shiftDate(today, -62), { generated: true, booked: 1 });
    const manual = await seedPerformance(movieA, roomA, shiftDate(today, -63));
    const recent = await seedPerformance(movieA, roomA, shiftDate(today, -3), { generated: true });
    const bookingA = await seedBooking(ticketed, true);
    const bookingB = await seedBooking(itemOnly, false);
    const bookingRows = (await sql.execute('SELECT * FROM bookings WHERE id IN (?, ?) ORDER BY id', [bookingA, bookingB]))[0];
    const before = await counts();
    const first = await refresh();
    assert.equal(first.generated, 0);
    assert.equal(first.pruned, 36);
    const sameDay = await refresh();
    assert.equal(sameDay.pruned, 0, 'Completed horizon avoids repeated maintenance during the same day');
    // Independently exercise another bounded maintenance run without changing
    // expiry boundaries or pretending repeated ordinary visits do more work.
    await setSettings({ last_maintained_at: null });
    const second = await refresh();
    assert.equal(second.pruned, 4);
    await setSettings({ last_maintained_at: null });
    const third = await refresh();
    assert.equal(third.pruned, 0);
    const after = await counts();
    assert.equal(Number(after.performances), Number(before.performances) - 40);
    assert.equal(Number(after.slots), Number(before.slots) - 40);
    assert.equal(Number(after.seats), Number(before.seats) - 80);
    for (const key of ['bookings', 'items', 'tickets', 'payments', 'emails']) assert.equal(Number(after[key]), Number(before[key]), `${key} history is preserved`);
    for (const id of [ticketed, itemOnly, counterOnly, manual, recent, farFuture]) assert.equal(await scalar('SELECT COUNT(*) FROM performances WHERE id = ?', [id]), 1);
    assert.deepEqual((await sql.execute('SELECT * FROM bookings WHERE id IN (?, ?) ORDER BY id', [bookingA, bookingB]))[0], bookingRows);
    assert.equal(await scalar('SELECT COUNT(*) FROM rolling_programme_slots slot LEFT JOIN performances p ON p.id = slot.performance_id WHERE p.id IS NULL'), 0);
    assert.equal(await scalar('SELECT COUNT(*) FROM performance_seats ps LEFT JOIN performances p ON p.id = ps.performance_id WHERE p.id IS NULL'), 0);
  });
  await check('The actual 2099 boundary generates December 30–31 only and stops in 2100', async () => {
    await sql.execute('UPDATE rolling_programme_templates SET enabled = 0');
    const room = await createRoom('year-limit');
    const movie = await createMovie('year-limit', { start: '2099-12-30', end: '2099-12-31' });
    await template(movie, room);
    await setSettings({ enabled: 1, horizon_days: 45, batch_days: 2, prune_enabled: 0, generated_through: '2099-12-29', last_maintained_at: null });
    // Future values remain in DATETIME/DATE columns. Legacy TIMESTAMP columns
    // keep their ordinary database defaults instead of receiving test-clock values.
    const boundary = await refresh('2099-12-30T06:00:00+08:00');
    assert.equal(boundary.status, 'refreshed');
    assert.equal(boundary.generated, 2);
    assert.equal(boundary.processedDays, 2);
    assert.equal(boundary.generatedThrough, '2099-12-31');
    assert.equal(boundary.horizonEnd, '2099-12-31');
    assert.deepEqual((await sql.execute('SELECT slot_date FROM rolling_programme_slots WHERE template_show_id = ? ORDER BY slot_date', [movie]))[0].map((row) => row.slot_date), ['2099-12-30', '2099-12-31']);
    assert.equal(await scalar("SELECT COUNT(*) FROM performances WHERE show_id = ? AND starts_at >= '2100-01-01'", [movie]), 0);
    const before = await counts();
    const cursor = await settings();
    const stopped = await refresh('2100-01-01T06:00:00+08:00');
    assert.equal(stopped.status, 'complete');
    assert.deepEqual([stopped.generated, stopped.pruned, stopped.processedDays], [0, 0, 0]);
    assert.equal(stopped.horizonEnd, '2099-12-31');
    assert.deepEqual(await counts(), before);
    assert.deepEqual(await settings(), cursor);
  });
}

try {
  const migration = await boot();
  await runChecks(migration);
  console.log(`PASS ${completed} rolling-programme integration groups. Only the isolated local schema was changed.`);
} catch (error) {
  console.error(`FAIL after ${completed} groups: ${error.message}`);
  process.exitCode = 1;
} finally {
  await cleanup();
}
