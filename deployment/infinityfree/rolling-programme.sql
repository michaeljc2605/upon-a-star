-- Select the EXISTING cinema database in phpMyAdmin before importing this file.
-- Incremental opt-in only: no database selection, drops, or booking replacement.
CREATE TABLE IF NOT EXISTS rolling_programme_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  batch_days TINYINT UNSIGNED NOT NULL DEFAULT 2,
  lead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  prune_enabled TINYINT(1) NOT NULL DEFAULT 1,
  generated_through DATE DEFAULT NULL,
  last_maintained_at DATETIME DEFAULT NULL,
  initialized_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rolling_programme_templates (
  show_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  format VARCHAR(40) NOT NULL,
  language VARCHAR(60) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_rolling_template_show FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE,
  CONSTRAINT fk_rolling_template_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rolling_programme_slots (
  template_show_id BIGINT UNSIGNED NOT NULL,
  slot_date DATE NOT NULL,
  performance_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (template_show_id, slot_date),
  UNIQUE KEY uq_rolling_performance (performance_id),
  CONSTRAINT fk_rolling_slot_template FOREIGN KEY (template_show_id) REFERENCES rolling_programme_templates(show_id) ON DELETE CASCADE,
  CONSTRAINT fk_rolling_slot_performance FOREIGN KEY (performance_id) REFERENCES performances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET time_zone = '+08:00';
START TRANSACTION;
INSERT INTO rolling_programme_settings (id) VALUES (1)
  ON DUPLICATE KEY UPDATE id = VALUES(id);

-- These 18 film/room assignments match the actual public import used for the
-- November 2026 launch. Keep the assigned room's existing format, language and
-- price; use its next screening, falling back to its latest historical one.
-- A fixed 18:00 daily slot is a new rolling timetable, not a moved reservation.
INSERT INTO rolling_programme_templates (show_id, room_id, start_time, format, language, unit_price)
SELECT s.id, r.id, '18:00:00', p.format, p.language, p.unit_price
FROM (
  SELECT 'toy-story' AS slug, 'N01' AS room_code UNION ALL
  SELECT 'beauty-and-the-beast', 'P02' UNION ALL
  SELECT 'peter-pan', 'N05' UNION ALL
  SELECT 'wreck-it-ralph', 'P01' UNION ALL
  SELECT 'inside-out', 'N09' UNION ALL
  SELECT 'monsters-university', 'N02' UNION ALL
  SELECT 'up', 'N03' UNION ALL
  SELECT 'ratatouille', 'N04' UNION ALL
  SELECT 'the-princess-and-the-frog', 'N06' UNION ALL
  SELECT 'the-incredibles', 'N07' UNION ALL
  SELECT 'coco', 'N08' UNION ALL
  SELECT 'soul', 'N10' UNION ALL
  SELECT 'luca', 'S01' UNION ALL
  SELECT 'encanto', 'S02' UNION ALL
  SELECT 'turning-red', 'S03' UNION ALL
  SELECT 'elemental', 'S04' UNION ALL
  SELECT 'onward', 'S05' UNION ALL
  SELECT 'a-bugs-life', 'S06'
) plan
JOIN shows s ON s.slug = plan.slug AND s.status = 'published'
JOIN rooms r ON r.code = plan.room_code AND r.active = 1
JOIN performances p ON p.id = (
  SELECT candidate.id FROM performances candidate
  WHERE candidate.show_id = s.id AND candidate.room_id = r.id
  ORDER BY (candidate.starts_at >= CURRENT_TIMESTAMP) DESC,
    CASE WHEN candidate.starts_at >= CURRENT_TIMESTAMP THEN candidate.starts_at END ASC,
    candidate.starts_at DESC, candidate.id DESC LIMIT 1
)
WHERE (SELECT initialized_at FROM rolling_programme_settings WHERE id = 1) IS NULL
ON DUPLICATE KEY UPDATE show_id = VALUES(show_id);

-- First import only. Re-importing does not undo a later owner pause or date edit.
UPDATE shows s JOIN rolling_programme_templates t ON t.show_id = s.id
SET s.run_end_date = '2099-12-31'
WHERE s.status = 'published'
  AND (SELECT initialized_at FROM rolling_programme_settings WHERE id = 1) IS NULL
  AND (SELECT COUNT(*) FROM rolling_programme_templates) = 18;
UPDATE rolling_programme_settings
SET enabled = 1, initialized_at = CURRENT_TIMESTAMP
WHERE id = 1 AND initialized_at IS NULL
  AND (SELECT COUNT(*) FROM rolling_programme_templates) = 18;
COMMIT;

-- Expected first-run result: enabled=1, template_count=18. A different result
-- leaves scheduling disabled and needs a template review; never re-import data.
SELECT enabled, horizon_days, batch_days, prune_enabled, retention_days,
  generated_through, initialized_at,
  (SELECT COUNT(*) FROM rolling_programme_templates) AS template_count
FROM rolling_programme_settings WHERE id = 1;
