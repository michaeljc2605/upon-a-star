-- Operational management, staff check-in, and email delivery records.
USE silver_screen;

ALTER TABLE booking_tickets
  ADD COLUMN ticket_code VARCHAR(40) NULL AFTER id,
  ADD UNIQUE KEY uq_ticket_code (ticket_code);

UPDATE booking_tickets
SET ticket_code = CONCAT('SVT-', LPAD(id, 8, '0'))
WHERE ticket_code IS NULL;

ALTER TABLE booking_tickets MODIFY ticket_code VARCHAR(40) NOT NULL;

ALTER TABLE performances
  ADD COLUMN cancelled_at TIMESTAMP NULL AFTER status,
  ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER cancelled_at;

CREATE TABLE email_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  recipient_email VARCHAR(254) NOT NULL,
  delivery_status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
  provider_message VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  CONSTRAINT fk_email_delivery_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  INDEX idx_email_delivery_booking (booking_id)
);
