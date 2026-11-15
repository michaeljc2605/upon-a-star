import { spawn } from 'node:child_process';
import { createWriteStream } from 'node:fs';
import { access, mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';

const repositoryRoot = fileURLToPath(new URL('../', import.meta.url));
dotenv.config({ path: join(repositoryRoot, 'server/.env') });

const database = process.env.MYSQL_DATABASE || 'silver_screen';
const databaseConfig = {
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
  database,
};

const outputPath = resolve(process.argv[2] || join(repositoryRoot, 'database/silver_screen_public.sql'));
const temporaryPath = `${outputPath}.tmp-${process.pid}`;
const publicTables = ['rooms', 'room_seats', 'shows', 'performances', 'performance_seats'];
const minimumCounts = new Map([
  ['rooms', 22],
  ['room_seats', 1872],
  ['shows', 27],
  ['performances', 249],
  ['performance_seats', 21600],
]);
const privateTables = [
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

const commandIsExecutable = async (command) => {
  if (!command.includes('/')) return true;
  try {
    await access(command, constants.X_OK);
    return true;
  } catch {
    return false;
  }
};

const dumpCandidates = [
  process.env.MYSQLDUMP_BIN,
  '/Applications/XAMPP/xamppfiles/bin/mysqldump',
  'mysqldump',
].filter(Boolean);

let dumpCommand = '';
for (const candidate of dumpCandidates) {
  if (await commandIsExecutable(candidate)) {
    dumpCommand = candidate;
    break;
  }
}
if (!dumpCommand) throw new Error('mysqldump was not found. Install MySQL/MariaDB or set MYSQLDUMP_BIN.');

const commonArguments = [
  `--host=${databaseConfig.host}`,
  `--port=${databaseConfig.port}`,
  `--user=${databaseConfig.user}`,
  '--protocol=TCP',
  '--default-character-set=utf8mb4',
  '--single-transaction',
  '--quick',
  '--skip-comments',
  '--skip-add-locks',
  '--skip-lock-tables',
  '--order-by-primary',
  '--hex-blob',
];

const runDump = (arguments_, destination) => new Promise((resolveDump, rejectDump) => {
  const child = spawn(dumpCommand, [...commonArguments, ...arguments_], {
    env: { ...process.env, MYSQL_PWD: databaseConfig.password },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let standardError = '';
  child.stderr.setEncoding('utf8');
  child.stderr.on('data', (chunk) => { standardError += chunk; });
  child.stdout.pipe(destination, { end: false });
  child.on('error', rejectDump);
  child.on('close', (code) => {
    if (code === 0) resolveDump();
    else rejectDump(new Error(`mysqldump exited with code ${code}: ${standardError.trim()}`));
  });
});

const verifySourceCounts = async () => {
  const connection = await mysql.createConnection(databaseConfig);
  try {
    const counts = {};
    for (const table of publicTables) {
      const [rows] = await connection.query('SELECT COUNT(*) AS count FROM ??', [table]);
      const actual = Number(rows[0].count);
      const minimum = minimumCounts.get(table);
      if (actual < minimum) throw new Error(`${table} contains ${actual} rows; expected at least ${minimum}.`);
      if (['rooms', 'room_seats'].includes(table) && actual !== minimum) {
        throw new Error(`${table} contains ${actual} rows; expected ${minimum}.`);
      }
      counts[table] = actual;
    }
    const [[seatMaps]] = await connection.query(`SELECT COUNT(*) AS errors FROM (
      SELECT p.id FROM performances p LEFT JOIN performance_seats ps ON ps.performance_id = p.id
      GROUP BY p.id, p.capacity HAVING COUNT(ps.id) != p.capacity
    ) invalid_seat_maps`);
    if (Number(seatMaps.errors)) throw new Error('The source has incomplete screening seat maps.');
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
    if (Number(conflicts.total)) throw new Error('The source has room timing clashes.');
    return counts;
  } finally {
    await connection.end();
  }
};

const verifyPublicDump = async (path) => {
  const sql = await readFile(path, 'utf8');
  for (const table of privateTables) {
    const privateInsert = new RegExp('INSERT INTO `' + table + '`', 'i');
    if (privateInsert.test(sql)) throw new Error(`Private table data was found for ${table}.`);
  }
  if (/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i.test(sql)) {
    throw new Error('An email address was found in the public database dump.');
  }
  for (const table of publicTables) {
    const publicInsert = new RegExp('INSERT INTO `' + table + '`', 'i');
    if (!publicInsert.test(sql)) throw new Error(`Expected public data is missing for ${table}.`);
  }
};

await mkdir(dirname(outputPath), { recursive: true });
const sourceCounts = await verifySourceCounts();

const destination = createWriteStream(temporaryPath, { encoding: 'utf8', mode: 0o644 });
destination.write(`-- Upon a Star Cinema public demo database\n`);
destination.write(`-- Contains the full schema, rooms, seat maps, ${sourceCounts.shows} movies and ${sourceCounts.performances} screenings.\n`);
destination.write(`-- Excludes users, sessions, bookings, tickets, payments, holds and delivery records.\n\n`);
destination.write('CREATE DATABASE IF NOT EXISTS `' + database + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n');
destination.write('USE `' + database + '`;\n\n');

try {
  await runDump(['--no-data', '--triggers', database], destination);
  destination.write('\n');
  await runDump(['--no-create-info', '--skip-triggers', database, ...publicTables], destination);
  // The public export omits reservations, so their cached occupancy must also
  // be removed. This statement changes only the imported copy, not the source.
  destination.write('\n-- No reservations are included in this public demo export.\nUPDATE `performances` SET `seats_booked` = 0;\n');
  await new Promise((resolveStream, rejectStream) => {
    destination.on('error', rejectStream);
    destination.end(resolveStream);
  });
  const generatedSql = await readFile(temporaryPath, 'utf8');
  await writeFile(temporaryPath, generatedSql.trimEnd() + '\n', { encoding: 'utf8', mode: 0o644 });
  await verifyPublicDump(temporaryPath);
  await rename(temporaryPath, outputPath);
  console.log(`Created public database dump: ${outputPath}`);
} catch (error) {
  destination.destroy();
  await rm(temporaryPath, { force: true });
  throw error;
}
