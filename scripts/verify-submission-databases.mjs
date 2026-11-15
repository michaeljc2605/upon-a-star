import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';

const repositoryRoot = fileURLToPath(new URL('../', import.meta.url));
dotenv.config({ path: join(repositoryRoot, 'server/.env'), quiet: true });

const sourceDatabases = ['silver_screen', 'silver_screen_base'];
const publicTables = ['rooms', 'room_seats', 'shows', 'performances', 'performance_seats'];
const privateTables = new Set([
  'users', 'sessions', 'audit_events', 'bookings', 'booking_items',
  'booking_tickets', 'payment_events', 'seat_holds', 'email_deliveries',
]);
const configuration = {
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
};
const identifier = (name) => '`' + name.replaceAll('`', '``') + '`';
const xamppMysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
const mysqlCommand = process.env.MYSQL_BIN || (existsSync(xamppMysql) ? xamppMysql : 'mysql');

// Only the explicitly created temporary database may receive imported tables.
// Remove the export's CREATE DATABASE statement instead of letting it create
// or select either live database. Reject any unexpected database directives.
function prepareTemporaryImport(dump, sourceDatabase, temporaryDatabase) {
  let creates = 0;
  let uses = 0;
  const rewritten = dump.split('\n').map((line) => {
    if (/^\s*CREATE\s+DATABASE\b/i.test(line)) {
      assert.match(line, new RegExp('^CREATE DATABASE IF NOT EXISTS `' + sourceDatabase
        + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\\s*$'), 'Unexpected CREATE DATABASE statement.');
      creates += 1;
      return '-- Temporary database was created by the verification script.';
    }
    if (/^\s*USE\b/i.test(line)) {
      assert.equal(line.trim(), 'USE ' + identifier(sourceDatabase) + ';', 'Unexpected USE statement.');
      uses += 1;
      return 'USE ' + identifier(temporaryDatabase) + ';';
    }
    assert.ok(!/^\s*(?:DROP|ALTER)\s+DATABASE\b/i.test(line), 'Unexpected database mutation in dump.');
    // The public exporter emits unqualified table names. Explicit references to
    // a live schema are refused rather than silently targeting live tables.
    for (const liveDatabase of sourceDatabases) {
      assert.ok(!line.includes(identifier(liveDatabase) + '.'), 'Dump contains a qualified live database reference.');
    }
    return line;
  }).join('\n');
  assert.equal(creates, 1, 'Expected exactly one CREATE DATABASE statement.');
  assert.equal(uses, 1, 'Expected exactly one USE statement.');
  return rewritten;
}

function importDump(sql, temporaryDatabase) {
  return new Promise((resolveImport, rejectImport) => {
    const child = spawn(mysqlCommand, [
      '--host=' + configuration.host,
      '--port=' + configuration.port,
      '--user=' + configuration.user,
      '--protocol=TCP',
      '--default-character-set=utf8mb4',
      '--binary-mode',
      '--database=' + temporaryDatabase,
    ], {
      env: { ...process.env, MYSQL_PWD: configuration.password },
      stdio: ['pipe', 'ignore', 'pipe'],
    });
    let standardError = '';
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (chunk) => { standardError += chunk; });
    child.on('error', rejectImport);
    // mysql may close stdin early after a SQL error. Report the child's SQL
    // failure instead of allowing an unhandled EPIPE to interrupt cleanup.
    child.stdin.on('error', (error) => {
      if (error.code !== 'EPIPE') rejectImport(error);
    });
    child.on('close', (code) => {
      if (code === 0) resolveImport();
      else rejectImport(new Error('Temporary SQL import failed (exit ' + code + '): ' + standardError.trim()));
    });
    child.stdin.end(sql);
  });
}

async function tableNames(connection, database) {
  const [rows] = await connection.execute(`SELECT TABLE_NAME AS name
    FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME`, [database]);
  return rows.map((row) => row.name);
}

async function tableSchema(connection, database, table) {
  const [[row]] = await connection.query('SHOW CREATE TABLE ??.??', [database, table]);
  // Export sanitization can reset private auto-increment counters. Their next
  // numeric value is state, not a difference in the table definition.
  return row['Create Table'].replace(/\bAUTO_INCREMENT=\d+\s*/g, '')
    .replaceAll(identifier(database) + '.', '`SCHEMA`.');
}

async function rowCount(connection, database, table) {
  const [[row]] = await connection.query('SELECT COUNT(*) AS total FROM ??.??', [database, table]);
  return Number(row.total);
}

async function verifyForeignKeys(connection, database) {
  const [rows] = await connection.execute(`SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
    REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION`, [database]);
  const constraints = new Map();
  for (const row of rows) {
    assert.equal(row.REFERENCED_TABLE_SCHEMA, database, 'An imported foreign key targets another database.');
    const key = row.TABLE_NAME + ':' + row.CONSTRAINT_NAME;
    if (!constraints.has(key)) constraints.set(key, []);
    constraints.get(key).push(row);
  }
  for (const [key, columns] of constraints) {
    const childTable = identifier(database) + '.' + identifier(columns[0].TABLE_NAME);
    const parentTable = identifier(database) + '.' + identifier(columns[0].REFERENCED_TABLE_NAME);
    const notNull = columns.map((column) => 'child.' + identifier(column.COLUMN_NAME) + ' IS NOT NULL').join(' AND ');
    const match = columns.map((column) => 'parent.' + identifier(column.REFERENCED_COLUMN_NAME)
      + ' = child.' + identifier(column.COLUMN_NAME)).join(' AND ');
    const [[result]] = await connection.query(`SELECT COUNT(*) AS total FROM ${childTable} child
      WHERE ${notNull} AND NOT EXISTS (SELECT 1 FROM ${parentTable} parent WHERE ${match})`);
    assert.equal(Number(result.total), 0, 'Foreign-key orphan records found for ' + key + '.');
  }
  return constraints.size;
}

async function verifyDatabase(connection, sourceDatabase, temporaryDatabase) {
  const sourceTables = await tableNames(connection, sourceDatabase);
  const importedTables = await tableNames(connection, temporaryDatabase);
  assert.deepEqual(importedTables, sourceTables, sourceDatabase + ': imported table list differs from live schema.');
  const counts = {};
  for (const table of sourceTables) {
    assert.equal(await tableSchema(connection, temporaryDatabase, table),
      await tableSchema(connection, sourceDatabase, table), table + ': imported table definition differs.');
    counts[table] = await rowCount(connection, temporaryDatabase, table);
    if (publicTables.includes(table)) {
      assert.equal(counts[table], await rowCount(connection, sourceDatabase, table), table + ': row count differs from live database.');
    } else {
      assert.ok(privateTables.has(table), 'Unclassified table in submission: ' + table + '.');
      assert.equal(counts[table], 0, table + ': private records were included.');
    }
  }
  for (const table of publicTables) assert.ok(sourceTables.includes(table), 'Missing public table: ' + table + '.');
  const [[triggers]] = await connection.execute(`SELECT
    (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?) AS source_total,
    (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?) AS imported_total`,
  [sourceDatabase, temporaryDatabase]);
  assert.equal(Number(triggers.imported_total), Number(triggers.source_total), 'Trigger counts differ.');

  await connection.query('USE ??', [temporaryDatabase]);
  const [[booked]] = await connection.query('SELECT COUNT(*) AS total FROM performances WHERE seats_booked <> 0');
  assert.equal(Number(booked.total), 0, 'Imported occupancy counters must be zero when reservations are excluded.');
  const [[seatMaps]] = await connection.query(`SELECT COUNT(*) AS total FROM (
    SELECT p.id FROM performances p LEFT JOIN performance_seats ps ON ps.performance_id = p.id
    GROUP BY p.id, p.capacity HAVING COUNT(ps.id) <> p.capacity
  ) invalid_seat_maps`);
  assert.equal(Number(seatMaps.total), 0, 'Imported screening seat maps do not match capacity.');
  const foreignKeysChecked = await verifyForeignKeys(connection, temporaryDatabase);
  const [[conflicts]] = await connection.query(`SELECT COUNT(*) AS total
    FROM performances first_screening JOIN performances second_screening
      ON first_screening.id < second_screening.id
      AND first_screening.room_id = second_screening.room_id
      AND first_screening.status = 'scheduled' AND second_screening.status = 'scheduled'
      AND first_screening.history_deleted_at IS NULL AND second_screening.history_deleted_at IS NULL
    JOIN shows first_movie ON first_movie.id = first_screening.show_id
    JOIN shows second_movie ON second_movie.id = second_screening.show_id
    WHERE first_screening.starts_at < TIMESTAMPADD(MINUTE, second_movie.duration_minutes + 60, second_screening.starts_at)
      AND second_screening.starts_at < TIMESTAMPADD(MINUTE, first_movie.duration_minutes + 60, first_screening.starts_at)`);
  assert.equal(Number(conflicts.total), 0, 'Imported schedule has room timing clashes including the 60-minute buffer.');
  const [[dates]] = await connection.query(`SELECT
    DATE_FORMAT(MIN(starts_at), '%Y-%m-%d %H:%i:%s') AS firstScreening,
    DATE_FORMAT(MAX(starts_at), '%Y-%m-%d %H:%i:%s') AS lastScreening
    FROM performances`);
  return {
    database: sourceDatabase,
    tableSchemasMatched: sourceTables.length,
    publicRowCounts: Object.fromEntries(publicTables.map((table) => [table, counts[table]])),
    privateTablesEmpty: sourceTables.filter((table) => privateTables.has(table)).length,
    privateRows: 0,
    occupancyCounters: 0,
    invalidSeatMaps: 0,
    foreignKeysChecked,
    foreignKeyOrphans: 0,
    triggersMatched: Number(triggers.imported_total),
    roomConflicts: 0,
    ...dates,
  };
}

const connection = await mysql.createConnection(configuration);
const createdDatabases = new Set();
const results = [];
try {
  for (const sourceDatabase of sourceDatabases) {
    const temporaryDatabase = 'sv_submission_verify_' + process.pid + '_' + randomBytes(8).toString('hex');
    assert.match(temporaryDatabase, /^sv_submission_verify_\d+_[a-f0-9]{16}$/);
    assert.ok(!sourceDatabases.includes(temporaryDatabase));
    const dump = await readFile(join(repositoryRoot, 'database/submission', sourceDatabase + '.sql'), 'utf8');
    const sql = prepareTemporaryImport(dump, sourceDatabase, temporaryDatabase);
    // No IF NOT EXISTS: a collision must fail without marking an existing DB
    // as ours to drop. Only successful CREATE statements enter this set.
    await connection.query('CREATE DATABASE ?? CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', [temporaryDatabase]);
    createdDatabases.add(temporaryDatabase);
    await importDump(sql, temporaryDatabase);
    results.push(await verifyDatabase(connection, sourceDatabase, temporaryDatabase));
  }
} finally {
  const cleanupErrors = [];
  for (const temporaryDatabase of createdDatabases) {
    try {
      assert.match(temporaryDatabase, /^sv_submission_verify_\d+_[a-f0-9]{16}$/);
      assert.ok(!sourceDatabases.includes(temporaryDatabase));
      await connection.query('DROP DATABASE ??', [temporaryDatabase]);
    } catch (error) {
      cleanupErrors.push(error);
    }
  }
  await connection.end();
  if (cleanupErrors.length) throw new AggregateError(cleanupErrors, 'Could not remove all submission verification databases.');
}

console.log(JSON.stringify({ verified: true, temporaryDatabasesRemoved: true, databases: results }, null, 2));
