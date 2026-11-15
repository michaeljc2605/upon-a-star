-- Run once against the existing silver_screen database.
USE silver_screen;

ALTER TABLE users MODIFY role ENUM('staff', 'manager', 'owner') NOT NULL DEFAULT 'staff';

ALTER TABLE shows
  ADD COLUMN status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
  ADD COLUMN trailer_url VARCHAR(500) NULL;

CREATE TABLE rooms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  room_type ENUM('normal', 'premier', 'small') NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE room_seats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  seat_code VARCHAR(12) NOT NULL,
  row_label VARCHAR(4) NOT NULL,
  seat_number SMALLINT UNSIGNED NOT NULL,
  block_number TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_room_seats_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  UNIQUE KEY uq_room_seat (room_id, seat_code)
);

ALTER TABLE performances ADD COLUMN room_id BIGINT UNSIGNED NULL AFTER show_id;
ALTER TABLE performances ADD COLUMN status ENUM('scheduled', 'cancelled') NOT NULL DEFAULT 'scheduled';
ALTER TABLE performances ADD CONSTRAINT fk_performances_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT;

CREATE TABLE performance_seats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  performance_id BIGINT UNSIGNED NOT NULL,
  room_seat_id BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_performance_seat_performance FOREIGN KEY (performance_id) REFERENCES performances(id) ON DELETE CASCADE,
  CONSTRAINT fk_performance_seat_template FOREIGN KEY (room_seat_id) REFERENCES room_seats(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_performance_seat (performance_id, room_seat_id)
);

CREATE TABLE booking_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  booking_item_id BIGINT UNSIGNED NOT NULL,
  performance_seat_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_item FOREIGN KEY (booking_item_id) REFERENCES booking_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_seat FOREIGN KEY (performance_seat_id) REFERENCES performance_seats(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_ticket_performance_seat (performance_seat_id),
  INDEX idx_ticket_booking (booking_id)
);

INSERT INTO rooms (code, name, room_type, capacity) VALUES
  ('N01', 'Normal Room 01', 'normal', 120), ('N02', 'Normal Room 02', 'normal', 120),
  ('N03', 'Normal Room 03', 'normal', 120), ('N04', 'Normal Room 04', 'normal', 120),
  ('N05', 'Normal Room 05', 'normal', 120), ('N06', 'Normal Room 06', 'normal', 120),
  ('N07', 'Normal Room 07', 'normal', 120), ('N08', 'Normal Room 08', 'normal', 120),
  ('N09', 'Normal Room 09', 'normal', 120), ('N10', 'Normal Room 10', 'normal', 120),
  ('P01', 'Premier Room 01', 'premier', 48), ('P02', 'Premier Room 02', 'premier', 48),
  ('S01', 'Small Room 01', 'small', 60), ('S02', 'Small Room 02', 'small', 60),
  ('S03', 'Small Room 03', 'small', 60), ('S04', 'Small Room 04', 'small', 60),
  ('S05', 'Small Room 05', 'small', 60), ('S06', 'Small Room 06', 'small', 60),
  ('S07', 'Small Room 07', 'small', 60), ('S08', 'Small Room 08', 'small', 60),
  ('S09', 'Small Room 09', 'small', 60), ('S10', 'Small Room 10', 'small', 60);

-- Normal: 10 rows x 12; Premier: 6 rows x 8; Small: 6 rows x 10.
INSERT INTO room_seats (room_id, seat_code, row_label, seat_number, block_number)
SELECT r.id, CONCAT(CHAR(64 + n.row_no), n.seat_no), CHAR(64 + n.row_no), n.seat_no,
       CASE WHEN n.seat_no <= FLOOR(r.capacity / CASE r.room_type WHEN 'normal' THEN 10 WHEN 'premier' THEN 6 ELSE 6 END / 3) THEN 1
            WHEN n.seat_no <= FLOOR(r.capacity / CASE r.room_type WHEN 'normal' THEN 10 WHEN 'premier' THEN 6 ELSE 6 END * 2 / 3) THEN 2 ELSE 3 END
FROM rooms r
JOIN (
  SELECT a.n AS row_no, b.n AS seat_no FROM
  (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) a
  CROSS JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) b
) n
WHERE n.row_no <= CASE r.room_type WHEN 'normal' THEN 10 ELSE 6 END
  AND n.seat_no <= CASE r.room_type WHEN 'normal' THEN 12 WHEN 'premier' THEN 8 ELSE 10 END;

-- Keep real walkways: Normal rooms are 3-6-3, Premier rooms 2-4-2, Small rooms 3-4-3.
UPDATE room_seats rs
JOIN rooms r ON r.id = rs.room_id
SET rs.block_number = CASE r.room_type
  WHEN 'normal' THEN CASE WHEN rs.seat_number <= 3 THEN 1 WHEN rs.seat_number <= 9 THEN 2 ELSE 3 END
  WHEN 'premier' THEN CASE WHEN rs.seat_number <= 2 THEN 1 WHEN rs.seat_number <= 6 THEN 2 ELSE 3 END
  ELSE CASE WHEN rs.seat_number <= 3 THEN 1 WHEN rs.seat_number <= 7 THEN 2 ELSE 3 END
END;

UPDATE performances p
JOIN rooms r ON r.code = CASE p.auditorium WHEN 'Hall 01' THEN 'N01' WHEN 'Hall 02' THEN 'N02' WHEN 'Hall 03' THEN 'N03' ELSE 'N01' END
SET p.room_id = r.id;

INSERT INTO performance_seats (performance_id, room_seat_id)
SELECT p.id, rs.id FROM performances p JOIN room_seats rs ON rs.room_id = p.room_id;

UPDATE performances p SET capacity = (SELECT COUNT(*) FROM performance_seats ps WHERE ps.performance_id = p.id);
