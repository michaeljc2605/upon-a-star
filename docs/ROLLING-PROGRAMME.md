# Rolling cinema programme

The native PHP site can keep the current 18 published films available through 31 December 2099. It creates a moving 45-day timetable when someone visits, rather than storing decades of screenings and seat rows. No Node service or scheduled job is needed.

## Enable it on the existing site

1. Upload the updated PHP runtime files, including `php/includes/rolling-programme.php`, `php/_start.php`, `php/native/customer.php` and `php/native/operations.php`. Keep the host's existing `php/config.local.php` intact.
2. In phpMyAdmin, select the **existing cinema database**. Import `deployment/infinityfree/rolling-programme.sql` (also included as `database/rolling-programme.sql` in a new deployment package).
3. Check the final result: `enabled = 1` and `template_count = 18`. The migration adds three tables and extends only the enrolled, currently published film runs. It does not select/drop a database, replace a booking, move a screening, or import the original snapshot again.
4. Visit the homepage or catalogue. Each visit processes at most two new dates. About 23 visits fill the initial 45-day horizon; normal visitors then keep it topped up. Screenings already on a processed date remain unchanged.

If fewer than 18 launch films or their assigned rooms exist, the migration leaves scheduling disabled. Review the template assignments instead of resetting the live database. Re-importing a completed migration does not reset later owner pauses, film dates or the cursor.

The first enrolment uses the actual November 2026 launch import's film/room assignments: ten normal rooms, two premier rooms and six small rooms. It preserves the assigned room's next existing screening format, language and price, falling back to its latest screening when necessary. New rolling screenings start at **18:00 Singapore time** each day. This new timetable does not move any existing reservation.

## Behaviour and owner control

- The next 45 calendar days are maintained, capped at 31 December 2099. A visit after a long absence resumes with the current date; it does not create missed historical screenings.
- A new screening needs at least 90 minutes of lead time. Existing screenings in any status suppress generation for that film/date. An owner cancellation is never silently replaced. A generated screening retains its original date marker if the owner moves it.
- Movie and room locks protect creation, and every room allocation includes the film runtime plus 60 minutes of turnover. Room conflicts are skipped; existing room allocations win.
- Archived/draft films, disabled templates, inactive rooms and owner-edited run windows are honoured. The generator never republishes a film or rewrites its dates. Managed published films are exempt from automatic archival caused only by a quiet gap; an expired owner-set run end still archives normally.
- The cursor records processed dates, including skipped dates. Re-enabling a film resumes new dates at the end of the current horizon. Owners can add an earlier screening through the existing dashboard.

The migration enables cleanup of **scheduler-created, unbooked screenings** that ended more than seven days ago. Cleanup is limited to 36 screenings per maintenance pass and requires `seats_booked = 0`, no booking item, no ticket and no live seat hold. Imported/manual screenings and all booking history stay intact. Their seat rows are never repurposed. Booked history can grow with actual usage; unbooked generated inventory stays bounded by the horizon and retention window.

## Verify or pause using SQL

These statements run only in the already selected cinema database:

```sql
SELECT enabled, horizon_days, batch_days, lead_minutes, prune_enabled,
       retention_days, generated_through, last_maintained_at
FROM rolling_programme_settings WHERE id = 1;

SELECT s.title, r.name AS room, t.start_time, t.format, t.language,
       t.unit_price, t.enabled, s.status, s.run_end_date
FROM rolling_programme_templates t
JOIN shows s ON s.id = t.show_id
JOIN rooms r ON r.id = t.room_id ORDER BY s.id;

SELECT COUNT(*) AS generated_screenings, MIN(p.starts_at) AS first_screening,
       MAX(p.starts_at) AS last_screening
FROM rolling_programme_slots marker
JOIN performances p ON p.id = marker.performance_id;

-- Pause all automatic generation/cleanup; existing screenings remain intact.
UPDATE rolling_programme_settings SET enabled = 0 WHERE id = 1;

-- Resume without importing or resetting data.
UPDATE rolling_programme_settings SET enabled = 1 WHERE id = 1;

-- Retain all generated history as well as all booked/imported history.
UPDATE rolling_programme_settings SET prune_enabled = 0 WHERE id = 1;
```

Use the owner dashboard to archive a film or cancel a screening. Do not delete the generated-slot markers or reset the cursor as a routine maintenance step. A full database backup remains appropriate before a migration, even though this migration is additive.

The 2099 limit describes the movie programme and screening dates. It does not certify unrelated hosting services or every legacy account/audit timestamp for operation in that year.

## Validation

`node scripts/test-rolling-programme.mjs --run` uses an explicitly local database service and creates/drops only its uniquely named `uas_rolling_test_*` fixture. It exercises bounded generation, repeats, long absences, the 2099 boundary, cancellation/archival, room conflicts, lock contention and protected booking history. It never connects to the hosted database.
