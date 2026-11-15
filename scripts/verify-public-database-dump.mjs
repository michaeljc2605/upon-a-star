import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';

const repositoryRoot = fileURLToPath(new URL('../', import.meta.url));
dotenv.config({ path: join(repositoryRoot, 'server/.env') });

const sourceDatabase = process.env.MYSQL_DATABASE || 'silver_screen';
const verificationDatabase = `silver_screen_public_verify_${process.pid}_${Date.now()}`;
const dumpPath = join(repositoryRoot, 'database/silver_screen_public.sql');
const publicTables = ['rooms', 'room_seats', 'shows', 'performances', 'performance_seats'];
const expectedEmptyCounts = [
  'users',
  'sessions',
  'audit_events',
  'bookings',
  'booking_items',
  'booking_tickets',
  'payment_events',
  'seat_holds',
  'email_deliveries',
];

const connection = await mysql.createConnection({
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
  multipleStatements: true,
});

const importDump = (sql) => new Promise((resolveImport, rejectImport) => {
  const xamppMysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
  const mysqlCommand = process.env.MYSQL_BIN || (existsSync(xamppMysql) ? xamppMysql : 'mysql');
  const child = spawn(mysqlCommand, [
    '--host=' + (process.env.MYSQL_HOST || '127.0.0.1'),
    '--port=' + Number(process.env.MYSQL_PORT || 3306),
    '--user=' + (process.env.MYSQL_USER || 'root'),
    '--protocol=TCP',
    '--default-character-set=utf8mb4',
  ], {
    env: { ...process.env, MYSQL_PWD: process.env.MYSQL_PASSWORD || '' },
    stdio: ['pipe', 'ignore', 'pipe'],
  });
  let standardError = '';
  child.stderr.setEncoding('utf8');
  child.stderr.on('data', (chunk) => { standardError += chunk; });
  child.on('error', rejectImport);
  child.on('close', (code) => {
    if (code === 0) resolveImport();
    else rejectImport(new Error('mysql import exited with code ' + code + ': ' + standardError.trim()));
  });
  child.stdin.end(sql);
});

try {
  const sourceCounts = {};
  for (const table of publicTables) {
    const [rows] = await connection.query(`SELECT COUNT(*) AS count FROM \`${sourceDatabase.replaceAll('`', '``')}\`.\`${table}\``);
    sourceCounts[table] = Number(rows[0].count);
  }
  const dump = await readFile(dumpPath, 'utf8');
  const sourceIdentifier = '`' + sourceDatabase.replaceAll('`', '``') + '`';
  const verificationIdentifier = '`' + verificationDatabase + '`';
  const verificationDump = dump.replaceAll(sourceIdentifier, verificationIdentifier);
  if (verificationDump === dump) throw new Error(`The dump does not target ${sourceIdentifier}.`);

  // Stream individual SQL statements through mysql. Sending the whole dump as
  // one mysql2 query can exceed a local server's max_allowed_packet setting.
  await importDump(verificationDump);
  await connection.query(`USE ${verificationIdentifier}`);

  const verifiedCounts = {};
  for (const table of publicTables) {
    const [rows] = await connection.query('SELECT COUNT(*) AS count FROM ??', [table]);
    const actual = Number(rows[0].count);
    if (actual !== sourceCounts[table]) throw new Error(`${table} imported ${actual} rows; source has ${sourceCounts[table]}.`);
    verifiedCounts[table] = actual;
  }
  for (const table of expectedEmptyCounts) {
    const [rows] = await connection.query('SELECT COUNT(*) AS count FROM ??', [table]);
    const actual = Number(rows[0].count);
    if (actual !== 0) throw new Error(`${table} imported ${actual} private rows.`);
  }

  const [[integrity]] = await connection.query(`SELECT
    SUM(DATE(p.starts_at) NOT BETWEEN s.run_start_date AND s.run_end_date) AS outside_run_dates,
    SUM(p.status != 'scheduled') AS non_scheduled,
    COUNT(DISTINCT CASE WHEN DATE(p.starts_at) IN ('2026-09-05', '2026-09-06') THEN p.room_id END) AS opening_weekend_rooms
    FROM performances p JOIN shows s ON s.id = p.show_id`);
  if (Number(integrity.outside_run_dates) !== 0) throw new Error('The imported dump has screenings outside movie run dates.');
  if (Number(integrity.non_scheduled) !== 0) throw new Error('The imported dump has non-scheduled programme screenings.');
  if (Number(integrity.opening_weekend_rooms) !== 22) throw new Error('The imported dump does not use all 22 opening-weekend rooms.');
  const [[conflicts]] = await connection.query(`SELECT COUNT(*) AS total
    FROM performances first_screening
    JOIN performances second_screening
      ON first_screening.id < second_screening.id
      AND first_screening.room_id = second_screening.room_id
      AND first_screening.status = 'scheduled'
      AND second_screening.status = 'scheduled'
      AND first_screening.history_deleted_at IS NULL
      AND second_screening.history_deleted_at IS NULL
    JOIN shows first_movie ON first_movie.id = first_screening.show_id
    JOIN shows second_movie ON second_movie.id = second_screening.show_id
    WHERE first_screening.starts_at < TIMESTAMPADD(MINUTE, second_movie.duration_minutes + 60, second_screening.starts_at)
      AND second_screening.starts_at < TIMESTAMPADD(MINUTE, first_movie.duration_minutes + 60, first_screening.starts_at)`);
  if (Number(conflicts.total)) throw new Error('The imported dump has room timing clashes.');

  console.log(JSON.stringify({
    importedIntoTemporaryDatabase: true,
    ...verifiedCounts,
    privateRows: 0,
    screeningsOutsideRunDates: 0,
    roomConflicts: 0,
    openingWeekendRooms: 22,
  }, null, 2));
} finally {
  await connection.query('DROP DATABASE IF EXISTS `' + verificationDatabase + '`');
  await connection.end();
}
