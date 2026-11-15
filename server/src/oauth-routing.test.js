import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

async function unusedPort() {
  const probe = createServer();
  await new Promise((resolve, reject) => {
    probe.once('error', reject);
    probe.listen(0, '127.0.0.1', resolve);
  });
  const { port } = probe.address();
  await new Promise((resolve) => probe.close(resolve));
  return port;
}

async function waitUntilHealthy(url, child, getErrors) {
  for (let attempt = 0; attempt < 60; attempt += 1) {
    if (child.exitCode !== null) throw new Error(`Test API exited early: ${getErrors()}`);
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(500) });
      if (response.ok) return;
    } catch { /* The child is still starting. */ }
    await delay(100);
  }
  throw new Error(`Test API did not start: ${getErrors()}`);
}

function flowCookies(response) {
  const cookies = response.headers.getSetCookie();
  const byName = new Map(cookies.map((cookie) => [cookie.split('=')[0], cookie]));
  for (const name of ['silver_google_state', 'silver_google_client']) {
    const cookie = byName.get(name);
    assert.ok(cookie, `${name} cookie is set`);
    assert.match(cookie, /; Path=\/api\/auth\/google/i);
    assert.match(cookie, /; HttpOnly/i);
    assert.match(cookie, /; SameSite=Lax/i);
    const age = Number(cookie.match(/; Max-Age=(\d+)/i)?.[1]);
    assert.ok(age > 0 && age <= 600, `${name} cookie expires within ten minutes`);
  }
  return byName;
}

test('Google OAuth accepts only fixed React and PHP return clients', async (t) => {
  const port = await unusedPort();
  const base = `http://127.0.0.1:${port}`;
  let errors = '';
  const child = spawn(process.execPath, ['src/server.js'], {
    cwd: new URL('..', import.meta.url),
    env: {
      ...process.env,
      NODE_ENV: 'development',
      PORT: String(port),
      CLIENT_ORIGIN: 'http://localhost:5173',
      PHP_CLIENT_ORIGIN: 'http://localhost/SilverVillage',
      GOOGLE_CLIENT_ID: 'oauth-routing-test',
      GOOGLE_CLIENT_SECRET: 'oauth-routing-test',
      GOOGLE_REDIRECT_URI: `${base}/api/auth/google/callback`,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  child.stderr.on('data', (chunk) => { errors += chunk.toString(); });
  t.after(async () => {
    if (child.exitCode !== null) return;
    child.kill('SIGTERM');
    await Promise.race([
      new Promise((resolve) => child.once('exit', resolve)),
      delay(2_000).then(() => { if (child.exitCode === null) child.kill('SIGKILL'); }),
    ]);
  });
  await waitUntilHealthy(`${base}/api/health`, child, () => errors);

  const phpStart = await fetch(`${base}/api/auth/google?client=php&return_to=https://attacker.example/`, { redirect: 'manual' });
  assert.equal(phpStart.status, 302);
  const googleLocation = new URL(phpStart.headers.get('location'));
  assert.equal(googleLocation.origin, 'https://accounts.google.com');
  assert.equal(googleLocation.searchParams.get('client_id'), 'oauth-routing-test');
  assert.equal(googleLocation.searchParams.get('redirect_uri'), `${base}/api/auth/google/callback`);
  assert.ok(googleLocation.searchParams.get('state'));
  assert.ok(!googleLocation.href.includes('attacker.example'));
  const phpCookies = flowCookies(phpStart);
  assert.match(phpCookies.get('silver_google_client'), /^silver_google_client=php;/);
  assert.equal(phpCookies.get('silver_google_state').split(';')[0].split('=')[1], googleLocation.searchParams.get('state'));

  const defaultStart = await fetch(`${base}/api/auth/google`, { redirect: 'manual' });
  assert.equal(defaultStart.status, 302);
  assert.match(flowCookies(defaultStart).get('silver_google_client'), /^silver_google_client=react;/);

  const invalidClient = await fetch(`${base}/api/auth/google?client=https%3A%2F%2Fattacker.example`, { redirect: 'manual' });
  assert.equal(invalidClient.status, 400);
  assert.equal(invalidClient.headers.get('location'), null);
  const ambiguousClient = await fetch(`${base}/api/auth/google?client=php&client=react`, { redirect: 'manual' });
  assert.equal(ambiguousClient.status, 400);

  const cookieHeader = [...phpCookies.values()].map((cookie) => cookie.split(';')[0]).join('; ');
  const rejectedCallback = await fetch(`${base}/api/auth/google/callback?state=wrong&client=php&redirect=https%3A%2F%2Fattacker.example`, {
    headers: { Cookie: cookieHeader },
    redirect: 'manual',
  });
  assert.equal(rejectedCallback.status, 400);
  assert.equal(rejectedCallback.headers.get('location'), null);
  const cleared = rejectedCallback.headers.getSetCookie();
  const expiresImmediately = (cookie) => /Max-Age=0|Expires=.*1970/i.test(cookie);
  assert.ok(cleared.some((cookie) => /^silver_google_state=;/.test(cookie) && expiresImmediately(cookie)));
  assert.ok(cleared.some((cookie) => /^silver_google_client=;/.test(cookie) && expiresImmediately(cookie)));

  // Account reads should not consume the 40-request Google sign-in budget.
  for (let i = 0; i < 45; i += 1) {
    const accountRead = await fetch(`${base}/api/auth/me`, { redirect: 'manual' });
    assert.equal(accountRead.status, 401);
  }
  const afterAccountReads = await fetch(`${base}/api/auth/google`, { redirect: 'manual' });
  assert.equal(afterAccountReads.status, 302);

  // Google start and callback share a separate, still-active 40-request limit.
  // Five Google requests above plus the one after account reads used six slots.
  for (let i = 0; i < 34; i += 1) {
    const start = await fetch(`${base}/api/auth/google`, { redirect: 'manual' });
    assert.equal(start.status, 302);
  }
  const throttled = await fetch(`${base}/api/auth/google`, { redirect: 'manual' });
  assert.equal(throttled.status, 429);
});
