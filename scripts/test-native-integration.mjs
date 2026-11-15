/**
 * Full native-PHP integration checks against an explicitly disposable schema.
 * Start an isolated local MySQL service, then run:
 *   node scripts/test-native-integration.mjs --run
 * Or keep the public programme running for browser QA until Ctrl+C:
 *   node scripts/test-native-integration.mjs --preview
 * Preview-only account buttons live in its generated local scratch router;
 * no fixture login route or test token is added to the application.
 * Optional: NATIVE_TEST_MYSQL_PORT (default 33079), NATIVE_TEST_MYSQL_USER,
 * NATIVE_TEST_MYSQL_PASSWORD and PHP_BINARY. Remote database hosts are refused.
 * The harness owns a random uas_native_test_* schema, PHP workers and scratch
 * files; all are removed in finally. Existing schemas are never imported into.
 * Google and email providers are deliberately unconfigured, not bypassed.
 */
import assert from 'node:assert/strict';
import { randomBytes, createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import net from 'node:net';
import mysql from 'mysql2/promise';
import { prepareSnapshot } from '../deployment/init-database.mjs';

const previewMode = process.argv.includes('--preview');
if ((!process.argv.includes('--run') && !previewMode) || (process.argv.includes('--run') && previewMode)) {
  console.error('Choose --run for integration tests or --preview for isolated local browser QA.');
  process.exit(2);
}
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const host = process.env.NATIVE_TEST_MYSQL_HOST || '127.0.0.1';
assert(['127.0.0.1', 'localhost', '::1'].includes(host), 'Only a loopback database is allowed');
const port = Number(process.env.NATIVE_TEST_MYSQL_PORT || 33079);
assert(Number.isInteger(port) && port > 0 && port <= 65535, 'Valid local MySQL port required');
const database = `uas_native_test_${Date.now()}_${randomBytes(4).toString('hex')}`;
const connectionOptions = { host, port, user: process.env.NATIVE_TEST_MYSQL_USER || 'root', password: process.env.NATIVE_TEST_MYSQL_PASSWORD || '', multipleStatements: true, timezone: '+08:00', dateStrings: true };
let admin, sql, php, scratch, created = false, completed = 0, cleaning, stopping = false;
let phpLog = '';
let origin;
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const day = 86_400_000;
const sgDate = (value) => new Date(value + 8 * 3_600_000).toISOString().slice(0, 10);
const start = Math.ceil((Date.now() + 2 * day) / 3_600_000) * 3_600_000;
const future = (hours = 0) => new Date(start + hours * 3_600_000).toISOString();
const jar = () => new Map();
const actors = {};

async function check(label, operation) {
  await operation();
  completed += 1;
  console.log(`PASS ${label}`);
}

async function cleanup() {
  if (cleaning) return cleaning;
  cleaning = (async () => {
    if (php && php.exitCode === null) {
      try { process.kill(-php.pid, 'SIGTERM'); } catch { php.kill('SIGTERM'); }
      await Promise.race([new Promise((resolve) => php.once('exit', resolve)), pause(1500)]);
      try { process.kill(-php.pid, 'SIGKILL'); } catch { /* group already stopped */ }
    }
    await sql?.end().catch(() => {});
    if (created && /^uas_native_test_\d+_[a-f0-9]{8}$/.test(database)) {
      await admin.query(`DROP DATABASE \`${database}\``);
      console.log('CLEANUP Removed the schema created by this run.');
    }
    await admin?.end().catch(() => {});
    if (scratch) await rm(scratch, { recursive: true, force: true });
  })();
  return cleaning;
}
for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => {
  stopping = true;
  cleanup().finally(() => process.exit(signal === 'SIGINT' ? 130 : 143));
});

async function request(path, { method = 'GET', actor, cookies = actor?.cookies || jar(), body, form, headers = {}, csrf = false } = {}) {
  const outgoing = { ...headers };
  if (cookies.size) outgoing.Cookie = [...cookies].map(([key, value]) => `${key}=${value}`).join('; ');
  if (csrf) outgoing['X-CSRF-Token'] = cookies.get('silver_csrf') || '';
  let payload;
  if (form) {
    outgoing['Content-Type'] = 'application/x-www-form-urlencoded';
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(form)) {
      for (const item of Array.isArray(value) ? value : [value]) params.append(key, String(item));
    }
    payload = params.toString();
  } else if (body !== undefined) {
    outgoing['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  const response = await fetch(origin + path, { method, headers: outgoing, body: payload, redirect: 'manual', signal: AbortSignal.timeout(15_000) });
  for (const line of response.headers.getSetCookie()) {
    const [pair] = line.split(';');
    const equal = pair.indexOf('=');
    const name = pair.slice(0, equal), value = pair.slice(equal + 1);
    if (!value || /max-age=0(?:;|$)/i.test(line)) cookies.delete(name);
    else cookies.set(name, value);
  }
  const text = await response.text();
  let data = null;
  try { data = JSON.parse(text); } catch { /* canonical pages are HTML */ }
  return { status: response.status, text, data, headers: response.headers, location: response.headers.get('location') || '' };
}
const expect = (response, status) => {
  assert.equal(response.status, status, `Expected HTTP ${status}; received ${response.status}: ${response.data?.error || response.text.slice(0, 180)}`);
  return response;
};
const tokenFrom = (html) => {
  const match = html.match(/name="csrf_token"\s+value="([A-Za-z0-9_-]+)"/);
  assert(match, 'Canonical form exposes a session CSRF token');
  return match[1];
};
const queryOne = async (statement, values = []) => (await sql.execute(statement, values))[0][0];
const countBookings = async () => Number((await queryOne('SELECT COUNT(*) AS n FROM bookings')).n);

async function seedActor(label, role = 'customer', extra = {}) {
  const email = `${label}@native-integration.invalid`;
  const [insert] = await sql.execute('INSERT INTO users (username, display_name, email, google_subject, password_hash, role, date_of_birth, onboarding_complete, account_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [label, `${label} Person`, email, `test-google-${label}`, 'not-a-login-password', role, '1995-01-01', extra.onboarding === false ? 0 : 1, extra.disabled ? 'disabled' : 'active']);
  const token = randomBytes(32).toString('base64url');
  await sql.execute('INSERT INTO sessions (token_hash,user_id,expires_at,last_seen_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? MINUTE), DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? MINUTE))', [createHash('sha256').update(token).digest(), insert.insertId, extra.expired ? -1 : 60, extra.idle ? -61 : 0]);
  return actors[label] = { id: insert.insertId, email, token, cookies: new Map([['silver_session', token]]) };
}

async function boot() {
  admin = await mysql.createConnection(connectionOptions);
  await admin.query(`CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  created = true;
  await admin.query(prepareSnapshot(await readFile(join(root, 'database/submission/silver_screen.sql'), 'utf8'), database));
  sql = await mysql.createConnection({ ...connectionOptions, multipleStatements: false, database });
  await sql.query("SET time_zone = '+08:00'");
  // Imported public catalogue remains isolated from every real/local QA schema.
  if (!previewMode) {
    await sql.query("UPDATE shows SET status = 'archived'");
    await sql.query("UPDATE performances SET status = 'cancelled'");
  }
  await seedActor('customer'); await seedActor('other'); await seedActor('owner', 'owner');
  await seedActor('staff', 'staff'); await seedActor('manager', 'manager');
  await seedActor('expired', 'customer', { expired: true });
  await seedActor('idle', 'customer', { idle: true });
  await seedActor('disabled', 'customer', { disabled: true });
  await seedActor('incomplete', 'customer', { onboarding: false });
  scratch = await mkdtemp(join(dirname(root), 'upon-a-star-native-test-'));
  await mkdir(join(scratch, 'sessions'));
  const fixtureAccounts = Buffer.from(JSON.stringify(Object.fromEntries(
    ['customer', 'other', 'staff', 'manager', 'owner', 'incomplete'].map((name) => [name, { id: actors[name].id, token: actors[name].token }]),
  ))).toString('base64');
  const previewRouter = previewMode ? `
// This route exists only in this generated loopback preview router.
if ($path === '/__qa') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $host = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? '');
    if (!in_array($remote, ['127.0.0.1', '::1'], true) || !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) { http_response_code(404); exit; }
    require $root . '/php/_start.php';
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $accounts = json_decode(base64_decode('${fixtureAccounts}'), true, 512, JSON_THROW_ON_ERROR);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Reload the local test account page and try again.'); }
        $actor = $_POST['actor'] ?? null;
        if (!is_string($actor) || ($actor !== 'guest' && !isset($accounts[$actor]))) { http_response_code(400); exit('Choose one of the local test accounts.'); }
        $_SESSION = [];
        session_regenerate_id(true);
        if ($actor === 'guest') {
            setcookie('silver_session', '', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
        } else {
            $fixture = $accounts[$actor];
            $statement = db()->prepare('INSERT INTO sessions (token_hash,user_id,expires_at,last_seen_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 60 MINUTE), CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at), last_seen_at=CURRENT_TIMESTAMP');
            $statement->execute([hash('sha256', $fixture['token'], true), $fixture['id']]);
            setcookie('silver_session', $fixture['token'], ['expires'=>time()+3600,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
        }
        header('Location: /', true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET, POST'); http_response_code(405); exit; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Local cinema test accounts</title><style>body{font:18px system-ui;max-width:720px;margin:48px auto;padding:0 24px;line-height:1.6}button{font:inherit;padding:12px 20px;margin:6px;border:1px solid #777;border-radius:8px;cursor:pointer}h1{line-height:1.2}</style><main><h1>Local browser test fixtures</h1><p>This disposable local copy uses fake customer, staff and owner accounts. It does not connect to Google or send real email. Your original catalogue is available for interface checks.</p><form method="post" action="/__qa"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    foreach (['customer'=>'Customer', 'other'=>'Second customer', 'staff'=>'Staff', 'manager'=>'Manager', 'owner'=>'Owner', 'incomplete'=>'New customer (profile incomplete)', 'guest'=>'Signed out'] as $key=>$label) echo '<button type="submit" name="actor" value="' . h($key) . '">' . h($label) . '</button>';
    echo '</form><p><a href="/">Open cinema homepage</a></p><p>Return to /__qa to switch roles. All preview records are deleted when this local preview stops.</p></main></html>';
    exit;
}
` : '';
  const router = `<?php
$root = ${JSON.stringify(root)};
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (str_contains($path, '..') || str_contains($path, "\\\\") || str_contains($path, "\\0")) { http_response_code(404); exit; }
${previewRouter}
if (str_starts_with($path, '/api/')) $target = $root . '/php/native/index.php';
elseif ($path === '/' || $path === '/index.php') $target = $root . '/index.php';
elseif (preg_match('~^/(movies|movie|showtimes|visit|book|process-booking|confirmation|find-booking|profile|my-bookings|staff|attendance|logout|resend-email|privacy|terms)\\.php$~', $path)) $target = $root . '/php' . $path;
elseif (preg_match('~^/assets/(?:base\\.css|base\\.js|schedule\\.js|booking-validation\\.js|movies/[a-z0-9-]+/(?:poster|backdrop)\\.webp|visit/(?:arrival|premier|normal|small)\\.webp)$~', $path)) $target = $root . '/base' . $path;
elseif (preg_match('~^/qr-assets/(?:qr-renderer|staff-scanner)\\.js$~', $path)) $target = $root . '/php' . $path;
else { http_response_code(404); exit; }
if (!is_file($target)) { http_response_code(404); exit; }
if (str_ends_with($target, '.php')) { $_SERVER['SCRIPT_FILENAME'] = $target; $_SERVER['SCRIPT_NAME'] = $path; chdir($root); require $target; }
else { $types=['css'=>'text/css','js'=>'text/javascript','webp'=>'image/webp']; header('Content-Type: '.($types[pathinfo($target, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($target); }
`;
  await writeFile(join(scratch, 'router.php'), router);
  const probe = net.createServer();
  await new Promise((resolve, reject) => probe.once('error', reject).listen(0, '127.0.0.1', resolve));
  const httpPort = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  origin = `http://127.0.0.1:${httpPort}`;
  php = spawn(process.env.PHP_BINARY || 'php', ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', `session.save_path=${join(scratch, 'sessions')}`, '-S', `127.0.0.1:${httpPort}`, '-t', root, join(scratch, 'router.php')], {
    cwd: root, detached: true,
    env: { PATH: process.env.PATH, PHP_CLI_SERVER_WORKERS: '4', PHP_RATE_LIMIT_DIR: join(scratch, 'rate-limits'), PHP_BACKEND: 'native', PHP_SITE_PATH: '', PHP_DB_NAME: database, BASE_DB_HOST: host, BASE_DB_PORT: String(port), BASE_DB_USER: connectionOptions.user, BASE_DB_PASSWORD: connectionOptions.password, MYSQL_SSL: 'false', NODE_ENV: 'development', PUBLIC_URL: origin, PHP_CLIENT_ORIGIN: origin, PHP_AUTH_BROWSER_ORIGIN: origin, CLIENT_ORIGIN: origin, ALLOW_HTTP: 'true', SESSION_TTL_MINUTES: '60', GOOGLE_CLIENT_ID: '', GOOGLE_CLIENT_SECRET: '', GOOGLE_REDIRECT_URI: '', EMAILJS_SERVICE_ID: '', EMAILJS_TEMPLATE_ID: '', EMAILJS_PUBLIC_KEY: '' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  php.on('error', (error) => { phpLog += error.message; });
  for (const stream of [php.stdout, php.stderr]) stream.on('data', (chunk) => { phpLog = (phpLog + chunk).slice(-10_000); });
  let ready = false;
  for (let attempt = 0; attempt < 60; attempt++) {
    try { const result = await request('/api/health'); if (result.status === 200) { ready = true; break; } } catch { /* server starting */ }
    if (php.exitCode !== null) break;
    await pause(150);
  }
  assert(ready, 'Native PHP API must start and return a healthy response');
  for (const actor of Object.values(actors)) expect(await request('/api/auth/csrf', { actor }), 200);
}

async function runChecks() {
  const { customer, other, owner, staff, manager, incomplete } = actors;
  await check('Session hash, absolute/idle expiry, disabled accounts, and forged cookies', async () => {
    const me = expect(await request('/api/auth/me', { actor: customer }), 200).data.user;
    assert.equal(Number(me.id), customer.id); assert.equal(me.email, customer.email); assert.equal(me.role, 'customer');
    const stored = await queryOne('SELECT HEX(token_hash) AS digest, OCTET_LENGTH(token_hash) AS bytes FROM sessions WHERE user_id = ?', [customer.id]);
    assert.equal(Number(stored.bytes), 32); assert.equal(stored.digest.toLowerCase(), createHash('sha256').update(customer.token).digest('hex'));
    expect(await request('/api/auth/me'), 401);
    for (const name of ['expired', 'idle', 'disabled']) expect(await request('/api/auth/me', { actor: actors[name] }), 401);
    expect(await request('/api/auth/me', { cookies: new Map([['silver_session', randomBytes(32).toString('base64url')]]) }), 401);
  });
  await check('Public mutations reject missing or mismatched CSRF without changing data', async () => {
    const before = await queryOne('SELECT display_name FROM users WHERE id = ?', [customer.id]);
    const body = { displayName: 'Tampered Person', dateOfBirth: '1990-01-01' };
    expect(await request('/api/profile', { method: 'PUT', actor: customer, body: { ...body, internal: true } }), 403);
    expect(await request('/api/profile', { method: 'PUT', actor: customer, body, headers: { 'X-CSRF-Token': 'wrong' } }), 403);
    assert.deepEqual(await queryOne('SELECT display_name FROM users WHERE id = ?', [customer.id]), before);
    expect(await request('/api/profile', { method: 'PUT', actor: customer, csrf: true, body: { ...body, displayName: 'Updated Customer' } }), 200);
  });
  await check('Canonical PHP forms enforce their own CSRF before callable dispatch', async () => {
    const profile = expect(await request('/profile.php', { actor: customer }), 200);
    const before = await queryOne('SELECT display_name FROM users WHERE id = ?', [customer.id]);
    expect(await request('/profile.php', { method: 'POST', actor: customer, form: { csrf_token: 'wrong', display_name: 'Not Applied', date_of_birth: '1992-01-01' } }), 303);
    assert.deepEqual(await queryOne('SELECT display_name FROM users WHERE id = ?', [customer.id]), before);
    expect(await request('/profile.php', { method: 'POST', actor: customer, form: { csrf_token: tokenFrom(profile.text), display_name: 'Canonical Customer', date_of_birth: '1992-01-01' } }), 303);
    assert.equal((await queryOne('SELECT display_name FROM users WHERE id = ?', [customer.id])).display_name, 'Canonical Customer');
  });
  await check('Role checks protect owner routes and ignore client-supplied identities', async () => {
    for (const actor of [customer, staff, manager]) expect(await request('/api/admin/accounts', { actor }), 403);
    expect(await request('/api/admin/accounts'), 401);
    expect(await request(`/api/admin/accounts/${other.id}`, { method: 'PATCH', actor: customer, csrf: true, body: { role: 'owner', user: { id: owner.id, role: 'owner' }, internal: true } }), 403);
    expect(await request(`/api/admin/accounts/${owner.id}`, { method: 'PATCH', actor: owner, csrf: true, body: { role: 'customer' } }), 400);
    assert.equal((await queryOne('SELECT role FROM users WHERE id = ?', [other.id])).role, 'customer');
  });
  const movie = { title: 'Native integration film', slug: `native-integration-${Date.now()}`, synopsis: 'A deterministic cinema integration test with isolated database records.', genre: 'Adventure', durationMinutes: 90, ageRating: 'PG', posterUrl: '/assets/movies/up/poster.webp', backdropUrl: '/assets/movies/up/backdrop.webp', trailerUrl: '', themeKey: 'sky_adventure', status: 'published', featured: false, runStartDate: sgDate(Date.now() - day), runEndDate: sgDate(Date.now() + 90 * day), salesOpenDate: sgDate(Date.now() - 2 * day) };
  let movieId, screeningId, nextScreeningId, roomId, seats;
  await check('Owner creates a film and screening; overlap and turnover conflicts are rejected', async () => {
    movieId = expect(await request('/api/admin/movies', { method: 'POST', actor: owner, csrf: true, body: movie }), 201).data.id;
    roomId = Number((await queryOne('SELECT id FROM rooms WHERE active = 1 ORDER BY id LIMIT 1')).id);
    const body = { showId: movieId, roomId, startsAt: future(), format: 'Standard', language: 'English', unitPrice: 17.25 };
    screeningId = expect(await request('/api/admin/screenings', { method: 'POST', actor: owner, csrf: true, body }), 201).data.id;
    expect(await request('/api/admin/screenings', { method: 'POST', actor: owner, csrf: true, body: { ...body, startsAt: future(0.5) } }), 409);
    expect(await request('/api/admin/screenings', { method: 'POST', actor: owner, csrf: true, body: { ...body, startsAt: future(1.5) } }), 409);
    nextScreeningId = expect(await request('/api/admin/screenings', { method: 'POST', actor: owner, csrf: true, body: { ...body, startsAt: future(4) } }), 201).data.id;
    expect(await request(`/api/admin/screenings/${nextScreeningId}`, { method: 'PATCH', actor: owner, csrf: true, body: { startsAt: future(0.25) } }), 409);
    seats = (await sql.execute('SELECT id FROM performance_seats WHERE performance_id = ? ORDER BY id LIMIT 8', [screeningId]))[0].map((row) => Number(row.id));
    assert(seats.length >= 8, 'Fixture has enough real room seats');
  });
  await check('Incomplete profiles cannot hold seats or book through the native API', async () => {
    const before = await countBookings();
    const required = 'Complete your profile before choosing seats or booking tickets.';
    const hold = expect(await request(`/api/screenings/${screeningId}/hold`, { method: 'POST', actor: incomplete, csrf: true, body: { seatIds: [seats[6]] } }), 403);
    assert.equal(hold.data.error, required);
    assert.equal(Number((await queryOne('SELECT COUNT(*) AS n FROM seat_holds WHERE user_id=?', [incomplete.id])).n), 0);
    // Even a pre-existing valid hold must not bypass the onboarding requirement.
    await sql.execute('INSERT INTO seat_holds (performance_seat_id,user_id,expires_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE))', [seats[6], incomplete.id]);
    const booking = expect(await request('/api/bookings', { method: 'POST', actor: incomplete, csrf: true, body: { customerPhone: '+65 8123 4567', items: [{ performanceId: screeningId, seatIds: [seats[6]] }] } }), 403);
    assert.equal(booking.data.error, required);
    assert.equal(await countBookings(), before);
    await sql.execute('DELETE FROM seat_holds WHERE user_id=?', [incomplete.id]);
  });
  await check('Hold validation, expiration and parallel requests cannot claim another customer’s seat', async () => {
    const holdPath = `/api/screenings/${screeningId}/hold`;
    const duplicate = await request(holdPath, { method: 'POST', actor: customer, csrf: true, body: { seatIds: [seats[2], seats[2]] } });
    assert([400, 409].includes(duplicate.status), 'Duplicate seat IDs must be rejected');
    expect(await request(holdPath, { method: 'POST', actor: other, csrf: true, body: { seatIds: [seats[2]] } }), 200);
    await sql.execute('UPDATE seat_holds SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE performance_seat_id=?', [seats[2]]);
    const unheldBooking = { customerPhone: '+65 8123 4567', items: [{ performanceId: screeningId, seatIds: [seats[2]] }] };
    const before = await countBookings();
    expect(await request('/api/bookings', { method: 'POST', actor: other, csrf: true, body: unheldBooking }), 409);
    assert.equal(await countBookings(), before);
    const outcomes = await Promise.all([customer, other].map((actor) => request(holdPath, { method: 'POST', actor, csrf: true, body: { seatIds: [seats[2]] } })));
    assert.deepEqual(outcomes.map((response) => response.status).sort(), [200, 409]);
    const held = await queryOne('SELECT user_id FROM seat_holds WHERE performance_seat_id=?', [seats[2]]);
    const winner = [customer, other].find((actor) => actor.id === Number(held.user_id));
    assert(winner, 'The hold belongs to exactly one authenticated customer');
    expect(await request(holdPath, { method: 'POST', actor: winner, csrf: true, body: { seatIds: [] } }), 200);
  });
  let reference, ticket;
  await check('Seat holds and booking enforce ownership, uniqueness and server prices', async () => {
    expect(await request(`/api/screenings/${screeningId}/hold`, { method: 'POST', actor: customer, csrf: true, body: { seatIds: [seats[0]] } }), 200);
    expect(await request(`/api/screenings/${screeningId}/hold`, { method: 'POST', actor: other, csrf: true, body: { seatIds: [seats[0]] } }), 409);
    const body = { customerPhone: '+65 8123 4567', customerEmail: other.email, customerName: 'Forged Owner', userId: owner.id, total: 0.01, items: [{ performanceId: screeningId, seatIds: [seats[0]], unitPrice: 0.01 }] };
    const booked = expect(await request('/api/bookings', { method: 'POST', actor: customer, csrf: true, body }), 201);
    reference = booked.data.reference || booked.data.booking?.reference || booked.data.booking?.public_reference;
    assert(reference, 'Booking response returns a reference');
    const saved = await queryOne('SELECT id,user_id,customer_email,total_amount FROM bookings WHERE public_reference = ?', [reference]);
    assert.equal(Number(saved.user_id), customer.id); assert.equal(saved.customer_email, customer.email); assert.equal(Number(saved.total_amount), 17.25);
    ticket = await queryOne('SELECT id,ticket_code FROM booking_tickets WHERE booking_id = ?', [saved.id]);
    const count = await countBookings();
    expect(await request('/api/bookings', { method: 'POST', actor: customer, csrf: true, body }), 409);
    assert.equal(await countBookings(), count);
    expect(await request(`/api/bookings/${reference}`, { actor: other }), 403);
    expect(await request(`/api/bookings/${reference}`, { actor: customer }), 200);
  });
  await check('Staff/manager tools accept a ticket once and report duplicate entry', async () => {
    expect(await request('/api/operations/ticket-lookup?q=' + reference, { actor: customer }), 403);
    for (const actor of [staff, manager]) expect(await request('/api/operations/ticket-lookup?q=' + reference, { actor }), 200);
    const first = expect(await request('/api/operations/scan-ticket', { method: 'POST', actor: staff, csrf: true, body: { code: `SVT:${ticket.ticket_code}` } }), 200);
    assert.equal(first.data.status, 'accepted');
    const duplicate = expect(await request('/api/operations/scan-ticket', { method: 'POST', actor: manager, csrf: true, body: { code: ticket.ticket_code } }), 200);
    assert.equal(duplicate.data.status, 'used');
    expect(await request(`/api/operations/tickets/${ticket.id}/check-in`, { method: 'PATCH', actor: staff, csrf: true, body: {} }), 409);
    assert.equal(Number((await queryOne('SELECT checked_in_by FROM booking_tickets WHERE id = ?', [ticket.id])).checked_in_by), staff.id);
  });
  await check('Canonical booking identity, CSRF, price and competing seat writes are preserved', async () => {
    expect(await request(`/book.php?performance_id=${screeningId}`), 401);
    const onboarding = expect(await request(`/book.php?performance_id=${screeningId}`, { actor: incomplete }), 303);
    assert(onboarding.location.endsWith('/profile.php'));
    const pages = await Promise.all([customer, other].map((actor) => request(`/book.php?performance_id=${screeningId}`, { actor })));
    const forms = pages.map((page) => ({ csrf_token: tokenFrom(expect(page, 200).text), performance_id: screeningId, 'seat_ids[]': [seats[1]], customer_name: 'Canonical Guest', customer_email: 'forged@native-integration.invalid', customer_phone: '+65 8123 4567', total_amount: '0.01', unit_price: '0.01' }));
    const before = await countBookings();
    const rejected = expect(await request('/process-booking.php', { method: 'POST', actor: customer, form: { ...forms[0], csrf_token: 'wrong' } }), 303);
    assert(rejected.location.includes('/book.php')); assert.equal(await countBookings(), before);
    const outcomes = await Promise.all([customer, other].map((actor, index) => request('/process-booking.php', { method: 'POST', actor, form: forms[index] })));
    assert.equal(outcomes.filter((response) => response.status === 303 && response.location.includes('/confirmation.php')).length, 1);
    assert.equal(await countBookings(), before + 1);
    const saved = await queryOne('SELECT b.total_amount,b.customer_email,b.user_id FROM bookings b JOIN booking_tickets bt ON bt.booking_id=b.id WHERE bt.performance_seat_id=?', [seats[1]]);
    assert.equal(Number(saved.total_amount), 17.25); assert([customer.email, other.email].includes(saved.customer_email));
    assert([customer.id, other.id].includes(Number(saved.user_id)));
    assert.equal(Number((await queryOne('SELECT seats_booked FROM performances WHERE id=?', [screeningId])).seats_booked), 2);
  });
  await check('Canonical pages and assets render with customer, staff and owner data', async () => {
    for (const path of ['/', '/movies.php', `/movie.php?slug=${movie.slug}`, '/showtimes.php', '/visit.php', '/find-booking.php', '/profile.php', '/my-bookings.php']) {
      const page = expect(await request(path, { actor: customer }), 200);
      assert(page.text.includes('<main'), `${path} has its main content`);
      assert(!/Fatal error|Uncaught |Warning:|Parse error/.test(page.text), `${path} has no PHP diagnostics`);
    }
    for (const actor of [staff, manager, owner]) {
      const page = expect(await request('/staff.php', { actor }), 200);
      assert(!page.text.includes('Some owner tools could not load.'), 'All owner widgets loaded');
      assert(page.text.includes(actor === owner ? 'Cinema owner' : 'Booking <em>desk'));
    }
    expect(await request('/attendance.php', { actor: owner }), 200);
    expect(await request('/attendance.php', { actor: staff }), 403);
    const confirmation = expect(await request('/confirmation.php?reference=' + reference, { actor: customer }), 200);
    assert(confirmation.text.includes(reference));
    const privateReceipt = expect(await request('/confirmation.php?reference=' + reference), 302);
    assert(!privateReceipt.text.includes(customer.email), 'Reference alone must not disclose personal details');
    expect(await request('/resend-email.php', { actor: customer }), 405);
    expect(await request('/resend-email.php', { method: 'POST', actor: customer, form: { csrf_token: tokenFrom(confirmation.text), reference } }), 303);
    for (const path of ['/assets/base.css', '/assets/base.js', '/assets/movies/up/poster.webp', '/qr-assets/qr-renderer.js', '/qr-assets/staff-scanner.js']) expect(await request(path), 200);
    expect(await request('/api/auth/google?client=php'), 503);
    for (const path of ['/server/src/server.js', '/php/native/shared.php', '/php/config.local.php', '/database/silver_screen_public.sql']) expect(await request(path), 404);
  });
  await check('Booked screenings require cancellation reasons and invalid tickets cannot be checked in', async () => {
    expect(await request(`/api/admin/screenings/${screeningId}`, { method: 'PATCH', actor: owner, csrf: true, body: { status: 'cancelled' } }), 400);
    expect(await request(`/api/admin/screenings/${screeningId}`, { method: 'PATCH', actor: owner, csrf: true, body: { status: 'cancelled', cancellationReason: 'Isolated integration check' } }), 200);
    expect(await request('/api/operations/scan-ticket', { method: 'POST', actor: staff, csrf: true, body: { code: ticket.ticket_code } }), 404);
    expect(await request(`/api/admin/screenings/${screeningId}`, { method: 'PATCH', actor: owner, csrf: true, body: { status: 'scheduled' } }), 200);
    expect(await request('/api/admin/screenings', { method: 'DELETE', actor: owner, csrf: true, body: { ids: [screeningId] } }), 409);
    expect(await request(`/api/admin/movies/${movieId}`, { method: 'DELETE', actor: owner, csrf: true, body: {} }), 409);
  });
  await check('Owner account changes invalidate sessions; canonical logout revokes its token', async () => {
    expect(await request(`/api/admin/accounts/${other.id}`, { method: 'PATCH', actor: owner, csrf: true, body: { accountStatus: 'disabled' } }), 200);
    expect(await request('/api/auth/me', { actor: other }), 401);
    assert.equal(Number((await queryOne('SELECT COUNT(*) AS n FROM sessions WHERE user_id=?', [other.id])).n), 0);
    const page = expect(await request('/profile.php', { actor: customer }), 200);
    expect(await request('/logout.php', { method: 'POST', actor: customer, form: { csrf_token: 'wrong' } }), 403);
    expect(await request('/api/auth/me', { actor: customer }), 200);
    expect(await request('/logout.php', { method: 'POST', actor: customer, form: { csrf_token: tokenFrom(page.text) } }), 303);
    expect(await request('/api/auth/me', { cookies: new Map([['silver_session', customer.token]]) }), 401);
  });
}

try {
  await boot();
  if (previewMode) {
    const gate = expect(await request('/__qa'), 200);
    assert(gate.text.includes('Local browser test fixtures'));
    const counts = await queryOne("SELECT (SELECT COUNT(*) FROM shows WHERE status='published') AS movies, (SELECT COUNT(*) FROM performances WHERE status='scheduled' AND starts_at>CURRENT_TIMESTAMP) AS screenings");
    console.log(`PREVIEW ${origin}/__qa`);
    console.log(`PREVIEW Public programme retained: ${counts.movies} published films; ${counts.screenings} future screenings.`);
    console.log(`PREVIEW Process ${process.pid}; stop with SIGTERM or Ctrl+C to remove only this preview schema and PHP workers.`);
    await new Promise((resolve, reject) => {
      php.once('exit', () => stopping ? resolve() : reject(new Error('The local PHP preview stopped unexpectedly.')));
      php.once('error', reject);
    });
  } else {
    await runChecks();
    console.log(`PASS ${completed} native integration groups. Google consent, email delivery, HTTPS and hosting rewrites still require hosted browser verification.`);
  }
} catch (error) {
  console.error(`FAIL after ${completed} groups: ${error.message}`);
  // Only report diagnostics classification, never provider credentials or full responses.
  if (/Fatal error|Parse error|Uncaught/.test(phpLog)) console.error('The PHP worker reported an exception; inspect the adapter locally.');
  process.exitCode = 1;
} finally {
  await cleanup();
}
