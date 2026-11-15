import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import express from 'express';
import cookieParser from 'cookie-parser';
import rateLimit from 'express-rate-limit';
import { buildProxyTrust } from './proxy-policy.js';
import { isLoopbackSessionRead } from './rate-limit-policy.js';

test('the default trusts only a direct Apache loopback hop', () => {
  const trust = buildProxyTrust({});
  assert.equal(trust('127.0.0.1', 0), true);
  assert.equal(trust('::1', 0), true);
  assert.equal(trust('::ffff:127.0.0.1', 0), true);
  assert.equal(trust('198.51.100.7', 0), false);
  assert.equal(trust('172.30.25.2', 1), false);
  assert.equal(trust('127.0.0.1', 1), false);
});

test('Oracle trust accepts the configured Caddy address only at its expected hop', () => {
  const trust = buildProxyTrust({ TRUSTED_PROXY_IPS: '172.30.25.2' });
  assert.equal(trust('172.30.25.2', 1), true);
  assert.equal(trust('::ffff:172.30.25.2', 1), true);
  assert.equal(trust('172.30.25.2', 0), false);
  assert.equal(trust('172.30.25.2', 2), false);
  assert.equal(trust('172.30.25.99', 1), false);
  for (const value of ['true', '2', '*', '0.0.0.0/0', '172.30.25.0/24', 'caddy']) {
    assert.throws(() => buildProxyTrust({ TRUSTED_PROXY_IPS: value }), /explicit IP/);
  }
});

async function startApp(t, handler) {
  const app = express();
  app.set('trust proxy', buildProxyTrust({ TRUSTED_PROXY_IPS: '172.30.25.2' }));
  handler(app);
  const server = createServer(app);
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  t.after(async () => {
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
  });
  return `http://127.0.0.1:${server.address().port}`;
}

test('Express resolves the real client through Caddy and rejects spoofed longer/shorter paths', async (t) => {
  const origin = await startApp(t, (app) => app.get('/ip', (request, response) => response.json({ ip: request.ip })));
  for (const [forwarded, expected] of [
    ['198.51.100.7, 172.30.25.2', '198.51.100.7'],
    ['203.0.113.99, 198.51.100.7, 172.30.25.2', '198.51.100.7'],
    ['203.0.113.99, 198.51.100.7', '198.51.100.7'],
    ['198.51.100.7, 172.30.25.99', '172.30.25.99'],
  ]) {
    const response = await fetch(`${origin}/ip`, { headers: { 'X-Forwarded-For': forwarded } });
    assert.equal((await response.json()).ip, expected);
  }
});

test('two-proxy traffic keeps per-client IP limits despite rotating tokens and spoofed prefixes', async (t) => {
  const origin = await startApp(t, (app) => {
    app.use(cookieParser());
    app.use('/api', rateLimit({ windowMs: 60_000, limit: 2, standardHeaders: false, legacyHeaders: false, skip: isLoopbackSessionRead }));
    app.get('/api/auth/me', (_request, response) => response.sendStatus(204));
  });
  const statuses = [];
  for (let index = 0; index < 3; index += 1) {
    const response = await fetch(`${origin}/api/auth/me`, {
      headers: {
        'X-Forwarded-For': `203.0.113.${index + 1}, 198.51.100.7, 172.30.25.2`,
        Cookie: `silver_session=${String.fromCharCode(65 + index).repeat(43)}`,
      },
    });
    statuses.push(response.status);
    await response.text();
  }
  assert.deepEqual(statuses, [204, 204, 429]);
  const otherClient = await fetch(`${origin}/api/auth/me`, {
    headers: { 'X-Forwarded-For': '198.51.100.8, 172.30.25.2', Cookie: `silver_session=${'Z'.repeat(43)}` },
  });
  assert.equal(otherClient.status, 204);
  await otherClient.text();
});
