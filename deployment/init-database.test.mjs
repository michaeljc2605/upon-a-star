import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { REQUIRED_TABLES, initializeDatabase, prepareSnapshot, runInitialization } from './init-database.mjs';

const snapshot = await readFile(new URL('../database/submission/silver_screen.sql', import.meta.url), 'utf8');
const complete = REQUIRED_TABLES.map((name) => ({ name, type: 'BASE TABLE' }));

function fakeConnection(states, version = '8.0.41') {
  const queries = [];
  let count = 0;
  return {
    queries,
    async execute(sql, parameters) {
      queries.push({ sql, parameters });
      return [states[Math.min(count++, states.length - 1)]];
    },
    async query(sql) {
      queries.push({ sql });
      if (sql.startsWith('SELECT VERSION()')) return [[{ version }]];
      if (sql === 'SELECT @@GLOBAL.tidb_enable_check_constraint AS enabled') return [[{ enabled: this.checksEnabled ? 1 : 0 }]];
      if (sql === 'SET GLOBAL tidb_enable_check_constraint = ON') this.checksEnabled = true;
      return [[]];
    },
    async end() { this.closed = true; },
  };
}

test('renames only database directives and removes destructive table drops', () => {
  const literal = "\nINSERT INTO `shows` (`title`) VALUES ('silver_screen;\nDROP TABLE IF EXISTS `users`;\nUSE `silver_screen`;');\n";
  const output = prepareSnapshot(snapshot + literal, 'cinema_live');
  assert.match(output, /CREATE DATABASE IF NOT EXISTS `cinema_live`/);
  assert.match(output, /USE `cinema_live`;/);
  assert.ok(output.endsWith(literal));
  assert.equal(output.slice(0, -literal.length).includes('DROP TABLE'), false);
});

test('invalid database identifiers and unexpected directives fail before import', () => {
  for (const name of ['bad-name', 'a`b', 'a;DROP', '', 'a'.repeat(65)]) {
    assert.throws(() => prepareSnapshot(snapshot, name), /MYSQL_DATABASE/);
  }
  assert.throws(() => prepareSnapshot(snapshot + '\nUSE `other_database`;\n', 'cinema'), /unexpected/);
});

test('a populated valid application database is never reimported', async () => {
  const connection = fakeConnection([complete]);
  const result = await initializeDatabase({ connection, database: 'cinema', snapshot });
  assert.deepEqual(result, { initialized: false, reason: 'existing-schema' });
  assert.equal(connection.queries.length, 1);
});

test('any preexisting table or view with an incomplete schema blocks all writes', async () => {
  for (const rows of [[{ name: 'unrelated', type: 'BASE TABLE' }], [{ name: 'users', type: 'VIEW' }], complete.slice(1)]) {
    const connection = fakeConnection([rows]);
    await assert.rejects(initializeDatabase({ connection, database: 'cinema', snapshot }), /not empty/);
    assert.equal(connection.queries.length, 1);
  }
});

test('empty TiDB target enables checks before the safe import and verifies tables', async () => {
  const connection = fakeConnection([[], [], complete], '8.0.11-TiDB-v8.5.0');
  assert.deepEqual(await initializeDatabase({ connection, database: 'cinema', snapshot }), { initialized: true });
  const queries = connection.queries.map(({ sql }) => sql);
  const enable = queries.indexOf('SET GLOBAL tidb_enable_check_constraint = ON');
  const importIndex = queries.findIndex((sql) => sql.includes('CREATE DATABASE'));
  assert.ok(enable >= 0 && enable < importIndex);
  assert.equal(queries[importIndex].includes('DROP TABLE'), false);
  assert.equal(connection.queries[0].parameters[0], 'cinema');
});

test('TiDB constraint configuration failure prevents application DDL', async () => {
  const connection = fakeConnection([[]], '8.0.11-TiDB-v8.5.0');
  const originalQuery = connection.query.bind(connection);
  connection.query = async (sql) => {
    if (sql === 'SET GLOBAL tidb_enable_check_constraint = ON') throw new Error('private database error');
    return originalQuery(sql);
  };
  await assert.rejects(initializeDatabase({ connection, database: 'cinema', snapshot }), /No application tables were imported/);
  assert.equal(connection.queries.some(({ sql }) => sql.includes('CREATE DATABASE')), false);
});

test('another initializer creating a table before import blocks this import', async () => {
  const connection = fakeConnection([[], complete]);
  await assert.rejects(initializeDatabase({ connection, database: 'cinema', snapshot }), /became non-empty/);
  assert.equal(connection.queries.some(({ sql }) => sql.includes('CREATE DATABASE')), false);
});

test('partially failed imports report safe recovery instructions without driver values', async () => {
  const connection = fakeConnection([[], []]);
  const originalQuery = connection.query.bind(connection);
  connection.query = async (sql) => {
    if (sql.includes('CREATE DATABASE')) throw new Error('private driver diagnostic');
    return originalQuery(sql);
  };
  await assert.rejects(initializeDatabase({ connection, database: 'cinema', snapshot }), (error) => {
    assert.match(error.message, /partial schema/);
    assert.equal(error.message.includes('private driver diagnostic'), false);
    return true;
  });
});

test('initialization requires explicit opt-in and closes the scoped connection', async () => {
  for (const flag of [undefined, 'false', 'TRUE']) {
    const result = await runInitialization({ env: { INITIALIZE_EMPTY_DATABASE: flag }, connect: () => { throw new Error('must not connect'); }, log: () => {} });
    assert.equal(result.reason, 'disabled');
  }
  const connection = fakeConnection([complete]);
  let configured;
  await runInitialization({
    env: { INITIALIZE_EMPTY_DATABASE: 'true' },
    configure: (env) => ({ database: env.MYSQL_DATABASE, connectionLimit: 10, waitForConnections: true, ssl: { rejectUnauthorized: true } }),
    connect: async (options) => { configured = options; return connection; },
    loadSnapshot: async () => snapshot,
    log: () => {},
  });
  assert.equal(configured.database, undefined);
  assert.equal(configured.connectionLimit, undefined);
  assert.equal(configured.waitForConnections, undefined);
  assert.equal(configured.multipleStatements, true);
  assert.equal(configured.ssl.rejectUnauthorized, true);
  assert.equal(connection.closed, true);
});
