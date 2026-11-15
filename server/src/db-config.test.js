import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { buildDbConfig, initializeDbConnection } from './db-config.js';

test('local database connections remain opt-in for TLS and preserve connection values', () => {
  const config = buildDbConfig({ MYSQL_HOST: '127.0.0.1', MYSQL_DATABASE: 'silver_screen', MYSQL_USER: 'local', MYSQL_PASSWORD: 'fixture-only' });
  assert.equal(config.ssl, undefined);
  assert.equal(config.host, '127.0.0.1');
  assert.equal(config.port, 3306);
  assert.equal(config.database, 'silver_screen');
  assert.equal(config.password, 'fixture-only');
  assert.equal(config.timezone, '+08:00');
  assert.ok(config.maxIdle < config.connectionLimit);
  assert.ok(config.idleTimeout < 340_000);
});

test('hosted TLS verifies both the certificate chain and server identity', () => {
  const config = buildDbConfig({ MYSQL_SSL: 'true', MYSQL_PORT: '4000' });
  assert.equal(config.ssl.rejectUnauthorized, true);
  assert.equal(config.ssl.verifyIdentity, true);
  assert.equal(config.ssl.minVersion, 'TLSv1.2');
  assert.equal(config.ssl.ca, undefined, 'Node trusted public roots are available without a custom bundle');
  assert.equal(config.port, 4000);
});

test('custom CA bundle is loaded and missing bundle fails without a cleartext fallback', () => {
  const directory = mkdtempSync(join(tmpdir(), 'upon-a-star-ca-'));
  try {
    const file = join(directory, 'fixture-ca.pem');
    writeFileSync(file, 'fixture certificate bytes');
    const config = buildDbConfig({ MYSQL_SSL: 'true', MYSQL_SSL_CA: file });
    assert.equal(config.ssl.ca.toString(), 'fixture certificate bytes');
    assert.equal(config.ssl.verifyIdentity, true);
    assert.throws(() => buildDbConfig({ MYSQL_SSL: 'true', MYSQL_SSL_CA: join(directory, 'missing.pem') }), /readable CA/);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('misspelled TLS flags and invalid database ports fail before connecting', () => {
  for (const value of ['tru', 'enabled', 'verify-none']) assert.throws(() => buildDbConfig({ MYSQL_SSL: value }), /MYSQL_SSL/);
  for (const value of ['0', '-1', '65536', '4000.5', 'port']) assert.throws(() => buildDbConfig({ MYSQL_PORT: value }), /MYSQL_PORT/);
  for (const value of ['', 'false', '0']) assert.equal(buildDbConfig({ MYSQL_SSL: value }).ssl, undefined);
  for (const value of ['true', 'TRUE', '1']) assert.equal(buildDbConfig({ MYSQL_SSL: value }).ssl.rejectUnauthorized, true);
});

test('new database connections initialize Singapore time and discard failed initialization', () => {
  const calls = [];
  const connection = { query(sql, callback) { calls.push(sql); callback(null); }, destroy() { calls.push('destroy'); } };
  initializeDbConnection(connection);
  assert.deepEqual(calls, ["SET time_zone = '+08:00'"]);
  const failed = { query(sql, callback) { calls.push(sql); callback(new Error('Time zone rejected')); }, destroy() { calls.push('destroy'); } };
  initializeDbConnection(failed);
  assert.equal(calls.at(-1), 'destroy');
});
