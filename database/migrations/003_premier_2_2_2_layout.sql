-- Premier rooms use three pairs of seats (2-2-2), separated by two walkways.
-- Verified before applying: no Premier seats 7 or 8 had been sold.
USE silver_screen;

DELETE ps
FROM performance_seats ps
JOIN room_seats rs ON rs.id = ps.room_seat_id
JOIN rooms r ON r.id = rs.room_id
WHERE r.room_type = 'premier' AND rs.seat_number > 6;

DELETE rs
FROM room_seats rs
JOIN rooms r ON r.id = rs.room_id
WHERE r.room_type = 'premier' AND rs.seat_number > 6;

UPDATE rooms
SET capacity = 36
WHERE room_type = 'premier';

UPDATE room_seats rs
JOIN rooms r ON r.id = rs.room_id
SET rs.block_number = CASE
  WHEN rs.seat_number <= 2 THEN 1
  WHEN rs.seat_number <= 4 THEN 2
  ELSE 3
END
WHERE r.room_type = 'premier';

UPDATE performances p
JOIN rooms r ON r.id = p.room_id
SET p.capacity = (
  SELECT COUNT(*) FROM performance_seats ps WHERE ps.performance_id = p.id
)
WHERE r.room_type = 'premier';
