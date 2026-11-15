import assert from 'node:assert/strict';
import dotenv from 'dotenv';
import { readFile } from 'node:fs/promises';
import { movies, programmeScreenings } from './disney-programme-data.mjs';

dotenv.config({ path: new URL('../server/.env', import.meta.url) });
const { pool } = await import('../server/src/db.js');

const expectedScreenings = programmeScreenings();
const expandedRunDates = new Map([
  ...['toy-story', 'beauty-and-the-beast', 'peter-pan', 'wreck-it-ralph', 'inside-out']
    .map((slug) => [slug, { start: '2026-11-06', end: '2026-11-26' }]),
  ...['monsters-university', 'up', 'ratatouille', 'the-princess-and-the-frog', 'the-incredibles']
    .map((slug) => [slug, { start: '2026-11-06', end: '2026-12-18', sales: '2026-11-05' }]),
]);
const newMovies = JSON.parse(await readFile(new URL('./expanded-movie-data.json', import.meta.url), 'utf8'));
const screeningKey = (item) => [
  item.title,
  item.roomCode,
  item.startsAt,
  item.format,
  item.language,
  Number(item.unitPrice),
].join('|');

try {
  const [movieRows] = await pool.query(`SELECT title, slug, synopsis, genre, duration_minutes, age_rating,
    poster_url, backdrop_url, COALESCE(trailer_url, '') AS trailer_url, theme_key, status, featured,
    DATE_FORMAT(run_start_date, '%Y-%m-%d') AS run_start_date,
    DATE_FORMAT(run_end_date, '%Y-%m-%d') AS run_end_date,
    DATE_FORMAT(sales_open_date, '%Y-%m-%d') AS sales_open_date
    FROM shows ORDER BY id`);
  assert.ok(movieRows.length >= movies.length, 'The original 27 programme movies must remain present.');

  const movieRowsBySlug = new Map(movieRows.map((row) => [row.slug, row]));
  for (const movie of movies) {
    const row = movieRowsBySlug.get(movie.slug);
    assert.ok(row, `Missing movie ${movie.slug}.`);
    // A live programme can archive completed films without changing its
    // original film metadata or screening history.
    assert.ok(['published', 'archived'].includes(row.status), `Unexpected lifecycle status for ${movie.title}.`);
    const expanded = expandedRunDates.get(movie.slug);
    assert.ok([movie.runEndDate, expanded?.end].includes(row.run_end_date),
      `Stored run end differs for ${movie.title}.`);
    assert.ok([movie.runStartDate, expanded?.start].includes(row.run_start_date),
      `Stored run start differs for ${movie.title}.`);
    assert.ok([movie.salesOpenDate, expanded?.sales].includes(row.sales_open_date),
      `Stored sales-open date differs for ${movie.title}.`);
    assert.deepEqual({
      title: row.title,
      slug: row.slug,
      synopsis: row.synopsis,
      genre: row.genre,
      durationMinutes: Number(row.duration_minutes),
      ageRating: row.age_rating,
      posterUrl: row.poster_url,
      backdropUrl: row.backdrop_url,
      trailerUrl: row.trailer_url,
      themeKey: row.theme_key,
      featured: Boolean(row.featured),
    }, {
      title: movie.title,
      slug: movie.slug,
      synopsis: movie.synopsis,
      genre: movie.genre,
      durationMinutes: movie.durationMinutes,
      ageRating: movie.ageRating,
      posterUrl: movie.posterUrl,
      backdropUrl: movie.backdropUrl,
      trailerUrl: movie.trailerUrl,
      themeKey: movie.themeKey,
      featured: movie.featured,
    }, `Stored metadata differs for ${movie.title}.`);
  }

  const presentNewMovies = newMovies.filter((movie) => movieRowsBySlug.has(movie.slug));
  assert.ok(
    presentNewMovies.length === 0 || presentNewMovies.length === newMovies.length,
    'The expanded programme must contain either all eight new films or none.',
  );
  if (presentNewMovies.length) {
    for (const film of newMovies) {
      const row = movieRowsBySlug.get(film.slug);
      assert.equal(row.title, film.title, film.slug + ' title changed.');
      assert.equal(row.synopsis, film.synopsis, film.slug + ' synopsis changed.');
      assert.equal(row.genre, film.genre, film.slug + ' genre changed.');
      assert.equal(Number(row.duration_minutes), film.durationMinutes, film.slug + ' duration changed.');
      assert.equal(row.age_rating, film.ageRating, film.slug + ' age rating changed.');
      assert.equal(row.theme_key, film.themeKey, film.slug + ' theme changed.');
      assert.equal(row.poster_url, '/assets/movies/' + film.slug + '/poster.webp');
      assert.equal(row.backdrop_url, '/assets/movies/' + film.slug + '/backdrop.webp');
      assert.equal(row.run_start_date, '2026-11-06');
      assert.equal(row.run_end_date, '2026-11-26');
      assert.equal(row.sales_open_date, '2026-11-05');
    }
    assert.equal(
      movieRows.filter((row) => row.status === 'archived' && movies.some((original) => original.slug === row.slug)).length,
      17,
      'The original 17 completed films must stay archived.',
    );
    const activeSlugs = [
      'toy-story', 'beauty-and-the-beast', 'peter-pan', 'wreck-it-ralph', 'inside-out',
      'monsters-university', 'up', 'ratatouille', 'the-princess-and-the-frog', 'the-incredibles',
      ...newMovies.map((film) => film.slug),
    ];
    const [dailyRows] = await pool.query(`SELECT DATE_FORMAT(p.starts_at, '%Y-%m-%d') AS day,
      s.slug, COUNT(*) AS total
      FROM performances p JOIN shows s ON s.id = p.show_id
      WHERE p.status = 'scheduled' AND DATE(p.starts_at) BETWEEN '2026-11-06' AND '2026-11-26'
      GROUP BY DATE(p.starts_at), s.slug`);
    const dailyCounts = new Map(dailyRows.map((row) => [row.day + '|' + row.slug, Number(row.total)]));
    for (let dayIndex = 0; dayIndex < 21; dayIndex++) {
      const date = new Date(Date.UTC(2026, 10, 6 + dayIndex)).toISOString().slice(0, 10);
      const weekday = new Date(date + 'T00:00:00Z').getUTCDay();
      const target = [0, 5, 6].includes(weekday) ? 2 : 1;
      for (const slug of activeSlugs) {
        assert.ok(
          (dailyCounts.get(date + '|' + slug) || 0) >= target,
          slug + ' has too few screenings on ' + date + '.',
        );
      }
    }
    const [[december]] = await pool.query(`SELECT COUNT(*) AS total, COUNT(DISTINCT show_id) AS titles
      FROM performances WHERE status = 'scheduled'
      AND DATE(starts_at) BETWEEN '2026-12-12' AND '2026-12-18'`);
    assert.ok(Number(december.total) >= 50, 'The five December films need at least 50 screenings.');
    assert.equal(Number(december.titles), 5, 'The December launch films must remain scheduled.');
    const [[lateFinishes]] = await pool.query(`SELECT COUNT(*) AS total FROM performances p
      JOIN shows s ON s.id = p.show_id
      WHERE p.status = 'scheduled'
      AND DATE(p.starts_at) BETWEEN '2026-11-06' AND '2026-12-18'
      AND TIME(TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at)) > '22:30:00'`);
    assert.equal(Number(lateFinishes.total), 0, 'A screening finishes after the published 22:30 closing time.');
  }

  const [screeningRows] = await pool.query(`SELECT s.title, r.code AS roomCode,
    DATE_FORMAT(p.starts_at, '%Y-%m-%dT%H:%i') AS startsAt,
    p.format, p.language, p.unit_price AS unitPrice
    FROM performances p
    JOIN shows s ON s.id = p.show_id
    JOIN rooms r ON r.id = p.room_id
    ORDER BY p.starts_at, r.code, s.title`);
  assert.ok(screeningRows.length >= expectedScreenings.length, 'The database is missing programme screenings.');

  const expectedCounts = new Map();
  const actualCounts = new Map();
  for (const row of expectedScreenings) expectedCounts.set(screeningKey(row), (expectedCounts.get(screeningKey(row)) || 0) + 1);
  for (const row of screeningRows) actualCounts.set(screeningKey(row), (actualCounts.get(screeningKey(row)) || 0) + 1);
  for (const [key, count] of expectedCounts) {
    assert.ok((actualCounts.get(key) || 0) >= count, `An original programme screening is missing: ${key}`);
  }

  const [[totals]] = await pool.query(`SELECT
    COUNT(*) AS screenings,
    SUM(DATE(p.starts_at) BETWEEN '2026-09-05' AND '2026-09-11') AS opening_week,
    SUM(DATE(p.starts_at) > '2026-09-11') AS later_launches,
    COUNT(DISTINCT CASE WHEN DATE(p.starts_at) IN ('2026-09-05', '2026-09-06') THEN p.room_id END) AS opening_weekend_rooms,
    SUM(DATE(p.starts_at) NOT BETWEEN s.run_start_date AND s.run_end_date) AS outside_run_dates,
    SUM(p.status != 'scheduled') AS non_scheduled
    FROM performances p JOIN shows s ON s.id = p.show_id`);
  assert.ok(Number(totals.opening_week) >= 228, 'Opening week is missing an original screening.');
  assert.ok(Number(totals.later_launches) >= 21, 'A later launch wave screening is missing.');
  assert.equal(Number(totals.opening_weekend_rooms), 22, 'Opening weekend must use all 22 rooms.');
  assert.equal(Number(totals.outside_run_dates), 0, 'No screening may fall outside its movie run.');
  assert.equal(Number(totals.non_scheduled), 0, 'All programme screenings must be scheduled.');

  const [[seatMaps]] = await pool.query(`SELECT COUNT(*) AS errors FROM (
    SELECT p.id
    FROM performances p
    LEFT JOIN performance_seats ps ON ps.performance_id = p.id
    GROUP BY p.id, p.capacity
    HAVING COUNT(ps.id) != p.capacity
  ) invalid_seat_maps`);
  assert.equal(Number(seatMaps.errors), 0, 'Every screening must have one generated seat row per room seat.');

  const [[conflicts]] = await pool.query(`SELECT COUNT(*) AS total
    FROM performances first_screening
    JOIN performances second_screening
      ON first_screening.id < second_screening.id
      AND first_screening.room_id = second_screening.room_id
      AND first_screening.status = 'scheduled'
      AND second_screening.status = 'scheduled'
    JOIN shows first_movie ON first_movie.id = first_screening.show_id
    JOIN shows second_movie ON second_movie.id = second_screening.show_id
    WHERE first_screening.starts_at < TIMESTAMPADD(MINUTE, second_movie.duration_minutes + 60, second_screening.starts_at)
      AND second_screening.starts_at < TIMESTAMPADD(MINUTE, first_movie.duration_minutes + 60, first_screening.starts_at)`);
  assert.equal(Number(conflicts.total), 0, 'The stored schedule contains a room conflict.');

  const [[roomRules]] = await pool.query(`SELECT
    SUM(r.code = 'P01' AND (p.format != 'IMAX' OR p.language != 'English' OR p.unit_price != 150000)) AS p01_violations,
    SUM(r.code = 'P02' AND (p.format != 'Dolby Atmos' OR p.language != 'English' OR p.unit_price != 135000)) AS p02_violations,
    SUM(r.code LIKE 'N%' AND p.unit_price != 80000) AS normal_price_violations,
    SUM(r.code LIKE 'S%' AND p.unit_price != 60000) AS small_price_violations
    FROM performances p JOIN rooms r ON r.id = p.room_id`);
  for (const [rule, total] of Object.entries(roomRules)) assert.equal(Number(total), 0, `${rule} must be zero.`);

  const premiumRows = expectedScreenings
    .filter((row) => ['P01', 'P02'].includes(row.roomCode))
    .sort((left, right) => left.startsAt.localeCompare(right.startsAt) || left.roomCode.localeCompare(right.roomCode));
  let maximumPremiumRun = 0;
  for (const roomCode of ['P01', 'P02']) {
    let previousTitle = '';
    let runLength = 0;
    for (const row of premiumRows.filter((item) => item.roomCode === roomCode)) {
      runLength = row.title === previousTitle ? runLength + 1 : 1;
      previousTitle = row.title;
      maximumPremiumRun = Math.max(maximumPremiumRun, runLength);
    }
  }
  assert.ok(maximumPremiumRun <= 2, 'A premium room has more than two consecutive screenings of one title.');

  const originalRows = movies.map((movie) => movieRowsBySlug.get(movie.slug));
  const originalSalesOpen = originalRows.filter((movie) => movie.sales_open_date <= '2026-08-29').length;
  const originalSalesFuture = originalRows.filter((movie) => movie.sales_open_date > '2026-08-29').length;
  assert.equal(originalSalesOpen, 11, 'Waves 1–2 must be on sale on 29 August.');
  assert.equal(originalSalesFuture, 16, 'Waves 3–5 must still have future sales dates on 29 August.');

  const [[visibility]] = await pool.query(`SELECT
    SUM(status = 'published' AND run_end_date >= '2027-01-01') AS public_on_january_1,
    COUNT(*) AS owner_history_on_january_1
    FROM shows`);
  assert.equal(Number(visibility.public_on_january_1), 0, 'No programme title may remain public on 1 January 2027.');
  assert.ok(Number(visibility.owner_history_on_january_1) >= movies.length, 'All original titles must remain in owner history.');

  const [timingRows] = await pool.query(`SELECT DATE_FORMAT(p.starts_at, '%Y-%m-%dT%H:%i') AS starts_at,
    DATE_FORMAT(TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at), '%Y-%m-%dT%H:%i') AS ends_at,
    DATE_FORMAT(TIMESTAMPADD(MINUTE, s.duration_minutes + 60, p.starts_at), '%Y-%m-%dT%H:%i') AS room_available_at
    FROM performances p
    JOIN shows s ON s.id = p.show_id
    JOIN rooms r ON r.id = p.room_id
    WHERE s.title = 'The Avengers' AND r.code = 'N06' AND DATE(p.starts_at) = '2026-09-07'
    ORDER BY p.starts_at`);
  assert.deepEqual(timingRows, [
    { starts_at: '2026-09-07T16:00', ends_at: '2026-09-07T18:23', room_available_at: '2026-09-07T19:23' },
    { starts_at: '2026-09-07T20:00', ends_at: '2026-09-07T22:23', room_available_at: '2026-09-07T23:23' },
  ], 'The Avengers timing example must use the 60-minute room turnover exactly.');

  console.log(JSON.stringify({
    movies: movieRows.length,
    originalProgrammeMovies: movies.length,
    additionalMovies: movieRows.length - movies.length,
    expandedNovemberProgramme: presentNewMovies.length === newMovies.length,
    publishedMovies: movieRows.filter((movie) => movie.status === 'published').length,
    archivedMovies: movieRows.filter((movie) => movie.status === 'archived').length,
    screenings: screeningRows.length,
    originalProgrammeScreenings: expectedScreenings.length,
    additionalScreenings: screeningRows.length - expectedScreenings.length,
    openingWeek: Number(totals.opening_week),
    laterLaunches: Number(totals.later_launches),
    openingWeekendRooms: Number(totals.opening_weekend_rooms),
    roomConflicts: Number(conflicts.total),
    screeningsOutsideRunDates: Number(totals.outside_run_dates),
    invalidScreeningSeatMaps: Number(seatMaps.errors),
    salesOpenOnAugust29: originalSalesOpen,
    futureSalesOnAugust29: originalSalesFuture,
    publicTitlesOnJanuary1: Number(visibility.public_on_january_1),
    ownerHistoryTitlesOnJanuary1: Number(visibility.owner_history_on_january_1),
    maximumConsecutivePremiumTitle: maximumPremiumRun,
    originalProgrammeIntact: true,
    singaporeTimesMatchOwnerEntry: true,
  }, null, 2));
} finally {
  await pool.end();
}
