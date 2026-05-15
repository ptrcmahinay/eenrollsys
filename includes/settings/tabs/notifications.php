<?php
$user = current_user();
$role = $user['role'] ?? '';
$entityId = $user['student_id'] ?? $user['staff_id'] ?? $user['users_id'] ?? 0;
$prefix = 'pref_' . $entityId;
$notifEnrollment = setting($prefix . '_notif_enrollment', '1') === '1';
$notifGrade = setting($prefix . '_notif_grade', '1') === '1';
$notifSystem = setting($prefix . '_notif_system', '1') === '1';
?>

<div class="settings-card">
    <h3>Notification Preferences</h3>
    <p class="settings-card-desc">Choose which notifications you want to receive.</p>

    <form id="notifForm" onsubmit="return submitSettingsForm('notifForm', 'update_notifications')">
        <div class="settings-form-grid">
            <label class="settings-checkbox">
                <input type="checkbox" name="notif_enrollment" <?= $notifEnrollment ? 'checked' : '' ?>>
                <div>
                    <div style="font-weight:600;">Enrollment Updates</div>
                    <div style="font-size:12px;color:#94a3b8;">Approval status changes, request remarks, enrollment confirmations</div>
                </div>
            </label>
            <label class="settings-checkbox">
                <input type="checkbox" name="notif_grade" <?= $notifGrade ? 'checked' : '' ?>>
                <div>
                    <div style="font-weight:600;">Grade Announcements</div>
                    <div style="font-size:12px;color:#94a3b8;">Grade postings, grade corrections, academic standing changes</div>
                </div>
            </label>
            <label class="settings-checkbox">
                <input type="checkbox" name="notif_system" <?= $notifSystem ? 'checked' : '' ?>>
                <div>
                    <div style="font-weight:600;">System Notifications</div>
                    <div style="font-size:12px;color:#94a3b8;">Enrollment opening, deadline reminders, maintenance notices</div>
                </div>
            </label>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Preferences</button>
        </div>
    </form>
</div>
