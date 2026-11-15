-- Reusable visual/animation templates selected by the owner for each movie.
USE silver_screen;

ALTER TABLE shows
  ADD COLUMN theme_key VARCHAR(40) NOT NULL DEFAULT 'enchanted_castle' AFTER sales_open_date;
