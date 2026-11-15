-- Let owners remove completed screenings from the dashboard without destroying
-- bookings, tickets, payments, or other records that reference the performance.
USE silver_screen;

ALTER TABLE performances
  ADD COLUMN history_deleted_at TIMESTAMP NULL AFTER cancellation_reason,
  ADD INDEX idx_performances_history_deleted (history_deleted_at);
