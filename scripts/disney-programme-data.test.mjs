import test from 'node:test';
import assert from 'node:assert/strict';
import { screeningBlocksOverlap } from '../server/src/screening-timing.js';
import { movies, openingWeekScreenings, laterWaveLaunchScreenings, programmeScreenings } from './disney-programme-data.mjs';

const byTitle = new Map(movies.map((movie) => [movie.title, movie]));
const singaporeInstant = (value) => new Date(`${value}:00+08:00`);
const threeDimensionalTitles = new Set(['Tangled', 'WALL-E', 'The Avengers', 'Big Hero 6', 'Frozen', 'Moana', 'The Little Mermaid', 'Brave', 'The Lion King', 'Finding Nemo', 'Zootopia', 'Beauty and the Beast', 'Wreck-It Ralph', 'Inside Out', 'Monsters University', 'Up']);

test('programme contains 27 unique published movies ending in December', () => {
  assert.equal(movies.length, 27);
  assert.equal(byTitle.size, 27);
  assert.ok(movies.every((movie) => movie.status === 'published' && movie.runEndDate === '2026-12-31'));
  assert.ok(movies.every((movie) => movie.posterUrl.startsWith('/assets/movies/') && movie.backdropUrl.startsWith('/assets/movies/')));
});

test('programme contains the approved 228 plus 21 screening split', () => {
  assert.equal(openingWeekScreenings().length, 228);
  assert.equal(laterWaveLaunchScreenings().length, 21);
  assert.equal(programmeScreenings().length, 249);
  const openingRooms = new Set(openingWeekScreenings().filter((screening) => screening.startsAt.startsWith('2026-09-05')).map((screening) => screening.roomCode));
  assert.equal(openingRooms.size, 22);
});

test('every screening is inside its movie run and respects room pricing and format', () => {
  for (const screening of programmeScreenings()) {
    const movie = byTitle.get(screening.title);
    const date = screening.startsAt.slice(0, 10);
    assert.ok(movie, `Missing movie for ${screening.title}`);
    assert.ok(date >= movie.runStartDate && date <= movie.runEndDate, `${screening.title} is outside its run`);
    if (screening.roomCode === 'P01') assert.deepEqual([screening.format, screening.unitPrice, screening.language], ['IMAX', 150000, 'English']);
    if (screening.roomCode === 'P02') assert.deepEqual([screening.format, screening.unitPrice, screening.language], ['Dolby Atmos', 135000, 'English']);
    if (screening.roomCode.startsWith('N')) assert.equal(screening.unitPrice, 80000);
    if (screening.roomCode.startsWith('S')) assert.equal(screening.unitPrice, 60000);
  }
});

test('the generated language and 3D matinee rules match the approved room grid', () => {
  for (const screening of programmeScreenings()) {
    const date = screening.startsAt.slice(0, 10);
    const hour = Number(screening.startsAt.slice(11, 13));
    const weekend = new Date(`${date}T12:00:00Z`).getUTCDay() % 6 === 0;
    if (screening.roomCode.startsWith('P')) {
      assert.equal(screening.language, 'English');
      continue;
    }
    if (screening.roomCode.startsWith('N')) {
      assert.equal(screening.language, weekend && hour === 10 ? 'Bahasa Indonesia' : 'English');
      assert.equal(screening.format, weekend && hour === 14 && threeDimensionalTitles.has(screening.title) ? '3D' : 'Standard');
      continue;
    }
    assert.equal(screening.language, (!weekend && hour === 16) || (weekend && [10, 14].includes(hour)) ? 'Bahasa Indonesia' : 'English');
    assert.equal(screening.format, 'Standard');
  }
});

test('premium rooms never repeat one title more than twice in succession', () => {
  for (const roomCode of ['P01', 'P02']) {
    let previousTitle = '';
    let runLength = 0;
    for (const screening of programmeScreenings().filter((item) => item.roomCode === roomCode).sort((first, second) => first.startsAt.localeCompare(second.startsAt))) {
      runLength = screening.title === previousTitle ? runLength + 1 : 1;
      previousTitle = screening.title;
      assert.ok(runLength <= 2, `${roomCode} repeats ${screening.title} more than twice.`);
    }
  }
});

test('no generated screening blocks overlap in the same room', () => {
  const screenings = programmeScreenings();
  for (let firstIndex = 0; firstIndex < screenings.length; firstIndex += 1) {
    const first = screenings[firstIndex];
    for (let secondIndex = firstIndex + 1; secondIndex < screenings.length; secondIndex += 1) {
      const second = screenings[secondIndex];
      if (first.roomCode !== second.roomCode) continue;
      assert.equal(screeningBlocksOverlap(singaporeInstant(first.startsAt), byTitle.get(first.title).durationMinutes, singaporeInstant(second.startsAt), byTitle.get(second.title).durationMinutes), false, `${first.roomCode}: ${first.startsAt} ${first.title} overlaps ${second.startsAt} ${second.title}`);
    }
  }
});
