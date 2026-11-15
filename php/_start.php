<?php
declare(strict_types=1);

// The complete PHP site can use either the original Node service or its
// optional native adapter without changing the independent /base/ runtime.
require_once __DIR__ . '/includes/native-config.php';
if (!defined('CINEMA_PHP_ENHANCED')) {
    define('CINEMA_PHP_ENHANCED', true);
}
php_configure_site();

require_once __DIR__ . '/../base/includes/bootstrap.php';

// Archive a completed run on the next visit without deleting its booking history.
if (!defined('CINEMA_NATIVE_API_REQUEST') || !CINEMA_NATIVE_API_REQUEST) {
    if (php_native_enabled()) {
        require_once __DIR__ . '/includes/rolling-programme.php';
        cinema_rolling_archive_finished(db());
    } else {
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
    }
}

require_once __DIR__ . '/includes/auth.php';
