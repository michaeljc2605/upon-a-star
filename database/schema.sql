CREATE DATABASE IF NOT EXISTS silver_screen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE silver_screen;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  display_name VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('staff', 'manager') NOT NULL DEFAULT 'staff',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash BINARY(32) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sessions_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action_name VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(100) NULL,
  event_data JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
);

CREATE TABLE IF NOT EXISTS shows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  synopsis TEXT NOT NULL,
  genre VARCHAR(120) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  age_rating VARCHAR(20) NOT NULL,
  poster_url VARCHAR(500) NOT NULL,
  backdrop_url VARCHAR(500) NOT NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS performances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  show_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  auditorium VARCHAR(80) NOT NULL,
  format VARCHAR(40) NOT NULL,
  language VARCHAR(60) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  seats_booked SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_performance_show FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE,
  UNIQUE KEY uq_performance_slot (show_id, starts_at, auditorium),
  INDEX idx_performances_start (starts_at),
  INDEX idx_performances_show (show_id)
);

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_reference VARCHAR(80) NOT NULL UNIQUE,
  customer_name VARCHAR(160) NOT NULL,
  customer_email VARCHAR(254) NOT NULL,
  customer_phone VARCHAR(40) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  booking_status ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
  payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  acknowledgement_sent_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_bookings_email (customer_email),
  INDEX idx_bookings_status (payment_status)
);

CREATE TABLE IF NOT EXISTS booking_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  performance_id BIGINT UNSIGNED NOT NULL,
  ticket_quantity TINYINT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_item_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_performance FOREIGN KEY (performance_id) REFERENCES performances(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_booking_performance (booking_id, performance_id)
);

CREATE TABLE IF NOT EXISTS payment_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_event_id VARCHAR(160) NOT NULL UNIQUE,
  booking_id BIGINT UNSIGNED NOT NULL,
  provider_status VARCHAR(40) NOT NULL,
  payload JSON NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
);

INSERT IGNORE INTO shows (title, slug, synopsis, genre, duration_minutes, age_rating, poster_url, backdrop_url, featured) VALUES
  ('Neon Reverie', 'neon-reverie', 'In a city that never sleeps, a sound engineer follows a mysterious signal that changes the rhythm of her life.', 'Sci-fi · Mystery', 118, '13+', 'https://placehold.co/600x900/24133f/f4d6b8?text=NEON+REVERIE', 'https://placehold.co/1800x900/171329/d8a0ff?text=NEON+REVERIE', 1),
  ('The Last Lantern', 'the-last-lantern', 'A quiet, hopeful drama about a family lighthouse keeper facing one final storm together.', 'Drama', 106, 'PG', 'https://placehold.co/600x900/3a245d/f5d9b4?text=THE+LAST+LANTERN', 'https://placehold.co/1800x900/201733/e7b579?text=THE+LAST+LANTERN', 1),
  ('Paper Moons', 'paper-moons', 'Two strangers trade unfinished stories on a night train and discover an unexpected beginning.', 'Romance · Indie', 101, 'PG', 'https://placehold.co/600x900/31214d/f7e8d0?text=PAPER+MOONS', 'https://placehold.co/1800x900/211936/f7c6a2?text=PAPER+MOONS', 0),
  ('Tiny Giants', 'tiny-giants', 'A playful documentary that finds extraordinary worlds in the smallest corners of our planet.', 'Documentary', 92, 'G', 'https://placehold.co/600x900/193f42/d7f0da?text=TINY+GIANTS', 'https://placehold.co/1800x900/173136/b4e0ce?text=TINY+GIANTS', 0);

INSERT IGNORE INTO performances (show_id, starts_at, auditorium, format, language, unit_price, capacity)
SELECT id, '2026-08-22 18:30:00', 'Hall 01', 'LUX', 'English', 85000, 120 FROM shows WHERE slug = 'neon-reverie';
INSERT IGNORE INTO performances (show_id, starts_at, auditorium, format, language, unit_price, capacity)
SELECT id, '2026-08-22 21:15:00', 'Hall 02', 'Atmos', 'English', 95000, 96 FROM shows WHERE slug = 'neon-reverie';
INSERT IGNORE INTO performances (show_id, starts_at, auditorium, format, language, unit_price, capacity)
SELECT id, '2026-08-23 16:00:00', 'Hall 01', 'LUX', 'English', 75000, 120 FROM shows WHERE slug = 'the-last-lantern';
INSERT IGNORE INTO performances (show_id, starts_at, auditorium, format, language, unit_price, capacity)
SELECT id, '2026-08-23 19:30:00', 'Hall 03', 'Standard', 'English', 70000, 80 FROM shows WHERE slug = 'paper-moons';
INSERT IGNORE INTO performances (show_id, starts_at, auditorium, format, language, unit_price, capacity)
SELECT id, '2026-08-24 14:00:00', 'Hall 02', 'Standard', 'English', 60000, 96 FROM shows WHERE slug = 'tiny-giants';
