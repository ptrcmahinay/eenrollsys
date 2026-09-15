<?php
$gradeDeadlineDays = (int) setting('grade_deadline_days', '30');
?>

<div class="settings-card">
    <h3>Grade Submission</h3>
    <p class="settings-card-desc">Configure grade submission deadlines and rules.</p>

    <form id="gradingForm" onsubmit="return submitSettingsForm('gradingForm', 'update_grading')">
        <div class="settings-form-grid cols-2">
            <div class="settings-field">
                <label for="grading_grade_days">Grade Encoding Deadline (days)</label>
                <input type="number" id="grading_grade_days" name="grade_deadline_days" value="<?= h((string) $gradeDeadlineDays) ?>" min="1" max="365">
                <div class="settings-field-hint">Days after term end date for instructors to submit grades.</div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Grading Settings</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <h3>Grade Scale & Academic Honors</h3>
    <p class="settings-card-desc">Configure the institutional grade scale and Latin Honors evaluation rules.</p>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/grade_scale.php')) ?>">Configure Grade Scale &rarr;</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/academic_honors.php')) ?>">Configure Academic Honors &rarr;</a>
    </div>
</div>

<div class="settings-card">
    <h3>Grade Management</h3>
    <p class="settings-card-desc">View and manage grades by Sched Code, approve corrections, and lock grades.</p>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/grade_management.php')) ?>">Grade Management &rarr;</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/grade_corrections.php')) ?>">Grade Corrections &rarr;</a>
    </div>
</div>
