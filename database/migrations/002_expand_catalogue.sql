-- Expand the public programme to ten titles and give each new title multiple dates.
USE silver_screen;

INSERT IGNORE INTO shows
  (title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url, status, featured)
VALUES
  ('After the Rain', 'after-the-rain', 'A travelling photographer returns to her coastal hometown and finds a forgotten friendship waiting in the rain.', 'Drama · Romance', 104, 'PG', 'https://placehold.co/600x900/32506a/f4d6b8?text=AFTER+THE+RAIN', 'https://placehold.co/1800x900/24394c/e8c7a6?text=AFTER+THE+RAIN', 'published', 0),
  ('Midnight Archive', 'midnight-archive', 'An archivist discovers a recorded voice that predicts events no one has lived through yet.', 'Thriller · Mystery', 112, '13+', 'https://placehold.co/600x900/382046/e8c0d8?text=MIDNIGHT+ARCHIVE', 'https://placehold.co/1800x900/21172f/d4a7c5?text=MIDNIGHT+ARCHIVE', 'published', 1),
  ('Little Comets', 'little-comets', 'Two siblings build a tiny observatory and make one enormous promise to the night sky.', 'Family · Adventure', 96, 'G', 'https://placehold.co/600x900/244661/f7db91?text=LITTLE+COMETS', 'https://placehold.co/1800x900/172d47/efc875?text=LITTLE+COMETS', 'published', 0),
  ('The Quiet Algorithm', 'the-quiet-algorithm', 'A patient programmer questions the recommendation system that seems to know her better than anyone.', 'Sci-fi · Drama', 121, '13+', 'https://placehold.co/600x900/27374d/c2dded?text=THE+QUIET+ALGORITHM', 'https://placehold.co/1800x900/17243b/b7dce9?text=THE+QUIET+ALGORITHM', 'published', 0),
  ('Saffron Street', 'saffron-street', 'A young chef brings an old neighbourhood together with a menu of dishes nobody can quite remember.', 'Comedy · Food', 98, 'PG', 'https://placehold.co/600x900/65392e/f7d4a3?text=SAFFRON+STREET', 'https://placehold.co/1800x900/44251f/f1be82?text=SAFFRON+STREET', 'published', 0),
  ('Wild Letters', 'wild-letters', 'Handwritten letters cross an island chain, carrying a conservationist and a poet toward the same shore.', 'Documentary · Nature', 88, 'G', 'https://placehold.co/600x900/20483e/d7ead0?text=WILD+LETTERS', 'https://placehold.co/1800x900/17362f/bcd9b6?text=WILD+LETTERS', 'published', 0);

-- Every row below is idempotent because performances retain their unique show/date/room slot.
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-22 15:00:00', r.name, 'Standard', 'English', 75000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'N04' WHERE s.slug = 'after-the-rain';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-24 19:00:00', r.name, 'Standard', 'English', 75000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'N04' WHERE s.slug = 'after-the-rain';

INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-22 20:30:00', r.name, 'Dolby Atmos', 'English', 155000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'P02' WHERE s.slug = 'midnight-archive';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-23 20:30:00', r.name, 'Dolby Atmos', 'English', 155000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'P02' WHERE s.slug = 'midnight-archive';

INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-23 11:30:00', r.name, 'Standard', 'Bahasa Indonesia', 60000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S01' WHERE s.slug = 'little-comets';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-24 11:30:00', r.name, 'Standard', 'Bahasa Indonesia', 60000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S01' WHERE s.slug = 'little-comets';

INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-23 18:45:00', r.name, 'Standard', 'English', 90000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'N05' WHERE s.slug = 'the-quiet-algorithm';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-25 18:45:00', r.name, 'Standard', 'English', 90000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'N05' WHERE s.slug = 'the-quiet-algorithm';

INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-22 17:15:00', r.name, 'Standard', 'Bahasa Indonesia', 70000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S02' WHERE s.slug = 'saffron-street';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-24 17:15:00', r.name, 'Standard', 'Bahasa Indonesia', 70000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S02' WHERE s.slug = 'saffron-street';

INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-22 14:30:00', r.name, 'Standard', 'English', 65000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S03' WHERE s.slug = 'wild-letters';
INSERT IGNORE INTO performances
  (show_id, room_id, starts_at, auditorium, format, language, unit_price, capacity, seats_booked, status)
SELECT s.id, r.id, '2026-08-25 14:30:00', r.name, 'Standard', 'English', 65000, r.capacity, 0, 'scheduled'
FROM shows s JOIN rooms r ON r.code = 'S03' WHERE s.slug = 'wild-letters';

INSERT IGNORE INTO performance_seats (performance_id, room_seat_id)
SELECT p.id, rs.id
FROM performances p
JOIN shows s ON s.id = p.show_id
JOIN room_seats rs ON rs.room_id = p.room_id
WHERE s.slug IN ('after-the-rain', 'midnight-archive', 'little-comets', 'the-quiet-algorithm', 'saffron-street', 'wild-letters');

UPDATE performances p
JOIN shows s ON s.id = p.show_id
SET p.capacity = (SELECT COUNT(*) FROM performance_seats ps WHERE ps.performance_id = p.id)
WHERE s.slug IN ('after-the-rain', 'midnight-archive', 'little-comets', 'the-quiet-algorithm', 'saffron-street', 'wild-letters');
