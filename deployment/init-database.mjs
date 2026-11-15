import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildDbConfig } from '../server/src/db-config.js';

export const REQUIRED_TABLES = Object.freeze([
  'audit_events', 'booking_items', 'booking_tickets', 'bookings',
  'email_deliveries', 'payment_events', 'performance_seats', 'performances',
  'room_seats', 'rooms', 'seat_holds', 'sessions', 'shows', 'users',
]);
const snapshotUrl = new URL('../database/submission/silver_screen.sql', import.meta.url);

export class InitializationError extends Error {
  constructor(message, options) { super(message, options); this.name = 'InitializationError'; }
}

export function validateDatabaseName(value) {
  if (typeof value !== 'string' || !/^[A-Za-z0-9_]{1,64}$/.test(value)) {
    throw new InitializationError('MYSQL_DATABASE must contain 1–64 letters, digits or underscores.');
  }
  return value;
}

// Preserve every literal and comment while recognizing only top-level statements.
// The bundled snapshot uses no stored procedures or DELIMITER directives.
function splitStatements(sql) {
  const statements = [];
  let start = 0;
  let state = '';
  for (let i = 0; i < sql.length; i += 1) {
    const character = sql[i];
    const next = sql[i + 1];
    if (state === 'line') { if (character === '\n') state = ''; continue; }
    if (state === 'block') { if (character === '*' && next === '/') { state = ''; i += 1; } continue; }
    if (state) {
      if (character === '\\' && state !== '`') { i += 1; continue; }
      if (character === state) {
        if (next === state) i += 1;
        else state = '';
      }
      continue;
    }
    if (character === "'" || character === '"' || character === '`') { state = character; continue; }
    if (character === '#' || (character === '-' && next === '-' && /\s/.test(sql[i + 2] || ''))) { state = 'line'; continue; }
    if (character === '/' && next === '*') { state = 'block'; i += 1; continue; }
    if (character === ';') { statements.push(sql.slice(start, i + 1)); start = i + 1; }
  }
  if (state && state !== 'line') throw new InitializationError('The bundled database snapshot has an unterminated SQL literal or comment.');
  statements.push(sql.slice(start));
  return statements;
}

export function prepareSnapshot(sql, database) {
  validateDatabaseName(database);
  let creates = 0;
  let uses = 0;
  const droppedTables = new Set();
  const rewritten = splitStatements(sql).map((part) => {
    const prefix = part.match(/^(?:(?:\s+)|(?:--[^\r\n]*(?:\r?\n|$))|(?:#[^\r\n]*(?:\r?\n|$))|(?:\/\*[\s\S]*?\*\/))*/)[0];
    const statement = part.slice(prefix.length);
    if (/^CREATE DATABASE IF NOT EXISTS `silver_screen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;$/.test(statement)) {
      creates += 1;
      return `${prefix}CREATE DATABASE IF NOT EXISTS \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`;
    }
    if (/^USE `silver_screen`;$/.test(statement)) {
      uses += 1;
      return `${prefix}USE \`${database}\`;`;
    }
    const drop = statement.match(/^DROP TABLE IF EXISTS `([a-z_]+)`;$/);
    if (drop) {
      if (!REQUIRED_TABLES.includes(drop[1])) throw new InitializationError('The bundled snapshot contains an unexpected table removal.');
      droppedTables.add(drop[1]);
      // Import is empty-only. Removing drops also prevents destructive races.
      return prefix;
    }
    if (/^(?:CREATE\s+DATABASE|USE\s|DROP\s)/i.test(statement)) {
      throw new InitializationError('The bundled snapshot contains an unexpected database or removal directive.');
    }
    return part;
  }).join('');
  if (creates !== 1 || uses !== 1 || droppedTables.size !== REQUIRED_TABLES.length) {
    throw new InitializationError('The bundled snapshot does not match the expected application schema.');
  }
  return rewritten;
}

async function listTables(connection, database) {
  const [rows] = await connection.execute(
    'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
    [database],
  );
  return rows;
}

function missingTables(rows) {
  const existing = new Set(rows.filter((row) => row.type === 'BASE TABLE').map((row) => row.name));
  return REQUIRED_TABLES.filter((table) => !existing.has(table));
}

export async function initializeDatabase({ connection, database, snapshot, log = () => {} }) {
  validateDatabaseName(database);
  const before = await listTables(connection, database);
  if (before.length) {
    const missing = missingTables(before);
    if (missing.length) {
      throw new InitializationError(`Database is not empty and lacks required application tables: ${missing.join(', ')}. Automatic import refused; existing data was not changed. Inspect or migrate this database manually.`);
    }
    log('Database already contains the application tables; initialization skipped.');
    return { initialized: false, reason: 'existing-schema' };
  }

  const sql = prepareSnapshot(snapshot, database);
  const [[version]] = await connection.query('SELECT VERSION() AS version');
  if (/tidb/i.test(String(version.version))) {
    try {
      const enabled = (value) => ['1', 'on', 'true'].includes(String(value).toLowerCase());
      const [[initial]] = await connection.query('SELECT @@GLOBAL.tidb_enable_check_constraint AS enabled');
      if (!enabled(initial.enabled)) {
        await connection.query('SET GLOBAL tidb_enable_check_constraint = ON');
        const [[updated]] = await connection.query('SELECT @@GLOBAL.tidb_enable_check_constraint AS enabled');
        if (!enabled(updated.enabled)) throw new Error('CHECK constraints remain disabled.');
        log('Enabled TiDB CHECK constraints globally for the application schema.');
      }
    } catch (cause) {
      throw new InitializationError('TiDB CHECK constraints must be enabled before initialization. The database account must be able to enable the global tidb_enable_check_constraint setting, or an administrator must enable it first. No application tables were imported.', { cause });
    }
  }
  // Check again immediately before the import, including views and other tables.
  if ((await listTables(connection, database)).length) {
    throw new InitializationError('Database became non-empty during startup. Automatic import refused; existing data was not changed. Restart after checking for another initializer.');
  }
  try {
    await connection.query(sql);
  } catch (cause) {
    throw new InitializationError('Database initialization failed. DDL may have left a partial schema; it will not be erased or automatically reimported. Check the database service and inspect the schema before retrying.', { cause });
  }
  const missing = missingTables(await listTables(connection, database));
  if (missing.length) throw new InitializationError(`Initialization did not create the required application tables: ${missing.join(', ')}. Inspect the schema manually; no automatic replacement will be attempted.`);
  log('Empty database initialized from the bundled public catalogue snapshot.');
  return { initialized: true };
}

export async function runInitialization({
  env = process.env,
  connect = async (options) => (await import('mysql2/promise')).default.createConnection(options),
  configure = buildDbConfig,
  loadSnapshot = () => readFile(snapshotUrl, 'utf8'),
  log = console.log,
} = {}) {
  if (env.INITIALIZE_EMPTY_DATABASE !== 'true') {
    log('Automatic database initialization is disabled.');
    return { initialized: false, reason: 'disabled' };
  }
  if (env.INITIALIZE_BASE_DATABASE === 'true') {
    throw new InitializationError('This initializer supports only the complete application database. Initialize the independent base database separately using its documented snapshot.');
  }
  const database = validateDatabaseName(env.MYSQL_DATABASE || 'silver_screen');
  const {
    database: unusedDatabase, waitForConnections, connectionLimit, maxIdle,
    idleTimeout, queueLimit, ...options
  } = configure({ ...env, MYSQL_DATABASE: database });
  // No database is selected until the target has been checked. Multiple statements
  // are enabled only for this short-lived connection to the fixed trusted snapshot.
  const connection = await connect({ ...options, multipleStatements: true });
  try {
    return await initializeDatabase({ connection, database, snapshot: await loadSnapshot(), log });
  } finally {
    await connection.end();
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  runInitialization().catch((error) => {
    console.error(error instanceof InitializationError ? error.message : 'Database initialization failed. Check the database connection, credentials and verified TLS configuration. Driver details are withheld to protect secrets.');
    process.exitCode = 1;
  });
}
