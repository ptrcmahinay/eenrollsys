<?php
$tuition = (float) setting('tuition_per_unit', '550');
$otherFees = (float) setting('other_school_fees', '2500');
$onlineEnrollment = setting('allow_online_enrollment', '1') === '1';
$irregularUnitCap = (int) setting('irregular_unit_cap', '28');
?>

<div class="settings-card">
    <h3>Online Enrollment</h3>
    <p class="settings-card-desc">Control enrollment availability and financial settings.</p>

    <form id="enrollmentForm" onsubmit="return submitSettingsForm('enrollmentForm', 'update_enrollment')">
        <div class="settings-form-grid cols-3">
            <div class="settings-field">
                <label for="enr_online_enrollment">Online Enrollment</label>
                <select id="enr_online_enrollment" name="allow_online_enrollment">
                    <option value="1" <?= $onlineEnrollment ? 'selected' : '' ?>>Open</option>
                    <option value="0" <?= !$onlineEnrollment ? 'selected' : '' ?>>Closed</option>
                </select>
                <div class="settings-field-hint">When closed, students cannot submit enrollment requests.</div>
            </div>
            <div class="settings-field">
                <label for="enr_tuition">Tuition Per Unit (₱)</label>
                <input type="number" step="0.01" id="enr_tuition" name="tuition_per_unit" value="<?= h((string) $tuition) ?>">
            </div>
            <div class="settings-field">
                <label for="enr_fees">Other School Fees (₱)</label>
                <input type="number" step="0.01" id="enr_fees" name="other_school_fees" value="<?= h((string) $otherFees) ?>">
            </div>
        </div>

        <div class="settings-form-grid" style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <div class="settings-field">
                <label for="enr_irregular_unit_cap">Unit Cap for Irregular Students</label>
                <input type="number" id="enr_irregular_unit_cap" name="irregular_unit_cap" value="<?= h((string) $irregularUnitCap) ?>" min="1" max="50">
                <div class="settings-field-hint">Maximum number of units an irregular student can enroll in per term. Set to 0 for no cap.</div>
            </div>
        </div>

        <div class="settings-form-grid" style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <div class="settings-field">
                <label for="enr_adviser_days">Adviser Approval Deadline (days)</label>
                <input type="number" id="enr_adviser_days" name="adviser_approval_days" value="<?= h(setting('adviser_approval_days', '3')) ?>" min="1" max="365">
                <div class="settings-field-hint">Days from request submission for adviser to approve. After this, approval is blocked.</div>
            </div>
            <div class="settings-field">
                <label for="enr_chair_days">Chair Approval Deadline (days)</label>
                <input type="number" id="enr_chair_days" name="chair_approval_days" value="<?= h(setting('chair_approval_days', '3')) ?>" min="1" max="365">
                <div class="settings-field-hint">Days from adviser approval for department chair to approve.</div>
            </div>
            <div class="settings-field">
                <label for="enr_registrar_days">Registrar Finalization Deadline (days)</label>
                <input type="number" id="enr_registrar_days" name="registrar_approval_days" value="<?= h(setting('registrar_approval_days', '3')) ?>" min="1" max="365">
                <div class="settings-field-hint">Days from chair approval for registrar to finalize.</div>
            </div>
            <div class="settings-field">
                <label for="enr_grade_days">Grade Encoding Deadline (days)</label>
                <input type="number" id="enr_grade_days" name="grade_deadline_days" value="<?= h(setting('grade_deadline_days', '30')) ?>" min="1" max="365">
                <div class="settings-field-hint">Days after term end date for instructors to submit grades.</div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Enrollment Settings</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <h3>Enrollment Schedules</h3>
    <p class="settings-card-desc">Set per year-level enrollment windows.</p>
    <a class="btn secondary" href="<?= h(app_url('registrar/enrollment_schedule.php')) ?>">Manage Schedules &rarr;</a>
</div>
