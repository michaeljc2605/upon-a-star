<?php
declare(strict_types=1);

// The hybrid PHP site reuses the traditional pages without changing their
// independent /base/ runtime. Google OAuth stays in the existing Node service.
if (!defined('CINEMA_PHP_ENHANCED')) {
    define('CINEMA_PHP_ENHANCED', true);
}
$sitePath = getenv('PHP_SITE_PATH');
putenv('BASE_PATH=' . ($sitePath === false ? '/SilverVillage' : $sitePath));
$siteDatabase = getenv('PHP_DB_NAME');
putenv('BASE_DB_NAME=' . ($siteDatabase === false ? 'silver_screen' : $siteDatabase));

require_once __DIR__ . '/../base/includes/bootstrap.php';

// Archive a completed run on the next visit without deleting its booking history.
db()->exec(<<<'SQL'
    UPDATE shows s SET s.status = 'archived'
     WHERE s.status = 'published' AND (
       (s.run_end_date IS NOT NULL AND s.run_end_date < CURRENT_DATE)
       OR ((s.run_start_date IS NULL OR s.run_start_date <= CURRENT_DATE)
         AND EXISTS (SELECT 1 FROM performances past
                      WHERE past.show_id = s.id AND past.status = 'scheduled'
                        AND past.history_deleted_at IS NULL
                        AND TIMESTAMPADD(MINUTE, s.duration_minutes, past.starts_at) <= CURRENT_TIMESTAMP)
         AND NOT EXISTS (SELECT 1 FROM performances upcoming
                         WHERE upcoming.show_id = s.id AND upcoming.status = 'scheduled'
                           AND upcoming.history_deleted_at IS NULL
                           AND TIMESTAMPADD(MINUTE, s.duration_minutes, upcoming.starts_at) > CURRENT_TIMESTAMP))
     )
    SQL);

require_once __DIR__ . '/includes/auth.php';
