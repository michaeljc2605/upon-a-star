<?php
declare(strict_types=1);
require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/../base/includes/layout.php';

header('Cache-Control: private, no-store');
$user = php_current_user();
$staffRoles = ['owner', 'staff', 'manager'];
$canUseStaffTools = is_array($user) && in_array($user['role'], $staffRoles, true);
$isOwner = $canUseStaffTools && $user['role'] === 'owner';

function staff_flash(string $type, string $message): void
{
    $_SESSION['php_staff_flash'] = ['type' => $type, 'message' => $message];
}

function staff_redirect(string $query = '', string $fragment = '', array $parameters = []): void
{
    $url = base_url('staff.php');
    if ($query !== '') {
        $parameters['q'] = $query;
    }
    if ($parameters) {
        $url .= '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
    $url .= $fragment;
    header('Location: ' . $url, true, 303);
    exit;
}

/** Keep an owner on the same schedule date after a normal POST/redirect. */
function staff_schedule_return(array $input): array
{
    $parameters = [];
    if (in_array($input['return_schedule_view'] ?? null, ['upcoming', 'past', 'cancelled'], true)) {
        $parameters['schedule_view'] = $input['return_schedule_view'];
    }
    if (is_string($input['return_schedule_date'] ?? null)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input['return_schedule_date'])) {
        $parameters['schedule_date'] = $input['return_schedule_date'];
    }
    if (is_string($input['return_schedule_movie'] ?? null)
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $input['return_schedule_movie'])) {
        $parameters['schedule_movie'] = $input['return_schedule_movie'];
    }
    $page = filter_var($input['return_screening_page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
    if ($page !== false && $page !== null) {
        $parameters['screening_page'] = $page;
    }
    return $parameters;
}

function staff_artwork_available(string $path, string $kind): bool
{
    if (preg_match('~^/assets/movies/[a-z0-9]+(?:-[a-z0-9]+)*/' . $kind . '\.webp$~D', $path)) {
        return is_file(dirname(__DIR__) . '/base' . $path);
    }
    $parts = parse_url($path);
    return filter_var($path, FILTER_VALIDATE_URL) !== false
        && is_array($parts)
        && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function staff_api_message(array $result, string $fallback): string
{
    $message = $result['data']['error'] ?? null;
    return is_string($message) && $message !== '' ? $message : $fallback;
}

function staff_date_time($value, string $format = 'D, j M Y · g:i A'): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Singapore')))->setTimezone(new DateTimeZone('Asia/Singapore'))->format($format);
    } catch (Throwable $exception) {
        return $value;
    }
}

function staff_datetime_local($value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Singapore')))->setTimezone(new DateTimeZone('Asia/Singapore'))->format('Y-m-d\\TH:i');
    } catch (Throwable $exception) {
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lookupAfter = is_string($_POST['lookup'] ?? null) ? trim($_POST['lookup']) : '';
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Your form session expired. Return to the staff dashboard and try again.');
    }
    if (!$canUseStaffTools) {
        http_response_code(403);
        exit('Staff access required.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $result = ['status' => 400, 'data' => ['error' => 'Choose a valid staff action.']];
    $successMessage = '';
    $returnQuery = $lookupAfter;

    if ($action === 'scan_ticket') {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        if (booking_reference_is_valid($code)) {
            staff_flash('info', 'Booking reference scanned. Check its seats below. Scan an individual seat QR to check in a guest.');
            staff_redirect($code, '#entry-desk');
        }
        $result = php_api_request('POST', '/api/operations/scan-ticket', ['code' => $code]);
        if ($result['status'] === 200) {
            $state = $result['data']['status'] ?? '';
            $ticket = $result['data']['ticket'] ?? [];
            if (is_array($ticket) && in_array($state, ['accepted', 'used'], true)) {
                $_SESSION['php_staff_scan_result'] = ['status' => $state, 'ticket' => $ticket];
            }
            $returnQuery = is_string($ticket['ticket_code'] ?? null) ? $ticket['ticket_code'] : $code;
        }
    } elseif ($action === 'check_in') {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($ticketId !== false) {
            $result = php_api_request('PATCH', '/api/operations/tickets/' . $ticketId . '/check-in', []);
        }
        if ($result['status'] === 200) {
            $successMessage = 'Ticket checked in.';
        }
    } elseif ($action === 'change_account' && $isOwner) {
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $body = [];
        $role = $_POST['role'] ?? null;
        $accountStatus = $_POST['account_status'] ?? null;
        if ($role !== null && in_array($role, ['customer', 'staff', 'owner'], true)) {
            $body['role'] = $role;
        } elseif ($accountStatus !== null && in_array($accountStatus, ['active', 'disabled'], true)) {
            $body['accountStatus'] = $accountStatus;
        }
        if ($accountId !== false && $body) {
            $result = php_api_request('PATCH', '/api/admin/accounts/' . $accountId, $body);
        }
        if ($result['status'] === 200) {
            $successMessage = 'Account access updated.';
        }
    } elseif ($action === 'create_movie' && $isOwner) {
        $body = [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'synopsis' => trim((string) ($_POST['synopsis'] ?? '')),
            'genre' => trim((string) ($_POST['genre'] ?? '')),
            'durationMinutes' => (int) ($_POST['duration_minutes'] ?? 0),
            'ageRating' => trim((string) ($_POST['age_rating'] ?? '')),
            'posterUrl' => trim((string) ($_POST['poster_url'] ?? '')),
            'backdropUrl' => trim((string) ($_POST['backdrop_url'] ?? '')),
            'trailerUrl' => trim((string) ($_POST['trailer_url'] ?? '')),
            'themeKey' => (string) ($_POST['theme_key'] ?? 'enchanted_castle'),
            'status' => (string) ($_POST['movie_status'] ?? 'draft'),
            'featured' => isset($_POST['featured']),
            'runStartDate' => trim((string) ($_POST['run_start_date'] ?? '')),
            'runEndDate' => trim((string) ($_POST['run_end_date'] ?? '')),
            'salesOpenDate' => trim((string) ($_POST['sales_open_date'] ?? '')),
        ];
        if (!staff_artwork_available($body['posterUrl'], 'poster')
            || !staff_artwork_available($body['backdropUrl'], 'backdrop')) {
            $result = ['status' => 400, 'data' => ['error' => 'Use a direct HTTP(S) image URL, or add a local WebP file and enter its /assets/movies/slug/poster.webp or backdrop.webp path.']];
        } else {
            $result = php_api_request('POST', '/api/admin/movies', $body);
        }
        if ($result['status'] === 201) {
            $successMessage = 'Movie saved.';
        }
    } elseif ($action === 'create_screening' && $isOwner) {
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        try {
            $date = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Singapore'));
            $isoStartsAt = $date->setTimezone(new DateTimeZone('Asia/Singapore'))->format(DateTimeInterface::ATOM);
        } catch (Throwable $exception) {
            $isoStartsAt = '';
        }
        $result = php_api_request('POST', '/api/admin/screenings', [
            'showId' => (int) ($_POST['show_id'] ?? 0),
            'roomId' => (int) ($_POST['room_id'] ?? 0),
            'startsAt' => $isoStartsAt,
            'format' => trim((string) ($_POST['format'] ?? 'Standard')),
            'language' => trim((string) ($_POST['language'] ?? 'English')),
            'unitPrice' => (float) ($_POST['unit_price'] ?? 0),
        ]);
        if ($result['status'] === 201) {
            $successMessage = 'Screening scheduled.';
        }
    } elseif ($action === 'update_screening' && $isOwner) {
        $screeningId = filter_var($_POST['screening_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $body = [];
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        if ($startsAt !== '') {
            try {
                $date = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Singapore'));
                $body['startsAt'] = $date->setTimezone(new DateTimeZone('Asia/Singapore'))->format(DateTimeInterface::ATOM);
            } catch (Throwable $exception) {
                $body['startsAt'] = '';
            }
        }
        if (filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $body['roomId'] = (int) $_POST['room_id'];
        }
        if (is_string($_POST['format'] ?? null) && trim($_POST['format']) !== '') {
            $body['format'] = trim($_POST['format']);
        }
        if (is_string($_POST['language'] ?? null) && trim($_POST['language']) !== '') {
            $body['language'] = trim($_POST['language']);
        }
        if (is_string($_POST['unit_price'] ?? null) && $_POST['unit_price'] !== '') {
            $body['unitPrice'] = (float) $_POST['unit_price'];
        }
        if (in_array($_POST['screening_status'] ?? null, ['scheduled', 'cancelled'], true)) {
            $body['status'] = $_POST['screening_status'];
        }
        $body['cancellationReason'] = trim((string) ($_POST['cancellation_reason'] ?? ''));
        if ($screeningId !== false && $body) {
            $result = php_api_request('PATCH', '/api/admin/screenings/' . $screeningId, $body);
        }
        if ($result['status'] === 200) {
            $successMessage = 'Screening updated.';
        }
    } elseif ($action === 'cancel_screening' && $isOwner) {
        $screeningId = filter_var($_POST['screening_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = is_string($_POST['cancellation_reason'] ?? null) ? trim($_POST['cancellation_reason']) : '';
        if ($screeningId !== false && $screeningId !== null) {
            $check = db()->prepare('SELECT status, seats_booked, starts_at > CURRENT_TIMESTAMP AS future_start FROM performances WHERE id = ? AND history_deleted_at IS NULL');
            $check->execute([$screeningId]);
            $screening = $check->fetch();
            if (!$screening || $screening['status'] !== 'scheduled' || (int) $screening['future_start'] !== 1) {
                $result = ['status' => 409, 'data' => ['error' => 'Only an upcoming scheduled screening can be removed from the public schedule.']];
            } elseif ((int) $screening['seats_booked'] > 0 && ($reason === '' || ($_POST['acknowledge_bookings'] ?? null) !== '1')) {
                $result = ['status' => 400, 'data' => ['error' => 'This screening has booked seats. Enter a cancellation reason and acknowledge that existing tickets will no longer be usable.']];
            } else {
                $result = php_api_request('PATCH', '/api/admin/screenings/' . $screeningId, [
                    'status' => 'cancelled',
                    'cancellationReason' => $reason !== '' ? $reason : 'Removed from the public schedule by the owner.',
                ]);
            }
        }
        if ($result['status'] === 200) {
            $successMessage = 'Screening removed from the public schedule. Its history is retained.';
        }
    } elseif ($action === 'update_movie' && $isOwner) {
        $movieId = filter_var($_POST['movie_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = $_POST['movie_status'] ?? null;
        if ($movieId !== false && in_array($status, ['draft', 'published', 'archived'], true)) {
            $body = ['status' => $status];
            foreach (['run_start_date' => 'runStartDate', 'run_end_date' => 'runEndDate', 'sales_open_date' => 'salesOpenDate'] as $field => $apiField) {
                if (is_string($_POST[$field] ?? null) && trim($_POST[$field]) !== '') {
                    $body[$apiField] = trim($_POST[$field]);
                }
            }
            $result = php_api_request('PATCH', '/api/admin/movies/' . $movieId, $body);
        }
        if ($result['status'] === 200) {
            $successMessage = 'Movie status and dates updated.';
        }
    }

    if ($action === 'scan_ticket' && $result['status'] === 200) {
        staff_redirect($returnQuery, '#entry-desk');
    }
    if ($successMessage !== '') {
        staff_flash('success', $successMessage);
    } else {
        staff_flash('error', staff_api_message($result, 'The staff action could not be completed.'));
    }
    $scheduleAction = in_array($action, ['update_screening', 'cancel_screening'], true);
    $createdScreening = $action === 'create_screening' && $successMessage !== '';
    $returnParameters = $scheduleAction ? staff_schedule_return($_POST) : [];
    if ($action === 'create_movie' && $successMessage !== '') {
        $newMovieId = filter_var($result['data']['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($newMovieId !== false && $newMovieId !== null) {
            $returnParameters['new_movie'] = $newMovieId;
        }
    }
    if ($createdScreening) {
        $startsAt = is_string($_POST['starts_at'] ?? null) ? $_POST['starts_at'] : '';
        $returnParameters = ['schedule_view' => 'upcoming'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $startsAt)) {
            $returnParameters['schedule_date'] = substr($startsAt, 0, 10);
        }
    }
    staff_redirect(
        $returnQuery,
        $action === 'scan_ticket' ? '#entry-desk' : (($scheduleAction || $createdScreening) ? '#screenings' : (in_array($action, ['create_screening', 'create_movie'], true) ? '#owner-tools' : '')),
        $returnParameters
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$flash = $_SESSION['php_staff_flash'] ?? null;
unset($_SESSION['php_staff_flash']);
$flash = is_array($flash) ? $flash : null;
$scanResult = $_SESSION['php_staff_scan_result'] ?? null;
unset($_SESSION['php_staff_scan_result']);
$scanResult = is_array($scanResult) ? $scanResult : null;
$lookup = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$lookupResult = null;
$lookupError = '';
$ownerData = [];

if ($canUseStaffTools && $lookup !== '') {
    if (mb_strlen($lookup) < 3 || mb_strlen($lookup) > 254) {
        $lookupError = 'Enter at least 3 characters to look up a booking, ticket, or email.';
    } else {
        $lookupResult = php_api_request('GET', '/api/operations/ticket-lookup?q=' . rawurlencode($lookup));
        if ($lookupResult['status'] !== 200) {
            $lookupError = staff_api_message($lookupResult, 'The booking lookup is unavailable.');
        }
    }
}

if ($isOwner) {
    $ownerData = [
        'summary' => php_api_request('GET', '/api/admin/summary'),
        'screenings' => php_api_request('GET', '/api/admin/screenings'),
        'rooms' => php_api_request('GET', '/api/admin/rooms'),
        'movies' => php_api_request('GET', '/api/admin/movies'),
        'accounts' => php_api_request('GET', '/api/admin/accounts'),
        'bookings' => php_api_request('GET', '/api/admin/bookings'),
    ];
}

$summaryData = $ownerData['summary']['data'] ?? [];
$summary = is_array($summaryData['summary'] ?? null) ? $summaryData['summary'] : null;
$nextScreening = is_array($summaryData['nextScreening'] ?? null) ? $summaryData['nextScreening'] : null;
$screenings = $ownerData['screenings']['data']['screenings'] ?? [];
$screenings = is_array($screenings) ? $screenings : [];
$rooms = $ownerData['rooms']['data']['rooms'] ?? [];
$movies = $ownerData['movies']['data']['movies'] ?? [];
$rooms = is_array($rooms) ? $rooms : [];
$movies = is_array($movies) ? $movies : [];
$newMovieId = filter_var($_GET['new_movie'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$movieById = [];
$movieBySlug = [];
foreach ($movies as $movie) {
    if (!is_array($movie)) continue;
    $movieById[(int) ($movie['id'] ?? 0)] = $movie;
    $movieBySlug[(string) ($movie['slug'] ?? '')] = $movie;
}
$scheduleView = in_array($_GET['schedule_view'] ?? null, ['upcoming', 'past', 'cancelled'], true)
    ? (string) $_GET['schedule_view'] : 'upcoming';
$requestedScheduleMovie = is_string($_GET['schedule_movie'] ?? null) ? $_GET['schedule_movie'] : '';
$scheduleMovie = isset($movieBySlug[$requestedScheduleMovie]) ? $requestedScheduleMovie : '';
$scheduleViewCounts = ['upcoming' => 0, 'past' => 0, 'cancelled' => 0];
$scheduleDayCounts = [];
$filteredScreenings = [];
$now = time();
foreach ($screenings as $screening) {
    if (!is_array($screening)) continue;
    $movie = $movieById[(int) ($screening['show_id'] ?? 0)] ?? null;
    if ($scheduleMovie !== '' && ($movie['slug'] ?? null) !== $scheduleMovie) continue;
    $startsAt = (string) ($screening['starts_at'] ?? '');
    $startTimestamp = strtotime($startsAt);
    if ($startTimestamp === false) continue;
    $view = ($screening['status'] ?? '') === 'cancelled' ? 'cancelled' : ($startTimestamp >= $now ? 'upcoming' : 'past');
    $scheduleViewCounts[$view]++;
    if ($view !== $scheduleView) continue;
    $date = date('Y-m-d', $startTimestamp);
    $screening['schedule_date'] = $date;
    $screening['schedule_time'] = date('H:i', $startTimestamp);
    $screening['poster_url'] = is_array($movie) ? ($movie['poster_url'] ?? null) : null;
    $screening['movie_slug'] = is_array($movie) ? ($movie['slug'] ?? '') : '';
    $filteredScreenings[] = $screening;
    $scheduleDayCounts[$date] = ($scheduleDayCounts[$date] ?? 0) + 1;
}
ksort($scheduleDayCounts);
$scheduleDates = array_keys($scheduleDayCounts);
$monthFirstDates = [];
foreach ($scheduleDates as $date) {
    $month = substr($date, 0, 7);
    if (!isset($monthFirstDates[$month])) $monthFirstDates[$month] = $date;
}
$requestedScheduleDate = is_string($_GET['schedule_date'] ?? null) ? $_GET['schedule_date'] : '';
$scheduleDateNotice = '';
if (in_array($requestedScheduleDate, $scheduleDates, true)) {
    $selectedScheduleDate = $requestedScheduleDate;
} elseif ($requestedScheduleDate !== '' && $scheduleDates) {
    $selectedScheduleDate = $scheduleDates[count($scheduleDates) - 1];
    foreach ($scheduleDates as $date) {
        if ($date >= $requestedScheduleDate) {
            $selectedScheduleDate = $date;
            break;
        }
    }
    $scheduleDateNotice = 'That date has no screenings in this view. Showing the nearest available day.';
} else {
    $selectedScheduleDate = $scheduleView === 'upcoming' ? ($scheduleDates[0] ?? null) : ($scheduleDates ? $scheduleDates[count($scheduleDates) - 1] : null);
}
$selectedDateIndex = $selectedScheduleDate === null ? false : array_search($selectedScheduleDate, $scheduleDates, true);
$previousScheduleDate = is_int($selectedDateIndex) && $selectedDateIndex > 0 ? $scheduleDates[$selectedDateIndex - 1] : null;
$nextScheduleDate = is_int($selectedDateIndex) && $selectedDateIndex < count($scheduleDates) - 1 ? $scheduleDates[$selectedDateIndex + 1] : null;
$dayScreenings = array_values(array_filter($filteredScreenings, static fn (array $row): bool => $row['schedule_date'] === $selectedScheduleDate));
usort($dayScreenings, static fn (array $left, array $right): int => strcmp((string) $left['starts_at'], (string) $right['starts_at']) ?: ((int) $left['id'] <=> (int) $right['id']));
$screeningPageSize = 48;
$screeningPageCount = max(1, (int) ceil(count($dayScreenings) / $screeningPageSize));
$requestedScreeningPage = filter_input(INPUT_GET, 'screening_page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$screeningPage = min($requestedScreeningPage ?: 1, $screeningPageCount);
$visibleScreenings = array_slice($dayScreenings, ($screeningPage - 1) * $screeningPageSize, $screeningPageSize);
$screeningTimeSlots = [];
foreach ($visibleScreenings as $screening) $screeningTimeSlots[(string) $screening['schedule_time']][] = $screening;
$scheduleUrl = static function (string $view, ?string $date, string $movie = '', int $page = 1): string {
    $query = ['schedule_view' => $view];
    if ($date !== null) $query['schedule_date'] = $date;
    if ($movie !== '') $query['schedule_movie'] = $movie;
    if ($page > 1) $query['screening_page'] = $page;
    return base_url('staff.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)) . '#screenings';
};
$accounts = $ownerData['accounts']['data']['accounts'] ?? [];
$recentBookings = $ownerData['bookings']['data']['bookings'] ?? [];
$ownerDataUnavailable = $isOwner && (bool) array_filter($ownerData, static fn (array $response): bool => ($response['status'] ?? 0) !== 200);

page_header($canUseStaffTools ? 'Staff dashboard' : 'Staff access', 'staff');
?>
<main class="section staff-page">
  <?php if ($user === null): ?>
    <div class="heading"><div><p class="eyebrow">Private operations</p><h1>Staff sign-in <em>required.</em></h1><span>Sign in with the Google account assigned staff access to this cinema.</span></div></div>
    <section class="panel staff-panel"><a class="primary" href="<?= h(php_signin_url()) ?>">Sign in with Google →</a></section>
  <?php elseif (!$canUseStaffTools): ?>
    <?php http_response_code(403); ?>
    <div class="heading"><div><p class="eyebrow">Private operations</p><h1>Staff access <em>required.</em></h1><span>This Google account does not have staff access. Ask a cinema owner to update the account role.</span></div></div>
    <section class="panel staff-panel"><p>Signed in as <?= h($user['email']) ?>.</p><a class="text-link" href="<?= h(base_url('profile.php')) ?>">Return to your profile →</a></section>
  <?php else: ?>
    <div class="heading"><div><p class="eyebrow">Private operations · <?= h($user['role']) ?></p><h1><?= $isOwner ? 'Cinema owner <em>dashboard.</em>' : 'Booking <em>desk.</em>' ?></h1><span>Ticket checking and booking support, inside the same Upon a Star site.</span></div></div>
    <?php if ($isOwner): ?><nav class="staff-area-tabs" aria-label="Owner tools"><a href="<?= h(base_url('staff.php')) ?>" aria-current="page">Staff dashboard</a><a href="#owner-tools">Add a movie or screening</a><a href="#screenings">Manage schedule</a><a href="<?= h(base_url('attendance.php')) ?>">Guest attendance</a></nav><?php endif; ?>
    <?php if ($flash && is_string($flash['message'] ?? null)): ?><p class="notice staff-notice <?= h($flash['type'] ?? 'info') ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>
    <?php if ($ownerDataUnavailable): ?><p class="notice staff-notice error" role="alert">Some owner tools could not load. Check that the local account service is running, then refresh this page.</p><?php endif; ?>

    <?php if ($isOwner && $summary): ?>
      <section class="staff-summary" aria-label="Today's cinema summary">
        <article><span>Today’s screenings</span><strong><?= h($summary['screenings_today'] ?? 0) ?></strong></article>
        <article><span>Seats booked</span><strong><?= h($summary['seats_booked_today'] ?? 0) ?></strong></article>
        <article><span>Seats checked in</span><strong><?= h($summary['seats_checked_in_today'] ?? 0) ?></strong></article>
        <article class="next-screening"><span>Next screening</span><strong><?= h($nextScreening ? ($nextScreening['title'] . ' · ' . staff_date_time($nextScreening['starts_at'] ?? null)) : 'None scheduled') ?></strong><small><?= h($nextScreening['room_name'] ?? '') ?></small></article>
      </section>
    <?php endif; ?>

    <section class="panel staff-panel" id="entry-desk">
      <div class="staff-section-heading"><div><p class="eyebrow">Ticket checking</p><h2>Entry desk</h2></div><span>Enter the ticket code from a QR scan or look up a booking reference, ticket code, or customer email.</span></div>
      <?php if ($scanResult && is_array($scanResult['ticket'] ?? null)): $scannedTicket = $scanResult['ticket']; $scanAccepted = ($scanResult['status'] ?? '') === 'accepted'; ?>
        <div class="staff-scan-result <?= $scanAccepted ? 'accepted' : 'used' ?>" role="status" aria-live="polite">
          <strong><?= $scanAccepted ? 'Entry approved · ticket checked in' : 'Entry denied · ticket already used' ?></strong>
          <p><?= h($scannedTicket['title'] ?? '') ?> · <?= h(staff_date_time($scannedTicket['starts_at'] ?? null)) ?> · <?= h($scannedTicket['room_name'] ?? '') ?> · Seat <?= h($scannedTicket['seat_code'] ?? '') ?></p>
          <p><?= h($scannedTicket['customer_name'] ?? '') ?> · Booking <?= h($scannedTicket['public_reference'] ?? '') ?></p>
          <small>Ticket <?= h($scannedTicket['ticket_code'] ?? '') ?> · Checked in <?= h(staff_date_time($scannedTicket['checked_in_at'] ?? null)) ?></small>
        </div>
      <?php endif; ?>
      <div class="staff-camera-scan">
        <button class="secondary" id="staff-scan-camera" type="button">Scan QR</button>
        <p id="staff-scan-status" aria-live="polite">Scan a seat QR to show its details and check it in immediately. Each code works once. Booking references open a lookup.</p>
        <div id="staff-qr-reader" hidden></div>
      </div>
      <form class="staff-scan-form" id="staff-scan-form" method="post" action="<?= h(base_url('staff.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="scan_ticket">
        <input type="hidden" name="lookup" value="<?= h($lookup) ?>">
        <label for="scan-code">Seat ticket code</label>
        <div class="staff-inline-form"><input id="scan-code" name="code" type="text" minlength="4" maxlength="80" autocomplete="off" placeholder="SVT-…" required><button class="primary" type="submit">Check in ticket →</button></div>
      </form>
      <form class="staff-lookup-form" method="get" action="<?= h(base_url('staff.php')) ?>#entry-desk">
        <label for="booking-lookup">Look up a booking</label>
        <div class="staff-inline-form"><input id="booking-lookup" name="q" type="search" minlength="3" maxlength="254" value="<?= h($lookup) ?>" placeholder="SV-… / SVT-… / customer@email.com" required><button class="secondary" type="submit">Look up →</button></div>
      </form>
      <?php if ($lookupError !== ''): ?><p class="notice staff-notice error" role="alert"><?= h($lookupError) ?></p><?php endif; ?>
      <?php if ($lookupResult && $lookupResult['status'] === 200): ?>
        <?php $foundBookings = $lookupResult['data']['bookings'] ?? []; ?>
        <?php if (!$foundBookings): ?><p class="staff-empty">No matching booking or ticket was found.</p><?php endif; ?>
        <?php foreach ($foundBookings as $booking): if (!is_array($booking)) continue; ?>
          <article class="staff-booking-detail">
            <div class="staff-booking-heading"><div><p class="eyebrow">Booking <?= h($booking['reference'] ?? '') ?></p><h3><?= h($booking['customerName'] ?? 'Customer') ?></h3><span><?= h($booking['customerEmail'] ?? '') ?> · <?= h($booking['customerPhone'] ?: 'No phone') ?></span></div><span class="staff-payment-state"><?= h($booking['bookingStatus'] ?? '') ?></span></div>
            <?php foreach (($booking['items'] ?? []) as $item): if (!is_array($item)) continue; $available = !empty($item['ticketAvailable']) && ($booking['bookingStatus'] ?? '') === 'confirmed'; ?>
              <div class="staff-booking-item">
                <strong><?= h($item['title'] ?? '') ?></strong><span><?= h(staff_date_time($item['startsAt'] ?? null)) ?> · <?= h($item['roomName'] ?? '') ?> · <?= h($item['screeningStatus'] ?? '') ?></span>
                <?php if (!$available): ?><p class="ticket-unavailable-reason">Unavailable · <?= h($item['ticketUnavailableReason'] ?? 'Ticket is not valid for entry.') ?></p><?php endif; ?>
                <div class="staff-ticket-list">
                  <?php foreach (($item['tickets'] ?? []) as $ticket): if (!is_array($ticket)) continue; ?>
                    <div class="staff-ticket-row"><span><b>Seat <?= h($ticket['seat'] ?? '') ?></b><small><?= h($ticket['code'] ?? '') ?></small></span>
                      <?php if (!empty($ticket['checkedInAt'])): ?><span class="staff-used">Checked in · <?= h(staff_date_time($ticket['checkedInAt'])) ?></span>
                      <?php elseif (!$available): ?><span class="staff-used">Unavailable</span>
                      <?php else: ?>
                        <form method="post" action="<?= h(base_url('staff.php')) ?>"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check_in"><input type="hidden" name="ticket_id" value="<?= h($ticket['id'] ?? '') ?>"><input type="hidden" name="lookup" value="<?= h($lookup) ?>"><button class="secondary" type="submit">Check in</button></form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php if ($isOwner): ?>
      <section class="staff-owner-grid" id="owner-tools">
        <form class="panel staff-form" method="post" action="<?= h(base_url('staff.php')) ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_movie">
          <p class="eyebrow">Programme</p><h2>Add a movie</h2>
          <label>Title<input name="title" minlength="2" maxlength="180" required></label>
          <label>URL slug<input name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" required></label>
          <label>Synopsis<textarea name="synopsis" minlength="10" required></textarea></label>
          <div class="staff-form-row"><label>Genre<input name="genre" required></label><label>Duration in minutes<input name="duration_minutes" type="number" min="30" max="360" value="90" required></label><label>Age rating<input name="age_rating" value="PG" required></label></div>
          <label>Poster image URL<input name="poster_url" placeholder="/assets/movies/slug/poster.webp" required></label>
          <label>Backdrop image URL<input name="backdrop_url" placeholder="/assets/movies/slug/backdrop.webp" required></label>
          <label>Trailer URL<input name="trailer_url" type="url"></label>
          <div class="staff-form-row"><label>Run starts<input name="run_start_date" type="date" required></label><label>Run ends<input name="run_end_date" type="date" required></label><label>Sales open<input name="sales_open_date" type="date"></label></div>
          <label>Artwork theme<select name="theme_key"><option value="enchanted_castle">Enchanted castle</option><option value="legends_and_courage">Legends and courage</option><option value="ocean_and_islands">Ocean and islands</option><option value="hero_city">Hero city</option><option value="tech_and_arcade">Tech and arcade</option><option value="worlds_of_imagination">Worlds of imagination</option><option value="sky_adventure">Sky adventure</option><option value="culinary_magic">Culinary magic</option><option value="animal_worlds">Animal worlds</option></select></label>
          <label>Status<select name="movie_status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
          <label class="staff-check"><input name="featured" type="checkbox" value="1"> Feature on the home page</label>
          <button class="primary" type="submit">Save movie →</button>
        </form>

        <form class="panel staff-form" method="post" action="<?= h(base_url('staff.php')) ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_screening">
          <p class="eyebrow">Schedule</p><h2>Allocate a room</h2>
          <label>Movie<select name="show_id" required><option value="">Choose a movie</option><?php foreach ($movies as $movie): ?><option value="<?= h($movie['id']) ?>"<?= $newMovieId !== false && $newMovieId !== null && (int) $movie['id'] === $newMovieId ? ' selected' : '' ?>><?= h($movie['title']) ?><?= ($movie['status'] ?? '') === 'archived' ? ' · archived' : '' ?> · <?= h($movie['run_start_date'] ?? '—') ?>–<?= h($movie['run_end_date'] ?? '—') ?></option><?php endforeach; ?></select></label>
          <label>Room<select name="room_id" required><option value="">Choose a room</option><?php foreach ($rooms as $room): if (empty($room['active'])) continue; ?><option value="<?= h($room['id']) ?>"><?= h($room['name']) ?> · <?= h($room['room_type']) ?></option><?php endforeach; ?></select></label>
          <label>Start time<input name="starts_at" type="datetime-local" required></label>
          <div class="staff-form-row"><label>Format<select name="format"><option>Standard</option><option>3D</option><option>IMAX</option><option>Dolby Atmos</option></select></label><label>Language<select name="language"><option>English</option><option>Bahasa Indonesia</option><option>Mandarin</option><option>Japanese</option><option>Korean</option><option>Other</option></select></label></div>
          <label>Ticket price (Rp)<input name="unit_price" type="number" min="1" step="1" required></label>
          <p class="staff-help">Start times use Singapore time. The API checks the movie run dates and room availability before saving.</p>
          <button class="primary" type="submit">Schedule screening →</button>
        </form>
      </section>

      <section class="panel staff-panel">
        <div class="staff-section-heading"><div><p class="eyebrow">Account access</p><h2>Google accounts</h2></div><span>Assign staff or owner access to signed-in accounts.</span></div>
        <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Change access</th></tr></thead><tbody>
          <?php foreach ($accounts as $account): if (!is_array($account)) continue; ?>
            <tr><td><?= h($account['display_name'] ?? '') ?></td><td><?= h($account['email'] ?? '') ?></td><td><?= h($account['role'] ?? '') ?></td><td><?= h($account['account_status'] ?? '') ?></td><td><?= h(staff_date_time($account['last_login_at'] ?? null)) ?></td><td>
              <?php if ((int) ($account['id'] ?? 0) === (int) $user['id']): ?><small>Your account</small><?php else: ?>
                <form class="staff-access-form" method="post" action="<?= h(base_url('staff.php')) ?>"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="change_account"><input type="hidden" name="account_id" value="<?= h($account['id']) ?>"><select name="role" aria-label="Role for <?= h($account['email'] ?? '') ?>"><option value="customer"<?= ($account['role'] ?? '') === 'customer' ? ' selected' : '' ?>>Customer</option><option value="staff"<?= ($account['role'] ?? '') === 'staff' ? ' selected' : '' ?>>Staff</option><option value="owner"<?= ($account['role'] ?? '') === 'owner' ? ' selected' : '' ?>>Owner</option></select><button class="secondary" type="submit">Save role</button></form>
                <form class="staff-access-form" method="post" action="<?= h(base_url('staff.php')) ?>"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="change_account"><input type="hidden" name="account_id" value="<?= h($account['id']) ?>"><input type="hidden" name="account_status" value="<?= ($account['account_status'] ?? '') === 'active' ? 'disabled' : 'active' ?>"><button class="text-link" type="submit"><?= ($account['account_status'] ?? '') === 'active' ? 'Disable' : 'Restore' ?></button></form>
              <?php endif; ?>
            </td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>

      <?php require __DIR__ . '/includes/owner-schedule-view.php'; ?>

      <section class="panel staff-panel">
        <div class="staff-section-heading"><div><p class="eyebrow">Programme</p><h2>Movie status</h2></div><span>Finished films archive automatically. To show one again, extend its dates if needed, add a future screening, then publish it.</span></div>
        <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Movie</th><th>Run</th><th>Status</th><th>Change</th></tr></thead><tbody>
          <?php foreach ($movies as $movie): if (!is_array($movie)) continue; ?>
            <tr>
              <td><?= h($movie['title'] ?? '') ?></td>
              <td><?= h($movie['run_start_date'] ?? '—') ?> – <?= h($movie['run_end_date'] ?? '—') ?></td>
              <td><?= ($movie['status'] ?? '') === 'archived' ? 'Archived · owner only' : h($movie['status'] ?? '') . ' · ' . h($movie['lifecycle_status'] ?? '') ?></td>
              <td><details><summary>Edit movie</summary>
                <form class="staff-form" method="post" action="<?= h(base_url('staff.php')) ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update_movie">
                  <input type="hidden" name="movie_id" value="<?= h($movie['id']) ?>">
                  <label>Status<select name="movie_status" aria-label="Status for <?= h($movie['title'] ?? '') ?>"><option value="draft"<?= ($movie['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Draft</option><option value="published"<?= ($movie['status'] ?? '') === 'published' ? ' selected' : '' ?>>Published</option><option value="archived"<?= ($movie['status'] ?? '') === 'archived' ? ' selected' : '' ?>>Archived</option></select></label>
                  <label>Run starts<input name="run_start_date" type="date" value="<?= h($movie['run_start_date'] ?? '') ?>" required></label>
                  <label>Run ends<input name="run_end_date" type="date" value="<?= h($movie['run_end_date'] ?? '') ?>" required></label>
                  <label>Sales open<input name="sales_open_date" type="date" value="<?= h($movie['sales_open_date'] ?? '') ?>"></label>
                  <button class="secondary" type="submit">Save movie</button>
                </form>
              </details></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>

      <section class="panel staff-panel">
        <div class="staff-section-heading"><div><p class="eyebrow">Recent activity</p><h2>Recent bookings</h2></div><span>Open a booking in the entry desk to inspect its seats and check-in status.</span></div>
        <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Reference</th><th>Customer</th><th>Email</th><th>Seats</th><th>Total</th></tr></thead><tbody>
          <?php foreach ($recentBookings as $booking): if (!is_array($booking)) continue; ?>
            <tr><td><a href="<?= h(base_url('staff.php?' . http_build_query(['q' => $booking['public_reference'] ?? '']) . '#entry-desk')) ?>"><?= h($booking['public_reference'] ?? '') ?></a></td><td><?= h($booking['customer_name'] ?? '') ?></td><td><?= h($booking['customer_email'] ?? '') ?></td><td><?= h($booking['seats'] ?? '—') ?></td><td><?= h(booking_money($booking['total_amount'] ?? 0)) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php if ($isOwner): ?><script defer src="<?= h(base_url('assets/schedule.js?v=' . (int) filemtime(dirname(__DIR__) . '/base/assets/schedule.js'))) ?>"></script><?php endif; ?>
<script defer src="<?= h(base_url('qr-assets/staff-scanner.js?v=' . (int) @filemtime(__DIR__ . '/qr-assets/staff-scanner.js'))) ?>"></script>
<?php page_footer(); ?>
