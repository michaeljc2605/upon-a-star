import dotenv from 'dotenv';
import mysql from 'mysql2/promise';
import { access, readFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { ROOM_TURNOVER_MINUTES } from '../server/src/screening-timing.js';

// Reproducible November 2026 programme for the integrated PHP/React database.
// The separate assessed base has a PHP-only command. This command never deletes
// or moves bookings, old screenings, or the original archived film catalogue.
const root = fileURLToPath(new URL('../', import.meta.url));
dotenv.config({ path: join(root, 'server/.env') });

const dryRun = process.argv.includes('--dry-run');
const unknownArguments = process.argv.slice(2).filter((argument) => argument !== '--dry-run');
if (unknownArguments.length) throw new Error('Unknown argument: ' + unknownArguments.join(' '));

const database = process.env.MYSQL_DATABASE || 'silver_screen';
if (database === 'silver_screen_base') {
  throw new Error('The assessed base has its own PHP-only schedule command.');
}

const manifest = JSON.parse(await readFile(join(root, 'scripts/expanded-movie-data.json'), 'utf8'));
if (manifest.length !== 8 || new Set(manifest.map((film) => film.slug)).size !== 8) {
  throw new Error('The expanded movie manifest must contain eight distinct titles.');
}
for (const film of manifest) {
  if (!film.title || !film.slug || !film.synopsis || !film.genre ||
      !Number.isInteger(film.durationMinutes) || film.durationMinutes <= 0 ||
      !film.ageRating || !film.themeKey) {
    throw new Error('A new film has missing or invalid metadata.');
  }
  for (const folder of ['client/public/assets/movies', 'base/assets/movies']) {
    for (const image of ['poster.webp', 'backdrop.webp']) {
      try {
        await access(join(root, folder, film.slug, image), constants.R_OK);
      } catch {
        throw new Error('Missing local artwork: ' + join(folder, film.slug, image));
      }
    }
  }
}

const firstNovemberDay = '2026-11-06';
const lastNovemberDay = '2026-11-26';
const lastDecemberDay = '2026-12-18';
const novemberFilms = [
  ['toy-story', 'N01'],
  ['beauty-and-the-beast', 'P02'],
  ['peter-pan', 'N05'],
  ['wreck-it-ralph', 'P01'],
  ['inside-out', 'N09'],
];
const pulledForwardDecemberFilms = [
  ['monsters-university', 'N02'],
  ['up', 'N03'],
  ['ratatouille', 'N04'],
  ['the-princess-and-the-frog', 'N06'],
  ['the-incredibles', 'N07'],
];
const newFilmRooms = [
  ['coco', 'N08'],
  ['soul', 'N10'],
  ['luca', 'S01'],
  ['encanto', 'S02'],
  ['turning-red', 'S03'],
  ['elemental', 'S04'],
  ['onward', 'S05'],
  ['a-bugs-life', 'S06'],
];
if (newFilmRooms.some(([slug]) => !manifest.some((film) => film.slug === slug))) {
  throw new Error('The film manifest and room plan differ.');
}

const plans = [
  ...novemberFilms.map(([slug, roomCode]) => ({ slug, roomCode, first: firstNovemberDay, last: lastNovemberDay })),
  ...pulledForwardDecemberFilms.map(([slug, roomCode]) => ({ slug, roomCode, first: firstNovemberDay, last: lastNovemberDay })),
  ...newFilmRooms.map(([slug, roomCode]) => ({ slug, roomCode, first: firstNovemberDay, last: lastNovemberDay })),
  ...[
    ['monsters-university', 'N01'],
    ['up', 'P02'],
    ['ratatouille', 'N05'],
    ['the-princess-and-the-frog', 'N07'],
    ['the-incredibles', 'P01'],
  ].map(([slug, roomCode]) => ({ slug, roomCode, first: '2026-12-12', last: lastDecemberDay })),
];

const earlyHours = ['10:00', '10:45', '11:30', '12:15', '13:00', '13:45'];
const lateHours = ['16:00', '16:45', '17:30', '18:15', '19:00', '19:45', '20:15'];
const dateAt = (date) => new Date(date + 'T00:00:00Z');
const addDays = (date, days) => new Date(dateAt(date).getTime() + days * 86_400_000).toISOString().slice(0, 10);
const daysBetween = (first, last) => Math.round((dateAt(last) - dateAt(first)) / 86_400_000);
const dayOfWeek = (date) => dateAt(date).getUTCDay();
const rotate = (list, offset) => [...list.slice(offset % list.length), ...list.slice(0, offset % list.length)];
const toInstant = (dateTime) => new Date(dateTime.replace(' ', 'T') + '+08:00');
const singaporeDateTime = (date) => new Date(date.getTime() + 8 * 3_600_000).toISOString().slice(0, 19).replace('T', ' ');

const connection = await mysql.createConnection({
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
  database,
  dateStrings: true,
});

let lockHeld = false;
let inTransaction = false;
try {
  await connection.query("SET time_zone = '+08:00'");
  const [[clock]] = await connection.query("SELECT DATE_FORMAT(CURRENT_DATE, '%Y-%m-%d') AS today");
  if (clock.today > lastNovemberDay) {
    throw new Error('This dated November 2026 campaign has ended. Create a new future campaign instead of reseeding past showtimes.');
  }
  const [[lock]] = await connection.query("SELECT GET_LOCK('silver_village_expanded_programme', 10) AS acquired");
  if (Number(lock.acquired) !== 1) throw new Error('The programme is being changed elsewhere. Try again shortly.');
  lockHeld = true;
  await connection.beginTransaction();
  inTransaction = true;

  const [archivedBefore] = await connection.query('SELECT * FROM shows WHERE id BETWEEN 1 AND 17 ORDER BY id');
  if (archivedBefore.length !== 17 || archivedBefore.some((film) => film.status !== 'archived')) {
    throw new Error('The original 17 archive films have an unexpected state.');
  }
  const [[bookingsBefore]] = await connection.query('SELECT COUNT(*) AS total FROM bookings');
  const [roomRows] = await connection.query([
    'SELECT r.id, r.code, r.name, r.room_type, r.capacity, r.active, COUNT(rs.id) AS seat_count',
    'FROM rooms r LEFT JOIN room_seats rs ON rs.room_id = r.id',
    'GROUP BY r.id, r.code, r.name, r.room_type, r.capacity, r.active',
  ].join(' '));
  const rooms = new Map(roomRows.map((room) => [room.code, room]));
  for (const room of rooms.values()) {
    if (!room.active || Number(room.seat_count) !== Number(room.capacity)) {
      throw new Error(room.code + ' is inactive or has an incomplete seat map.');
    }
  }

  let addedMovies = 0;
  for (const film of manifest) {
    const [existing] = await connection.execute('SELECT * FROM shows WHERE slug = ? FOR UPDATE', [film.slug]);
    if (existing.length) {
      const saved = existing[0];
      if (saved.title !== film.title || saved.synopsis !== film.synopsis ||
          saved.genre !== film.genre || Number(saved.duration_minutes) !== film.durationMinutes ||
          saved.age_rating !== film.ageRating || saved.theme_key !== film.themeKey ||
          saved.poster_url !== '/assets/movies/' + film.slug + '/poster.webp' ||
          saved.backdrop_url !== '/assets/movies/' + film.slug + '/backdrop.webp' ||
          saved.status !== 'published' || saved.run_start_date !== firstNovemberDay ||
          saved.run_end_date !== lastNovemberDay || saved.sales_open_date !== '2026-11-05') {
        throw new Error('Existing movie differs from the planned new title: ' + film.slug);
      }
      continue;
    }
    await connection.execute([
      'INSERT INTO shows (title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url,',
      'featured, status, run_start_date, run_end_date, sales_open_date, theme_key, trailer_url)',
      "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, ?, ?, ?, NULL)",
    ].join(' '), [
      film.title, film.slug, film.synopsis, film.genre, film.durationMinutes, film.ageRating,
      '/assets/movies/' + film.slug + '/poster.webp',
      '/assets/movies/' + film.slug + '/backdrop.webp',
      ['coco', 'encanto', 'elemental'].includes(film.slug) ? 1 : 0,
      firstNovemberDay, lastNovemberDay, '2026-11-05', film.themeKey,
    ]);
    addedMovies++;
  }

  // The five December launches also play in November. Keep their original
  // December screening and give the five November launches honest end dates.
  for (const [cohort, runEnd, salesOpen] of [
    [novemberFilms, lastNovemberDay, null],
    [pulledForwardDecemberFilms, lastDecemberDay, '2026-11-05'],
  ]) {
    for (const [slug] of cohort) {
      const [rows] = await connection.execute('SELECT id, status FROM shows WHERE slug = ? FOR UPDATE', [slug]);
      if (rows.length !== 1 || rows[0].status !== 'published') {
        throw new Error('Original public movie is missing or unpublished: ' + slug);
      }
      const [outside] = await connection.execute(
        'SELECT id FROM performances WHERE show_id = ? AND (DATE(starts_at) < ? OR DATE(starts_at) > ?) LIMIT 1',
        [rows[0].id, firstNovemberDay, runEnd],
      );
      if (outside.length) throw new Error(slug + ' has an existing screening outside the planned run.');
      await connection.execute(
        'UPDATE shows SET run_start_date = ?, run_end_date = ?, sales_open_date = COALESCE(?, sales_open_date) WHERE id = ?',
        [firstNovemberDay, runEnd, salesOpen, rows[0].id],
      );
    }
  }

  const [movieRows] = await connection.query([
    'SELECT id, slug, title, duration_minutes, status,',
    "DATE_FORMAT(run_start_date, '%Y-%m-%d') AS run_start_date,",
    "DATE_FORMAT(run_end_date, '%Y-%m-%d') AS run_end_date",
    'FROM shows',
  ].join(' '));
  const movies = new Map(movieRows.map((movie) => [movie.slug, movie]));

  let addedScreenings = 0;
  let addedSeats = 0;
  const additionsByFilm = new Map();
  const now = new Date();

  for (const [planIndex, plan] of plans.entries()) {
    const movie = movies.get(plan.slug);
    const room = rooms.get(plan.roomCode);
    if (!movie || !room) throw new Error('Missing movie or room for ' + plan.slug);
    if (movie.status !== 'published' || plan.first < movie.run_start_date || plan.last > movie.run_end_date) {
      throw new Error(movie.title + ' is not published for the entire planned run.');
    }
    for (let dayIndex = 0; dayIndex <= daysBetween(plan.first, plan.last); dayIndex++) {
      const date = addDays(plan.first, dayIndex);
      const weekend = [0, 5, 6].includes(dayOfWeek(date));
      const target = weekend ? 2 : 1;
      const [existing] = await connection.execute([
        'SELECT id, starts_at FROM performances',
        "WHERE show_id = ? AND starts_at >= ? AND starts_at < ? AND status = 'scheduled'",
        'AND history_deleted_at IS NULL ORDER BY starts_at',
      ].join(' '), [movie.id, date + ' 00:00:00', addDays(date, 1) + ' 00:00:00']);
      let remaining = Math.max(0, target - existing.length);
      if (!remaining) continue;
      if (date < clock.today) {
        throw new Error('Cannot backfill past screenings for ' + movie.title + ' on ' + date + '. Start a new future campaign.');
      }

      const early = rotate(earlyHours, planIndex * 2 + dayIndex);
      const late = rotate(lateHours, planIndex + dayIndex * 2);
      const existingEarly = existing.some((item) => item.starts_at.slice(11, 16) < '15:00');
      const pools = target === 2
        ? (existingEarly ? [late] : [early, late])
        : [((planIndex + dayIndex) % 2 === 0 ? early : late)];
      for (const pool of pools) {
        if (!remaining) break;
        let placed = false;
        for (const time of pool) {
          const startsAt = date + ' ' + time + ':00';
          const startsInstant = toInstant(startsAt);
          if (startsInstant.getTime() < now.getTime() + 90 * 60_000) continue;
          const filmEnds = new Date(startsInstant.getTime() + Number(movie.duration_minutes) * 60_000);
          if (filmEnds > toInstant(date + ' 22:30:00')) continue;
          const roomReady = singaporeDateTime(new Date(filmEnds.getTime() + ROOM_TURNOVER_MINUTES * 60_000));

          // Match the owner API's room-row lock and half-open overlap rule.
          const [locked] = await connection.execute(
            'SELECT id FROM rooms WHERE id = ? AND active = 1 FOR UPDATE', [room.id],
          );
          if (!locked.length) throw new Error('Room became inactive: ' + room.code);
          const [duplicate] = await connection.execute(
            'SELECT id FROM performances WHERE show_id = ? AND starts_at = ? AND auditorium = ? LIMIT 1',
            [movie.id, startsAt, room.name],
          );
          if (duplicate.length) continue;
          const [conflicts] = await connection.execute([
            'SELECT p.id FROM performances p JOIN shows s ON s.id = p.show_id',
            "WHERE p.room_id = ? AND p.status = 'scheduled' AND p.history_deleted_at IS NULL",
            'AND p.starts_at < ?',
            'AND TIMESTAMPADD(MINUTE, s.duration_minutes + ' + ROOM_TURNOVER_MINUTES + ', p.starts_at) > ? LIMIT 1',
          ].join(' '), [room.id, roomReady, startsAt]);
          if (conflicts.length) continue;

          const format = room.code === 'P01' ? 'IMAX' : room.code === 'P02' ? 'Dolby Atmos' : 'Standard';
          const price = room.code === 'P01' ? '150000.00' : room.code === 'P02' ? '135000.00'
            : room.room_type === 'small' ? '60000.00' : '80000.00';
          const language = room.room_type === 'premier' || (planIndex + dayIndex) % 3 !== 0
            ? 'English' : 'Bahasa Indonesia';
          const [created] = await connection.execute([
            'INSERT INTO performances',
            '(show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)',
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'scheduled')",
          ].join(' '), [
            movie.id, room.id, startsAt, room.name, format, language, price, Number(room.capacity),
          ]);
          const [seats] = await connection.execute(
            'INSERT INTO performance_seats (performance_id, room_seat_id) SELECT ?, id FROM room_seats WHERE room_id = ?',
            [created.insertId, room.id],
          );
          if (seats.affectedRows !== Number(room.capacity)) {
            throw new Error('Seat map is incomplete for ' + movie.title + ' at ' + startsAt);
          }
          addedScreenings++;
          addedSeats += seats.affectedRows;
          additionsByFilm.set(movie.title, (additionsByFilm.get(movie.title) || 0) + 1);
          remaining--;
          placed = true;
          break;
        }
        if (!placed) throw new Error('Could not fit a ' + (pool === early ? 'matinee' : 'evening') + ' for ' + movie.title + ' on ' + date);
      }
      if (remaining) throw new Error('Could not fit ' + target + ' screenings for ' + movie.title + ' on ' + date);
    }
  }

  const [[conflicts]] = await connection.query([
    'SELECT COUNT(*) AS total FROM performances first_screening',
    'JOIN performances second_screening ON first_screening.id < second_screening.id',
    'AND first_screening.room_id = second_screening.room_id',
    "AND first_screening.status = 'scheduled' AND second_screening.status = 'scheduled'",
    'AND first_screening.history_deleted_at IS NULL AND second_screening.history_deleted_at IS NULL',
    'JOIN shows first_movie ON first_movie.id = first_screening.show_id',
    'JOIN shows second_movie ON second_movie.id = second_screening.show_id',
    'WHERE first_screening.starts_at < TIMESTAMPADD(MINUTE, second_movie.duration_minutes + ' + ROOM_TURNOVER_MINUTES + ', second_screening.starts_at)',
    'AND second_screening.starts_at < TIMESTAMPADD(MINUTE, first_movie.duration_minutes + ' + ROOM_TURNOVER_MINUTES + ', first_screening.starts_at)',
  ].join(' '));
  if (Number(conflicts.total)) throw new Error(conflicts.total + ' room timing conflicts remain.');

  const [archivedAfter] = await connection.query('SELECT * FROM shows WHERE id BETWEEN 1 AND 17 ORDER BY id');
  if (JSON.stringify(archivedAfter) !== JSON.stringify(archivedBefore)) {
    throw new Error('The original archived catalogue changed.');
  }
  const [[bookingsAfter]] = await connection.query('SELECT COUNT(*) AS total FROM bookings');
  if (Number(bookingsAfter.total) !== Number(bookingsBefore.total)) {
    throw new Error('The booking count changed unexpectedly.');
  }

  if (dryRun) await connection.rollback();
  else await connection.commit();
  inTransaction = false;
  console.log(JSON.stringify({
    database,
    dryRun,
    newMovies: addedMovies,
    addedScreenings,
    addedSeats,
    roomConflicts: Number(conflicts.total),
    preservedBookings: Number(bookingsAfter.total),
    preservedArchivedMovies: archivedAfter.length,
    additionsByFilm: Object.fromEntries([...additionsByFilm].sort(([a], [b]) => a.localeCompare(b))),
  }, null, 2));
} catch (error) {
  if (inTransaction) await connection.rollback();
  throw error;
} finally {
  if (lockHeld) await connection.query("SELECT RELEASE_LOCK('silver_village_expanded_programme')");
  await connection.end();
}
