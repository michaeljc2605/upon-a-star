-- Google-authenticated customers have a verified Google subject and email.
USE silver_screen;

ALTER TABLE users
  MODIFY role ENUM('customer', 'staff', 'manager', 'owner') NOT NULL DEFAULT 'customer',
  ADD COLUMN email VARCHAR(254) NULL UNIQUE AFTER display_name,
  ADD COLUMN google_subject VARCHAR(255) NULL UNIQUE AFTER email;
