<?php
declare(strict_types=1);
// Included by the authenticated owner dashboard after it prepares the filters.
?>
<section class="panel staff-panel staff-schedule-panel" id="screenings" aria-labelledby="owner-schedule-heading">
  <div class="staff-section-heading"><div><p class="eyebrow">Screenings</p><h2 id="owner-schedule-heading">Manage the schedule</h2></div><span>Choose a day to see every room allocation. Edit a future screening or remove it from the public schedule when plans change.</span></div>
  <nav class="staff-schedule-views" aria-label="Screening status">
    <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past history', 'cancelled' => 'Cancelled'] as $view => $label): ?>
      <a href="<?= h($scheduleUrl($view, null, $scheduleMovie)) ?>"<?= $scheduleView === $view ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span><?= (int) $scheduleViewCounts[$view] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <div class="schedule-calendar staff-schedule-calendar">
    <div class="schedule-calendar-heading">
      <div><p class="eyebrow">Pick a date</p><h3><?= $selectedScheduleDate === null ? 'No screenings here yet.' : h(staff_date_time($selectedScheduleDate . ' 12:00:00', 'l, d F Y')) ?></h3><span><?= count($dayScreenings) ?> <?= count($dayScreenings) === 1 ? 'screening' : 'screenings' ?> on this day · <?= array_sum($scheduleDayCounts) ?> in <?= h($scheduleView) ?><?= $scheduleMovie !== '' ? ' for this film' : '' ?></span></div>
      <form class="schedule-film-filter staff-schedule-filter" method="get" action="<?= h(base_url('staff.php')) ?>#screenings">
        <input type="hidden" name="schedule_view" value="<?= h($scheduleView) ?>">
        <label for="owner-schedule-film">Film</label>
        <select id="owner-schedule-film" name="schedule_movie"><option value="">All films</option><?php foreach ($movies as $movie): if (!is_array($movie)) continue; ?><option value="<?= h($movie['slug'] ?? '') ?>"<?= $scheduleMovie === ($movie['slug'] ?? '') ? ' selected' : '' ?>><?= h($movie['title'] ?? '') ?></option><?php endforeach; ?></select>
        <button type="submit">Show dates</button>
      </form>
    </div>
    <?php if ($scheduleDates): ?>
      <?php if (count($monthFirstDates) > 1): ?><nav class="schedule-month-jumps" aria-label="Jump to a month"><span>Jump to</span><?php foreach ($monthFirstDates as $month => $firstDate): ?><a class="<?= $selectedScheduleDate !== null && substr($selectedScheduleDate, 0, 7) === $month ? 'is-current' : '' ?>" href="<?= h($scheduleUrl($scheduleView, $firstDate, $scheduleMovie)) ?>"><?= h(staff_date_time($firstDate . ' 12:00:00', 'F Y')) ?></a><?php endforeach; ?></nav><?php endif; ?>
      <div class="schedule-date-controls"><nav class="schedule-date-rail" aria-label="Screening dates" data-schedule-dates>
        <?php $lastMonth = ''; foreach ($scheduleDayCounts as $date => $count): $month = substr($date, 0, 7); ?>
          <?php if ($month !== $lastMonth): ?><span class="schedule-month-break"><?= h(staff_date_time($date . ' 12:00:00', 'F Y')) ?></span><?php $lastMonth = $month; endif; ?>
          <a class="schedule-date-choice<?= $date === $selectedScheduleDate ? ' is-current' : '' ?>" href="<?= h($scheduleUrl($scheduleView, $date, $scheduleMovie)) ?>"<?= $date === $selectedScheduleDate ? ' aria-current="date"' : '' ?>><small><?= h(staff_date_time($date . ' 12:00:00', 'D')) ?></small><strong><?= h(staff_date_time($date . ' 12:00:00', 'd')) ?></strong><span><?= h(staff_date_time($date . ' 12:00:00', 'M')) ?></span><em><?= (int) $count ?> <?= (int) $count === 1 ? 'show' : 'shows' ?></em></a>
        <?php endforeach; ?>
      </nav></div>
      <div class="schedule-date-steps">
        <?php if ($previousScheduleDate !== null): ?><a href="<?= h($scheduleUrl($scheduleView, $previousScheduleDate, $scheduleMovie)) ?>">← Previous screening day</a><?php endif; ?>
        <?php if ($nextScheduleDate !== null): ?><a href="<?= h($scheduleUrl($scheduleView, $nextScheduleDate, $scheduleMovie)) ?>">Next screening day →</a><?php endif; ?>
      </div>
      <?php if ($scheduleDateNotice !== ''): ?><p class="schedule-date-notice" role="status"><?= h($scheduleDateNotice) ?></p><?php endif; ?>
    <?php else: ?>
      <p class="staff-empty">No <?= h($scheduleView) ?> screenings<?= $scheduleMovie !== '' ? ' for this film' : '' ?>. Choose another status or film.</p>
    <?php endif; ?>
  </div>

  <?php if ($visibleScreenings): ?>
    <p class="staff-help staff-schedule-count">Showing <?= count($visibleScreenings) ?> of <?= count($dayScreenings) ?> screenings on this date<?= $screeningPageCount > 1 ? ' · Page ' . $screeningPage . ' of ' . $screeningPageCount : '' ?>. Times use Singapore time.</p>
    <div class="schedule-timeline-rows staff-schedule-rows">
      <?php foreach ($screeningTimeSlots as $time => $slot): ?>
        <div class="schedule-timeline-row"><div class="schedule-timeline-time"><time datetime="<?= h($selectedScheduleDate . 'T' . $time) ?>"><?= h($time) ?></time></div><div class="schedule-timeline-events">
          <?php foreach ($slot as $screening): $booked = (int) ($screening['seats_booked'] ?? 0); $capacity = (int) ($screening['capacity'] ?? 0); $future = strtotime((string) ($screening['starts_at'] ?? '')) > time(); ?>
            <article class="schedule-timeline-card staff-schedule-card" id="screening-<?= (int) ($screening['id'] ?? 0) ?>">
              <div class="staff-schedule-card-top"><div class="schedule-timeline-film"><img src="<?= h(movie_asset(is_string($screening['poster_url'] ?? null) ? $screening['poster_url'] : null, 'poster')) ?>" alt="" loading="lazy"><span><?= h($screening['title'] ?? '') ?></span></div><span class="staff-schedule-state <?= ($screening['status'] ?? '') === 'cancelled' ? 'is-cancelled' : ($future ? 'is-upcoming' : 'is-past') ?>"><?= ($screening['status'] ?? '') === 'cancelled' ? 'Cancelled' : ($future ? 'Scheduled' : 'Past') ?></span></div>
              <p>Until <?= h(staff_date_time($screening['ends_at'] ?? null, 'H:i')) ?> · <?= h($screening['room_name'] ?? '') ?> · <?= h($screening['format'] ?? '') ?> · <?= h(booking_money($screening['unit_price'] ?? 0)) ?></p>
              <small><?= $booked ?> of <?= $capacity ?> seats booked · Room ready at <?= h(staff_date_time($screening['room_available_at'] ?? null, 'H:i')) ?></small>
              <?php if (($screening['status'] ?? '') === 'cancelled' && is_string($screening['cancellation_reason'] ?? null) && $screening['cancellation_reason'] !== ''): ?><p class="staff-schedule-reason">Reason: <?= h($screening['cancellation_reason']) ?></p><?php endif; ?>

              <?php if ($scheduleView === 'upcoming' && $future): ?>
                <div class="staff-schedule-actions">
                  <details class="staff-screening-editor"><summary>Edit screening</summary>
                    <form class="staff-screening-form" method="post" action="<?= h(base_url('staff.php')) ?>">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_screening"><input type="hidden" name="screening_id" value="<?= (int) $screening['id'] ?>">
                      <input type="hidden" name="return_schedule_view" value="<?= h($scheduleView) ?>"><input type="hidden" name="return_schedule_date" value="<?= h($selectedScheduleDate) ?>"><input type="hidden" name="return_schedule_movie" value="<?= h($scheduleMovie) ?>"><input type="hidden" name="return_screening_page" value="<?= $screeningPage ?>">
                      <label>Start time<input name="starts_at" type="datetime-local" value="<?= h(staff_datetime_local($screening['starts_at'] ?? null)) ?>" required></label>
                      <label>Room<select name="room_id" required><?php foreach ($rooms as $room): if (!is_array($room) || empty($room['active'])) continue; ?><option value="<?= (int) $room['id'] ?>"<?= (int) ($screening['room_id'] ?? 0) === (int) ($room['id'] ?? 0) ? ' selected' : '' ?>><?= h($room['name']) ?></option><?php endforeach; ?></select></label>
                      <div class="staff-form-row"><label>Format<input name="format" value="<?= h($screening['format'] ?? '') ?>" required></label><label>Language<input name="language" value="<?= h($screening['language'] ?? '') ?>" required></label><label>Price (Rp)<input name="unit_price" type="number" min="1" step="1" value="<?= h($screening['unit_price'] ?? '') ?>" required></label></div>
                      <button class="secondary" type="submit">Save changes</button>
                    </form>
                  </details>
                  <details class="staff-screening-remove"><summary>Remove from public schedule</summary>
                    <p>Cancellation hides this time from customers and keeps its history.<?= $booked > 0 ? ' It has booked seats; those tickets will no longer be usable and customers are not emailed automatically.' : '' ?></p>
                    <form class="staff-screening-form" method="post" action="<?= h(base_url('staff.php')) ?>">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_screening"><input type="hidden" name="screening_id" value="<?= (int) $screening['id'] ?>">
                      <input type="hidden" name="return_schedule_view" value="<?= h($scheduleView) ?>"><input type="hidden" name="return_schedule_date" value="<?= h($selectedScheduleDate) ?>"><input type="hidden" name="return_schedule_movie" value="<?= h($scheduleMovie) ?>"><input type="hidden" name="return_screening_page" value="<?= $screeningPage ?>">
                      <label>Cancellation reason<?= $booked > 0 ? ' (required)' : ' (optional)' ?><input name="cancellation_reason" maxlength="255"<?= $booked > 0 ? ' required' : '' ?> placeholder="Why is this screening being cancelled?"></label>
                      <?php if ($booked > 0): ?><label class="staff-check"><input type="checkbox" name="acknowledge_bookings" value="1" required> I understand the <?= $booked ?> booked <?= $booked === 1 ? 'seat' : 'seats' ?> will have unusable tickets.</label><?php endif; ?>
                      <button class="staff-danger-button" type="submit">Confirm cancellation</button>
                    </form>
                  </details>
                </div>
              <?php elseif ($scheduleView === 'cancelled' && $future): ?>
                <form class="staff-schedule-restore" method="post" action="<?= h(base_url('staff.php')) ?>"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_screening"><input type="hidden" name="screening_id" value="<?= (int) $screening['id'] ?>"><input type="hidden" name="screening_status" value="scheduled"><input type="hidden" name="return_schedule_view" value="cancelled"><input type="hidden" name="return_schedule_date" value="<?= h($selectedScheduleDate) ?>"><input type="hidden" name="return_schedule_movie" value="<?= h($scheduleMovie) ?>"><button class="secondary" type="submit">Restore screening</button></form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div></div>
      <?php endforeach; ?>
    </div>
    <?php if ($screeningPageCount > 1): ?><nav class="staff-pagination" aria-label="More screenings on this date"><?php if ($screeningPage > 1): ?><a class="secondary" href="<?= h($scheduleUrl($scheduleView, $selectedScheduleDate, $scheduleMovie, $screeningPage - 1)) ?>">← Previous</a><?php endif; ?><?php if ($screeningPage < $screeningPageCount): ?><a class="secondary" href="<?= h($scheduleUrl($scheduleView, $selectedScheduleDate, $scheduleMovie, $screeningPage + 1)) ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
  <?php endif; ?>
</section>
