<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

/* -----------------------------------------------------------------------
 * POST handlers
 * --------------------------------------------------------------------- */
if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_schedule') {
        $termId    = (int) ($_POST['term_id']    ?? 0);
        $yearLevel = (int) ($_POST['year_level'] ?? 0);
        $openDate  = trim($_POST['open_date']  ?? '');
        $closeDate = trim($_POST['close_date'] ?? '');
        $openTime  = trim($_POST['open_time']  ?? '07:00');
        $closeTime = trim($_POST['close_time'] ?? '19:00');

        if ($termId > 0 && $yearLevel > 0 && $openDate !== '' && $closeDate !== '') {
            execute_sql(
                'INSERT INTO enrollment_schedules
                    (term_id, year_level, open_date, close_date, open_time, close_time)
                 VALUES (:tid, :yl, :od, :cd, :ot, :ct)
                 ON DUPLICATE KEY UPDATE
                    open_date  = VALUES(open_date),
                    close_date = VALUES(close_date),
                    open_time  = VALUES(open_time),
                    close_time = VALUES(close_time),
                    updated_at = NOW()',
                [
                    'tid' => $termId,
                    'yl'  => $yearLevel,
                    'od'  => $openDate,
                    'cd'  => $closeDate,
                    'ot'  => $openTime . ':00',
                    'ct'  => $closeTime . ':00',
                ]
            );
            flash('success', 'Enrollment schedule saved.');
        } else {
            flash('error', 'Please fill in all required fields.');
        }
    }

    if ($action === 'delete_schedule') {
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            execute_sql('DELETE FROM enrollment_schedules WHERE id = :id', ['id' => $scheduleId]);
            flash('success', 'Schedule entry removed.');
        }
    }

    redirect('registrar/enrollment_schedule.php');
}

/* -----------------------------------------------------------------------
 * Fetch data
 * --------------------------------------------------------------------- */
$terms = fetch_all(
    'SELECT t.*, ay.year_label
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$activeTerm = current_term();
$selectedTermId = (int) ($_GET['term_id'] ?? ($activeTerm['id'] ?? 0));

$schedules = [];
if ($selectedTermId > 0) {
    $schedules = fetch_all(
        'SELECT * FROM enrollment_schedules WHERE term_id = :tid ORDER BY year_level',
        ['tid' => $selectedTermId]
    );
}

// Dynamically build year levels from the curriculum (supports 5th-year programs, etc.)
$maxYearRow = fetch_one('SELECT MAX(CAST(year_level AS UNSIGNED)) AS max_yr FROM program_curriculum');
$maxYear = max(4, (int) ($maxYearRow['max_yr'] ?? 4));
$suffixes = ['st', 'nd', 'rd'];
$yearLevels = [];
for ($i = 1; $i <= $maxYear; $i++) {
    $suffix = $suffixes[$i - 1] ?? 'th';
    $yearLevels[$i] = $i . $suffix . ' Year';
}

// Build a map: year_level => schedule row
$scheduleMap = [];
foreach ($schedules as $s) {
    $scheduleMap[(int) $s['year_level']] = $s;
}

/* -----------------------------------------------------------------------
 * View
 * --------------------------------------------------------------------- */
ob_start();
?>
<div class="page-header">
    <div>
        <h1>Enrollment Schedule</h1>
        <p>Set per-year-level enrollment windows (date &amp; time) within an academic term. Admin and Registrar only.</p>
    </div>
</div>

<!-- Term selector -->
<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <label style="font-weight:600;">Viewing term:</label>
        <select name="term_id" onchange="this.form.submit()" style="min-width:220px;">
            <?php foreach ($terms as $t): ?>
                <option value="<?= h($t['id']) ?>"
                    <?= (int) $t['id'] === $selectedTermId ? 'selected' : '' ?>>
                    <?= h($t['year_label'] . ' — ' . semester_label((string) $t['semester'])) ?>
                    <?= (int) $t['is_active'] ? ' (Active)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn secondary small" type="submit">Go</button></noscript>
    </form>
</div>

<?php if ($selectedTermId > 0): ?>

<!-- Add / edit schedule row -->
<div class="card" style="margin-bottom:16px;">
    <h3>Add / Update a Year-Level Window</h3>
    <p style="color:var(--text-secondary); margin-top:-8px; margin-bottom:16px;">
        If <strong>no schedules</strong> are set for a term, enrollment obeys only the term's Open/Closed flag.
        Once you add at least one schedule row, each year level is gated by its own window.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="term_id" value="<?= h((string) $selectedTermId) ?>">
        <div class="form-grid" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:12px;">
            <div>
                <label>Year Level</label>
                <select name="year_level" required>
                    <?php foreach ($yearLevels as $lvl => $label): ?>
                        <option value="<?= $lvl ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Open Date</label>
                <input type="date" name="open_date" required>
            </div>
            <div>
                <label>Close Date</label>
                <input type="date" name="close_date" required>
            </div>
            <div>
                <label>Daily Open Time</label>
                <input type="time" name="open_time" value="07:00" required>
            </div>
            <div>
                <label>Daily Close Time</label>
                <input type="time" name="close_time" value="19:00" required>
            </div>
        </div>
        <div class="form-actions" style="margin-top:12px;">
            <button class="btn" type="submit">Save Schedule</button>
        </div>
    </form>
</div>

<!-- Current schedules table -->
<div class="card">
    <h3>Current Schedules for Selected Term</h3>
    <?php if (count($schedules) === 0): ?>
        <p style="color:var(--text-secondary);">No year-level schedules configured yet. Enrollment is controlled by the term's Open/Closed flag only.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Year Level</th>
                    <th>Open Date</th>
                    <th>Close Date</th>
                    <th>Daily Window</th>
                    <th>Status Now</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $s):
                $now     = new DateTimeImmutable('now');
                $openDT  = new DateTimeImmutable($s['open_date']  . ' ' . $s['open_time']);
                $closeDT = new DateTimeImmutable($s['close_date'] . ' ' . $s['close_time']);
                if ($now < $openDT) {
                    $statusLabel = 'Upcoming';
                    $statusClass = '';
                } elseif ($now > $closeDT) {
                    $statusLabel = 'Closed';
                    $statusClass = 'danger';
                } else {
                    $statusLabel = 'Open';
                    $statusClass = 'success';
                }
            ?>
                <tr>
                    <td><?= h($yearLevels[(int) $s['year_level']] ?? 'Year ' . $s['year_level']) ?></td>
                    <td><?= h(date('M j, Y', strtotime($s['open_date']))) ?></td>
                    <td><?= h(date('M j, Y', strtotime($s['close_date']))) ?></td>
                    <td>
                        <?= h(date('g:i A', strtotime($s['open_time']))) ?>
                        &ndash;
                        <?= h(date('g:i A', strtotime($s['close_time']))) ?>
                    </td>
                    <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                    <td>
                        <form class="inline-form" method="post"
                              onsubmit="return confirm('Remove this schedule?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_schedule">
                            <input type="hidden" name="schedule_id" value="<?= h($s['id']) ?>">
                            <button class="btn small danger" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
    <div class="card"><p>No academic term selected or available.</p></div>
<?php endif; ?>

<?php
render_page('Enrollment Schedule', 'Enroll Schedule', (string) ob_get_clean());
