import test from 'node:test';
import assert from 'node:assert/strict';
import { ROOM_TURNOVER_MINUTES, roomAvailableAt, screeningBlocksOverlap, screeningEndsAt } from './screening-timing.js';

test('screening timing separates the movie end from the room-ready time', () => {
  const startsAt = new Date('2026-09-05T08:00:00.000Z');
  assert.equal(ROOM_TURNOVER_MINUTES, 60);
  assert.equal(screeningEndsAt(startsAt, 143).toISOString(), '2026-09-05T10:23:00.000Z');
  assert.equal(roomAvailableAt(startsAt, 143).toISOString(), '2026-09-05T11:23:00.000Z');
});

test('a room conflicts at 59 minutes of turnover and is available at exactly 60', () => {
  const firstStart = '2026-09-05T02:00:00.000Z';
  assert.equal(screeningBlocksOverlap(firstStart, 100, '2026-09-05T04:39:00.000Z', 90), true);
  assert.equal(screeningBlocksOverlap(firstStart, 100, '2026-09-05T04:40:00.000Z', 90), false);
});

test('the approved universal grid accommodates The Avengers', () => {
  assert.equal(screeningBlocksOverlap('2026-09-07T08:00:00.000Z', 143, '2026-09-07T12:00:00.000Z', 143), false);
  assert.equal(screeningEndsAt('2026-09-07T12:00:00.000Z', 143).toISOString(), '2026-09-07T14:23:00.000Z');
});
