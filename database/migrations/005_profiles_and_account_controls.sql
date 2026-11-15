-- Customer onboarding data and owner-controlled account access.
USE silver_screen;

ALTER TABLE users
  ADD COLUMN date_of_birth DATE NULL AFTER google_subject,
  ADD COLUMN onboarding_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER date_of_birth,
  ADD COLUMN account_status ENUM('active', 'disabled') NOT NULL DEFAULT 'active' AFTER onboarding_complete,
  ADD COLUMN last_login_at TIMESTAMP NULL AFTER account_status;

UPDATE users
SET onboarding_complete = 1
WHERE google_subject IS NOT NULL AND date_of_birth IS NOT NULL;
