export const ROOM_TURNOVER_MINUTES = 60;

function validStart(value) {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.valueOf())) throw new TypeError('Choose a valid screening start time.');
  return date;
}

function validDuration(value) {
  const duration = Number(value);
  if (!Number.isFinite(duration) || duration <= 0) throw new TypeError('Choose a valid movie duration.');
  return duration;
}

export function screeningEndsAt(startsAt, durationMinutes) {
  return new Date(validStart(startsAt).getTime() + validDuration(durationMinutes) * 60_000);
}

export function roomAvailableAt(startsAt, durationMinutes) {
  return new Date(screeningEndsAt(startsAt, durationMinutes).getTime() + ROOM_TURNOVER_MINUTES * 60_000);
}

export function screeningBlocksOverlap(firstStart, firstDuration, secondStart, secondDuration) {
  const first = validStart(firstStart);
  const second = validStart(secondStart);
  return first < roomAvailableAt(second, secondDuration) && second < roomAvailableAt(first, firstDuration);
}
