<?php
$user = current_user();
$role = $user['role'] ?? '';
$initials = '';
$words = explode(' ', trim((string) ($user['display_name'] ?? 'User')));
foreach ($words as $w) { if ($w !== '') $initials .= strtoupper($w[0]); if (strlen($initials) >= 2) break; }
if ($initials === '') $initials = 'U';
$avatarPath = '';
if (!empty($user['profile_pic'])) {
    $avatarPath = app_url('uploads/' . $user['profile_pic']);
}
?>

<div class="settings-card">
    <h3>Profile</h3>
    <p class="settings-card-desc">Update your display name, email, password, and profile picture.</p>

    <form id="profileForm" onsubmit="return submitSettingsForm('profileForm', 'update_profile')">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div class="settings-profile-avatar" id="avatarPreview">
                <?php if ($avatarPath): ?>
                    <img src="<?= h($avatarPath) ?>" alt="Avatar">
                <?php else: ?>
                    <?= h($initials) ?>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;"><?= h($user['display_name']) ?></div>
                <div style="font-size:12px;color:#64748b;"><?= h(ucfirst(str_replace('_', ' ', $role))) ?></div>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer;font-size:12px;font-weight:600;color:#16a34a;">
                    <span class="material-symbols-outlined" style="font-size:16px;">add_a_photo</span>
                    Change photo
                    <input type="file" name="profile_pic" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
                </label>
            </div>
        </div>

        <div class="settings-form-grid cols-2">
            <div class="settings-field">
                <label for="profile_display_name">Display Name</label>
                <input type="text" id="profile_display_name" name="display_name" value="<?= h($user['display_name']) ?>">
            </div>
            <div class="settings-field">
                <label for="profile_email">Email Address</label>
                <input type="email" id="profile_email" name="email" value="<?= h($user['email']) ?>">
            </div>
        </div>

        <div class="settings-form-grid cols-2" style="margin-top:14px;">
            <div class="settings-field">
                <label for="profile_current_password">Current Password</label>
                <input type="password" id="profile_current_password" name="current_password" placeholder="Required to change password">
            </div>
            <div class="settings-field">
                <label for="profile_new_password">New Password</label>
                <input type="password" id="profile_new_password" name="password" placeholder="Leave blank to keep current">
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Changes</button>
        </div>
    </form>
</div>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('avatarPreview').innerHTML = '<img src="' + e.target.result + '">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
