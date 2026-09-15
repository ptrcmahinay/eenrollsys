<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
$user = require_role(['chair', 'admin', 'registrar']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('index.php');
}

$scheduleId = (int) ($_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0);
if ($scheduleId <= 0) {
    flash('error', 'Missing schedule ID.');
    redirect('chair/class_schedules.php');
}

$schedule = get_schedule_with_details($scheduleId);
if ($schedule === null) {
    flash('error', 'Schedule not found.');
    redirect('chair/class_schedules.php');
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'submit_change_request') {
        $field     = trim($_POST['field_changed'] ?? '');
        $newValue  = trim($_POST['new_value'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');

        if ($field === '' || $newValue === '' || $reason === '') {
            flash('error', 'All fields are required.');
            redirect('chair/schedule_change_request.php?schedule_id=' . $scheduleId);
        }

        $oldValue = (string) ($schedule[$field] ?? '');
        if ($oldValue === $newValue) {
            flash('error', 'New value is the same as the current value.');
            redirect('chair/schedule_change_request.php?schedule_id=' . $scheduleId);
        }

        schedule_change_request($scheduleId, $field, $oldValue, $newValue, $reason, (int) $staff['staff_id']);
        flash('success', 'Change request submitted for registrar review.');
        redirect('chair/class_schedules.php');
    }
}

$changeableFields = [
    'room'          => 'Room',
    'instructor_id' => 'Instructor',
    'day'           => 'Day',
    'start_time'    => 'Time Start',
    'end_time'      => 'Time End',
];

$instructors = fetch_all(
    'SELECT staff_id, full_name FROM staff WHERE status = "active" ORDER BY full_name'
);

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$timeOptions = '';
for ($h = 7; $h <= 20; $h++) {
    foreach (['00', '30'] as $m) {
        $t = sprintf('%02d:%02d', $h, $m);
        $timeOptions .= '<option value="' . $t . '">' . format_time_12h($t) . '</option>';
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Request Schedule Change</h1>
        <p>Request a modification to an approved class schedule. The registrar will review your request.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('chair/class_schedules.php')) ?>">&larr; Back</a>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>Current Schedule</h3>
        <div class="kv-list">
            <div class="item"><div class="k">Schedule Code</div><div class="v"><?= h($schedule['schedule_code'] ?? '—') ?></div></div>
            <div class="item"><div class="k">Course</div><div class="v"><?= h($schedule['subject_code'] . ' - ' . $schedule['subject_description']) ?></div></div>
            <div class="item"><div class="k">Units</div><div class="v"><?= h((string) $schedule['units']) ?></div></div>
            <div class="item"><div class="k">Section</div><div class="v"><?= h($schedule['program_code'] . ' ' . $schedule['year_level'] . $schedule['section_name']) ?></div></div>
            <div class="item"><div class="k">Instructor</div><div class="v"><?= h($schedule['instructor_name'] ?? 'TBA') ?></div></div>
            <div class="item"><div class="k">Day</div><div class="v"><?= h($schedule['day'] ?? '—') ?></div></div>
            <div class="item"><div class="k">Time</div><div class="v"><?= h(($schedule['start_time'] ? format_time_12h($schedule['start_time']) : '—') . ' – ' . ($schedule['end_time'] ? format_time_12h($schedule['end_time']) : '')) ?></div></div>
            <div class="item"><div class="k">Room</div><div class="v"><?= h($schedule['room'] ?? '—') ?></div></div>
            <div class="item"><div class="k">Status</div><div class="v"><span class="badge <?= schedule_status_badge_class($schedule['status']) ?>"><?= h(ucfirst($schedule['status'])) ?></span></div></div>
        </div>
    </div>

    <div class="card">
        <h3>Request Change</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="schedule_id" value="<?= h((string) $scheduleId) ?>">
            <input type="hidden" name="action" value="submit_change_request">

            <div class="form-grid">
                <div>
                    <label>Field to Change *</label>
                    <select name="field_changed" id="fieldSelect" required onchange="updateNewValueField(this)">
                        <option value="">-- Select Field --</option>
                        <?php foreach ($changeableFields as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="newValueContainer">
                    <label>New Value *</label>
                    <input type="text" name="new_value" id="newValueInput" required>
                </div>
            </div>

            <div style="margin-top:12px;">
                <label>Reason for Change *</label>
                <textarea name="reason" rows="4" required placeholder="Explain why this change is needed..." style="width:100%;"></textarea>
            </div>

            <div class="form-actions" style="margin-top:16px;">
                <button class="btn" type="submit" onclick="return confirm('Submit this change request?')">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateNewValueField(sel) {
    var container = document.getElementById('newValueContainer');
    var field = sel.value;

    var instructors = <?= json_encode(array_map(fn($i) => ['id' => (string) $i['staff_id'], 'name' => $i['full_name']], $instructors)) ?>;
    var days = <?= json_encode($days) ?>;
    var times = <?= json_encode($timeOptions) ?>;

    var html = '<label>New Value *</label>';

    if (field === 'instructor_id') {
        html += '<select name="new_value" required><option value="">-- Select Instructor --</option>';
        instructors.forEach(function(i) {
            html += '<option value="' + i.id + '">' + i.name + '</option>';
        });
        html += '</select>';
    } else if (field === 'day') {
        html += '<select name="new_value" required><option value="">-- Select Day --</option>';
        days.forEach(function(d) {
            html += '<option value="' + d + '">' + d + '</option>';
        });
        html += '</select>';
    } else if (field === 'start_time' || field === 'end_time') {
        html += '<select name="new_value" required><option value="">-- Select Time --</option>';
        var tmp = document.createElement('div');
        tmp.innerHTML = times;
        var opts = tmp.querySelectorAll('option');
        opts.forEach(function(o) { html += '<option value="' + o.value + '">' + o.text + '</option>'; });
        html += '</select>';
    } else if (field === 'room') {
        html += '<input type="text" name="new_value" required list="roomList2" placeholder="e.g. Room 201">';
        html += '<datalist id="roomList2">';
        <?php
        $rooms = fetch_all('SELECT DISTINCT room FROM class_schedules WHERE room IS NOT NULL AND room <> "" ORDER BY room');
        foreach ($rooms as $rm): ?>
            html += '<option value="<?= h($rm['room']) ?>"><?= h($rm['room']) ?></option>';
        <?php endforeach; ?>
        html += '</datalist>';
    }

    container.innerHTML = html;
}
</script>

<?php
render_page('Schedule Change Request', 'Class Schedules', (string) ob_get_clean());
