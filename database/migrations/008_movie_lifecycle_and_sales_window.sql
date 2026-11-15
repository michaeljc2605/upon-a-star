-- Movie cinema-run dates and the controlled ticket-sales window.
USE silver_screen;

ALTER TABLE shows
  ADD COLUMN run_start_date DATE NULL AFTER status,
  ADD COLUMN run_end_date DATE NULL AFTER run_start_date,
  ADD COLUMN sales_open_date DATE NULL AFTER run_end_date,
  ADD INDEX idx_shows_public_lifecycle (status, run_end_date, run_start_date);

-- Preserve existing catalogue behaviour by deriving each current movie's run
-- from its earliest and latest screening. Movies without screenings remain
-- unconfigured until an owner supplies their lifecycle dates.
UPDATE shows s
JOIN (
  SELECT show_id, DATE(MIN(starts_at)) AS first_screening_date, DATE(MAX(starts_at)) AS last_screening_date
  FROM performances
  GROUP BY show_id
) scheduled ON scheduled.show_id = s.id
SET s.run_start_date = scheduled.first_screening_date,
    s.run_end_date = scheduled.last_screening_date,
    s.sales_open_date = DATE_SUB(scheduled.first_screening_date, INTERVAL 1 MONTH)
WHERE s.run_start_date IS NULL;
