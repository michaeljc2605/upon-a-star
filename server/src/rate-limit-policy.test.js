import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import express from 'express';
import cookieParser from 'cookie-parser';
import rateLimit from 'express-rate-limit';
import { hasPlausibleSessionToken, isLoopbackSessionRead, loopbackSessionKey } from './rate-limit-policy.js';

const token = 'A'.repeat(43);
const request = (path, remoteAddress = '127.0.0.1', method = 'GET', session = token) => ({
  path,
  method,
  socket: { remoteAddress },
  cookies: session ? { silver_session: session } : {},
  headers: {},
});

test('only local session reads get an individual rate-limit bucket', () => {
  assert.equal(isLoopbackSessionRead(request('/auth/me')), true);
  assert.equal(isLoopbackSessionRead(request('/tickets', '::ffff:127.0.0.1')), true);
  assert.equal(isLoopbackSessionRead(request('/tickets', '::1')), true);
  assert.equal(isLoopbackSessionRead(request('/auth/me', '198.51.100.7')), false);
  assert.equal(isLoopbackSessionRead(request('/auth/me', '127.0.0.1', 'POST')), false);
  assert.equal(isLoopbackSessionRead(request('/auth/google')), false);
  assert.equal(isLoopbackSessionRead(request('/auth/me', '127.0.0.1', 'GET', null)), false);
  assert.equal(hasPlausibleSessionToken(request('/auth/me', '127.0.0.1', 'GET', 'short')), false);
});

test('forwarded requests retain the public limit even when they claim loopback', () => {
  for (const headers of [
    { 'x-forwarded-for': '198.51.100.7' },
    { 'x-forwarded-for': '127.0.0.1' },
    { 'x-forwarded-for': '' },
    { 'x-forwarded-for': ['198.51.100.7', '127.0.0.1'] },
    { 'X-Forwarded-For': '::1' },
    { forwarded: 'for=127.0.0.1;proto=https' },
    { 'x-forwarded-proto': 'https' },
    { 'x-real-ip': '198.51.100.7' },
  ]) {
    for (const path of ['/auth/me', '/tickets']) {
      assert.equal(isLoopbackSessionRead({ ...request(path), headers }), false);
    }
  }
});

test('public proxy requests cannot reset their IP budget by rotating session cookies', async (t) => {
  const app = express();
  app.set('trust proxy', 1);
  app.use(cookieParser());
  app.use('/api', rateLimit({
    windowMs: 60_000,
    limit: 2,
    standardHeaders: false,
    legacyHeaders: false,
    skip: isLoopbackSessionRead,
  }));
  app.get(['/api/auth/me', '/api/tickets'], (_request, response) => response.sendStatus(204));
  const server = createServer(app);
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  t.after(async () => {
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
  });
  const origin = `http://127.0.0.1:${server.address().port}`;
  const statuses = [];
  for (const [index, path] of ['/auth/me', '/tickets', '/auth/me'].entries()) {
    const response = await fetch(`${origin}/api${path}`, {
      headers: {
        'X-Forwarded-For': '198.51.100.7',
        Cookie: `silver_session=${String.fromCharCode(65 + index).repeat(43)}`,
      },
    });
    statuses.push(response.status);
    await response.text();
  }
  assert.deepEqual(statuses, [204, 204, 429]);
});

test('local read buckets do not expose or combine session tokens', () => {
  const first = loopbackSessionKey(request('/auth/me'));
  const second = loopbackSessionKey(request('/auth/me', '127.0.0.1', 'GET', 'B'.repeat(43)));
  assert.match(first, /^[a-f0-9]{64}$/);
  assert.notEqual(first, token);
  assert.notEqual(first, second);
});
