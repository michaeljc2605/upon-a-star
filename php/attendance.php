<?php
declare(strict_types=1);

require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/../base/includes/layout.php';

header('Cache-Control: private, no-store');
$user = php_current_user();
if ($user === null) {
    header('Location: ' . php_signin_url(), true, 302);
    exit;
}
if (($user['role'] ?? '') !== 'owner') {
    http_response_code(403);
    page_header('Guest attendance', 'attendance');
    echo '<main class="section staff-page"><div class="heading"><div><p class="eyebrow">Owner tools</p><h1>Owner access <em>required.</em></h1></div></div></main>';
    page_footer();
    exit;
}

function attendance_date($value): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Singapore')))
            ->setTimezone(new DateTimeZone('Asia/Singapore'))
            ->format('D, j M Y · g:i A');
    } catch (Throwable $exception) {
        return '—';
    }
}

$statusLabels = [
    'pending' => 'Pending check-in',
    'not_checked_in' => 'Not checked in',
    'did_not_attend' => 'Did not attend',
    'checked_in' => 'Checked in',
    'cancelled' => 'Cancelled',
];
$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 120);
$movieId = filter_var($_GET['movie'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$movieId = is_int($movieId) ? $movieId : null;
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!array_key_exists($status, $statusLabels)) {
    $status = '';
}
$requestedPage = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = is_int($requestedPage) ? $requestedPage : 1;
$pageSize = 20;
$movies = [];
$rows = [];
$counts = array_fill_keys(array_keys($statusLabels), 0);
$total = 0;
$error = false;

try {
    $pdo = db();
    $movies = $pdo->query('SELECT DISTINCT s.id, s.title FROM shows s JOIN performances p ON p.show_id = s.id JOIN booking_items bi ON bi.performance_id = p.id ORDER BY s.title')->fetchAll();

    // A confirmed seat becomes a no-show automatically once its screening ends.
    // Keep this computed from the current time so a background job is unnecessary.
    $statusSql = "CASE
        WHEN bt.checked_in_at IS NOT NULL THEN 'checked_in'
        WHEN b.booking_status = 'cancelled' OR p.status = 'cancelled' THEN 'cancelled'
        WHEN CURRENT_TIMESTAMP < p.starts_at THEN 'pending'
        WHEN CURRENT_TIMESTAMP < TIMESTAMPADD(MINUTE, s.duration_minutes, p.starts_at) THEN 'not_checked_in'
        ELSE 'did_not_attend'
    END";
    $conditions = [];
    $params = [];
    if ($movieId !== null) {
        $conditions[] = 's.id = ?';
        $params[] = $movieId;
    }
    if ($search !== '') {
        $conditions[] = '(b.customer_name LIKE ? OR b.customer_email LIKE ? OR b.public_reference LIKE ? OR s.title LIKE ? OR bt.ticket_code LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $baseSql = "SELECT b.public_reference, b.customer_name, b.customer_email, b.customer_phone,
        b.created_at AS booked_at, s.title AS movie_title, p.starts_at, r.name AS room_name,
        rs.seat_code, bt.ticket_code, bt.checked_in_at, $statusSql AS attendance_status
        FROM booking_tickets bt
        JOIN bookings b ON b.id = bt.booking_id
        JOIN booking_items bi ON bi.id = bt.booking_item_id
        JOIN performances p ON p.id = bi.performance_id
        JOIN shows s ON s.id = p.show_id
        JOIN rooms r ON r.id = p.room_id
        JOIN performance_seats ps ON ps.id = bt.performance_seat_id
        JOIN room_seats rs ON rs.id = ps.room_seat_id
        $where";

    $countQuery = $pdo->prepare("SELECT attendance_status, COUNT(*) AS seat_count FROM ($baseSql) attendance GROUP BY attendance_status");
    $countQuery->execute($params);
    foreach ($countQuery->fetchAll() as $countRow) {
        $key = (string) $countRow['attendance_status'];
        if (array_key_exists($key, $counts)) {
            $counts[$key] = (int) $countRow['seat_count'];
        }
    }
    $total = $status === '' ? array_sum($counts) : $counts[$status];
    $pageCount = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pageCount);
    $statusWhere = $status === '' ? '' : 'WHERE attendance.attendance_status = ?';
    $rowQuery = $pdo->prepare("SELECT * FROM ($baseSql) attendance $statusWhere ORDER BY attendance.starts_at DESC, attendance.public_reference DESC, attendance.seat_code LIMIT ? OFFSET ?");
    $position = 1;
    foreach ($params as $param) {
        $rowQuery->bindValue($position++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    if ($status !== '') {
        $rowQuery->bindValue($position++, $status, PDO::PARAM_STR);
    }
    $rowQuery->bindValue($position++, $pageSize, PDO::PARAM_INT);
    $rowQuery->bindValue($position, ($page - 1) * $pageSize, PDO::PARAM_INT);
    $rowQuery->execute();
    $rows = $rowQuery->fetchAll();
} catch (Throwable $exception) {
    error_log('Owner attendance query failed: ' . get_class($exception));
    $error = true;
    $pageCount = 1;
}

function attendance_page_url(int $page, string $search, ?int $movieId, string $status): string
{
    $params = ['page' => $page];
    if ($search !== '') $params['q'] = $search;
    if ($movieId !== null) $params['movie'] = $movieId;
    if ($status !== '') $params['status'] = $status;
    return base_url('attendance.php?' . http_build_query($params));
}

page_header('Guest attendance', 'attendance');
?>
<main class="section staff-page attendance-page">
  <div class="heading"><div><p class="eyebrow">Owner tools</p><h1>Guest <em>attendance.</em></h1><span>Every booked seat across every movie, with its entry status. Unscanned seats become “Did not attend” after the screening ends.</span></div></div>
  <nav class="staff-area-tabs" aria-label="Owner tools">
    <a href="<?= h(base_url('staff.php')) ?>">Staff dashboard</a>
    <a href="<?= h(base_url('attendance.php')) ?>" aria-current="page">Guest attendance</a>
  </nav>
  <?php if ($error): ?>
    <p class="notice staff-notice error" role="alert">The attendance table could not load. Refresh this page or check the cinema database.</p>
  <?php else: ?>
    <section class="attendance-summary" aria-label="Attendance totals for selected movie and search">
      <?php foreach ($statusLabels as $key => $label): ?>
        <article><span><?= h($label) ?></span><strong><?= h($counts[$key]) ?></strong></article>
      <?php endforeach; ?>
    </section>
    <section class="panel staff-panel">
      <div class="staff-section-heading"><div><p class="eyebrow">All bookings</p><h2>Seat register</h2></div><span>One row per booked seat. <?= h($total) ?> <?= $total === 1 ? 'seat' : 'seats' ?> match the current filters.</span></div>
      <form class="attendance-filters" method="get" action="<?= h(base_url('attendance.php')) ?>">
        <label>Search customer, booking or ticket<input type="search" name="q" value="<?= h($search) ?>" maxlength="120" placeholder="Name, email, SV-… or SVT-…"></label>
        <label>Movie<select name="movie"><option value="">All movies</option><?php foreach ($movies as $movie): ?><option value="<?= h($movie['id']) ?>"<?= $movieId === (int) $movie['id'] ? ' selected' : '' ?>><?= h($movie['title']) ?></option><?php endforeach; ?></select></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= h($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        <button class="primary" type="submit">Apply filters</button>
      </form>
      <?php if (!$rows): ?><p class="staff-empty">No seats match these filters.</p><?php endif; ?>
      <?php if ($rows): ?>
        <div class="staff-table-wrap"><table class="staff-table attendance-table">
          <thead><tr><th>Customer</th><th>Movie &amp; showtime</th><th>Room &amp; seat</th><th>Booking</th><th>Status</th><th>Checked in</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $row): $rowStatus = (string) $row['attendance_status']; ?>
              <tr>
                <td><strong><?= h($row['customer_name']) ?></strong><small><?= h($row['customer_email']) ?></small><small><?= h($row['customer_phone']) ?></small></td>
                <td><strong><?= h($row['movie_title']) ?></strong><small><?= h(attendance_date($row['starts_at'])) ?></small></td>
                <td><?= h($row['room_name']) ?><small>Seat <?= h($row['seat_code']) ?></small></td>
                <td><a href="<?= h(base_url('staff.php?' . http_build_query(['q' => $row['public_reference']]) . '#entry-desk')) ?>"><?= h($row['public_reference']) ?></a><small><?= h($row['ticket_code']) ?></small><small>Booked <?= h(attendance_date($row['booked_at'])) ?></small></td>
                <td><span class="attendance-status status-<?= h($rowStatus) ?>"><?= h($statusLabels[$rowStatus] ?? $rowStatus) ?></span></td>
                <td><?= h(attendance_date($row['checked_in_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($pageCount > 1): ?>
          <nav class="attendance-pagination" aria-label="Attendance pages">
            <?php if ($page > 1): ?><a href="<?= h(attendance_page_url($page - 1, $search, $movieId, $status)) ?>">← Previous</a><?php endif; ?>
            <span>Page <?= h($page) ?> of <?= h($pageCount) ?></span>
            <?php if ($page < $pageCount): ?><a href="<?= h(attendance_page_url($page + 1, $search, $movieId, $status)) ?>">Next →</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php page_footer(); ?>
