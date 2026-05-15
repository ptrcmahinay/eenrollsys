<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
$user = require_login();
$role = $user['role'] ?? '';

$activeTab = $_GET['tab'] ?? 'profile';
$isAdmin = $role === 'admin';
$isStaff = in_array($role, ['admin', 'registrar'], true);

$tabs = [
    ['key' => 'profile', 'label' => 'Profile', 'icon' => 'person', 'roles' => ['all']],
    ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'notifications', 'roles' => ['all']],
    ['key' => 'enrollment', 'label' => 'Enrollment', 'icon' => 'app_registration', 'roles' => ['admin', 'registrar']],
    ['key' => 'academic', 'label' => 'Academic', 'icon' => 'school', 'roles' => ['admin', 'registrar']],
    ['key' => 'institution', 'label' => 'Institution', 'icon' => 'business', 'roles' => ['admin']],
    ['key' => 'smtp', 'label' => 'Email / SMTP', 'icon' => 'email', 'roles' => ['admin']],
];

$allowedTabs = array_filter($tabs, function($t) use ($role) {
    return in_array('all', $t['roles'], true) || in_array($role, $t['roles'], true);
});

if (!in_array($activeTab, array_map(fn($t) => $t['key'], $allowedTabs))) {
    $activeTab = 'profile';
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Settings</h1>
        <p>Manage your account and system configuration.</p>
    </div>
</div>

<div class="settings-tabs">
    <?php foreach ($allowedTabs as $tab): ?>
    <button class="settings-tab-btn <?= $tab['key'] === $activeTab ? 'active' : '' ?>"
            data-tab="<?= h($tab['key']) ?>"
            onclick="switchTab('<?= h($tab['key']) ?>')">
        <span class="material-symbols-outlined"><?= h($tab['icon']) ?></span>
        <span><?= h($tab['label']) ?></span>
    </button>
    <?php endforeach; ?>
</div>

<div class="settings-content" id="settingsContent">
    <?php include __DIR__ . '/settings/tabs/' . $activeTab . '.php'; ?>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
    var activeBtn = document.querySelector('[data-tab="' + tab + '"]');
    if (activeBtn) activeBtn.classList.add('active');
    window.location.search = 'tab=' + tab;
}

function submitSettingsForm(formId, action) {
    var form = document.getElementById(formId);
    var formData = new FormData(form);
    formData.append('action', action);
    var verify = {};
    formData.forEach(function(v, k) { verify[k] = v; });
    formData.append('verify', JSON.stringify(verify));

    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

    fetch('<?= h(app_url('includes/settings_handler.php')) ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(function(data) {
        if (btn) { btn.disabled = false; btn.textContent = 'Save Changes'; }
        if (data.success) {
            flashMsg('success', data.message);
            if (data.reload) setTimeout(function() { location.reload(); }, 600);
        } else {
            flashMsg('error', data.message || 'Save failed.');
        }
    })
    .catch(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Save Changes'; }
        flashMsg('error', 'Request failed. Try again.');
    });
    return false;
}

function flashMsg(type, msg) {
    var existing = document.querySelector('.settings-flash');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'settings-flash settings-flash-' + type;
    el.textContent = msg;
    document.getElementById('settingsContent').insertBefore(el, document.getElementById('settingsContent').firstChild);
    setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 400); }, 3000);
}
</script>

<style>
.settings-tabs {
    display: flex;
    gap: 4px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    border: 1px solid #e2e8f0;
    padding: 6px;
    margin: 16px 0;
    overflow-x: auto;
    flex-wrap: wrap;
}
.settings-tab-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    transition: all .15s;
    white-space: nowrap;
}
.settings-tab-btn:hover { background: #f0fdf4; color: #16a34a; }
.settings-tab-btn.active { background: #f0fdf4; color: #16a34a; font-weight: 600; }
.settings-tab-btn .material-symbols-outlined { font-size: 18px; }
.settings-content { min-width: 0; }
.settings-flash {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 13px;
    font-weight: 600;
    transition: opacity .4s;
}
.settings-flash-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.settings-flash-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
.settings-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    border: 1px solid #e2e8f0;
    padding: 24px;
    margin-bottom: 16px;
}
.settings-card h3 {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
}
.settings-card .settings-card-desc {
    margin: 0 0 16px;
    font-size: 13px;
    color: #64748b;
}
.settings-form-grid { display: grid; gap: 14px; }
.settings-form-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.settings-form-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
.settings-field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}
.settings-field input,
.settings-field select,
.settings-field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s;
}
.settings-field input:focus,
.settings-field select:focus,
.settings-field textarea:focus {
    border-color: #22c55e;
    outline: none;
    box-shadow: 0 0 0 3px rgba(34,197,94,.12);
}
.settings-field textarea { min-height: 70px; resize: vertical; }
.settings-field .settings-field-hint {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}
.settings-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}
.settings-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #374151;
    cursor: pointer;
}
.settings-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #22c55e;
}
.settings-profile-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #22c55e);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 28px;
    font-weight: 700;
    overflow: hidden;
    flex-shrink: 0;
}
.settings-profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.settings-sig-preview {
    max-height: 60px;
    margin-top: 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

@media (max-width: 768px) {
    .settings-tabs { flex-wrap: wrap; }
    .settings-tab-btn { padding: 6px 10px; font-size: 12px; }
    .settings-form-grid.cols-2,
    .settings-form-grid.cols-3 { grid-template-columns: 1fr; }
}
</style>
<?php
render_page('Settings', 'Settings', (string) ob_get_clean());
