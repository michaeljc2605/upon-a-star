-- Account-owned bookings, temporary seat holds, and cinema check-in records.
USE silver_screen;

ALTER TABLE bookings
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_booking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_bookings_user (user_id);

ALTER TABLE booking_tickets
  ADD COLUMN checked_in_at TIMESTAMP NULL AFTER created_at,
  ADD COLUMN checked_in_by BIGINT UNSIGNED NULL AFTER checked_in_at,
  ADD CONSTRAINT fk_ticket_checked_in_by FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE seat_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  performance_seat_id BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hold_performance_seat FOREIGN KEY (performance_seat_id) REFERENCES performance_seats(id) ON DELETE CASCADE,
  CONSTRAINT fk_hold_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_hold_expiry (expires_at),
  INDEX idx_hold_user (user_id)
);
