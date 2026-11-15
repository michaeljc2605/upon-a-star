<?php
declare(strict_types=1);

require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/includes/email.php';
php_require_booking_identity();
require dirname(__DIR__) . '/base/process-booking.php';
