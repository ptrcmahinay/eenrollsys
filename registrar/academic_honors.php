<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_honor') {
        $id = (int) ($_POST['honor_id'] ?? 0);
        $minGwa = $_POST['minimum_gwa'] !== '' ? (float) $_POST['minimum_gwa'] : null;
        $maxGwa = $_POST['maximum_gwa'] !== '' ? (float) $_POST['maximum_gwa'] : null;
        $minGrade = $_POST['minimum_passing_grade'] !== '' ? (float) $_POST['minimum_passing_grade'] : null;
        $allowFailed = isset($_POST['allow_failed_grade']) ? 1 : 0;
        $allowInc = isset($_POST['allow_inc']) ? 1 : 0;
        $allowDrp = isset($_POST['allow_drp']) ? 1 : 0;
        $allowW = isset($_POST['allow_withdrawal']) ? 1 : 0;
        $requiredUnits = $_POST['required_units'] !== '' ? (float) $_POST['required_units'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        db()->prepare(
            "UPDATE academic_honor_rules SET minimum_gwa = ?, maximum_gwa = ?, minimum_passing_grade = ?,
             allow_failed_grade = ?, allow_inc = ?, allow_drp = ?, allow_withdrawal = ?,
             required_units = ?, is_active = ? WHERE id = ?"
        )->execute([$minGwa, $maxGwa, $minGrade, $allowFailed, $allowInc, $allowDrp, $allowW, $requiredUnits, $isActive, $id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Honor rule updated.']);
        exit;
    }

    if ($action === 'add_honor') {
        $name = trim($_POST['new_honor_name'] ?? '');
        if ($name === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Honor name is required.']);
            exit;
        }

        $maxOrder = db()->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM academic_honor_rules")->fetchColumn();

        db()->prepare(
            "INSERT INTO academic_honor_rules (honor_name, minimum_gwa, maximum_gwa, minimum_passing_grade,
             allow_failed_grade, allow_inc, allow_drp, allow_withdrawal, required_units, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $name,
            null, null, null,
            0, 0, 0, 0, null,
            $maxOrder,
        ]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Honor rule '{$name}' added."]);
        exit;
    }

    if ($action === 'delete_honor') {
        $id = (int) ($_POST['honor_id'] ?? 0);
        db()->prepare("DELETE FROM academic_honor_rules WHERE id = ?")->execute([$id]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Honor rule deleted.']);
        exit;
    }
}

$rules = GradingEngine::getHonorRules();
$allRules = db()->query("SELECT * FROM academic_honor_rules ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Academic Honors Configuration</h1>
        <p>Configure Latin Honors rules. The system evaluates eligibility based on these rules.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('includes/settings.php?tab=academic')) ?>">&larr; Back to Settings</a>
    </div>
</div>

<?php foreach ($allRules as $rule): ?>
<div class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;"><?= h($rule['honor_name']) ?></h3>
        <div>
            <span class="badge <?= $rule['is_active'] ? 'success' : 'secondary' ?>"><?= $rule['is_active'] ? 'Active' : 'Inactive' ?></span>
        </div>
    </div>

    <form class="honor-form" data-id="<?= $rule['id'] ?>" style="margin-top:16px;">
        <div class="settings-form-grid cols-3">
            <div class="settings-field">
                <label>GWA Range (Minimum)</label>
                <input type="number" step="0.01" name="minimum_gwa" value="<?= $rule['minimum_gwa'] !== null ? h((string) $rule['minimum_gwa']) : '' ?>" placeholder="e.g. 1.00">
            </div>
            <div class="settings-field">
                <label>GWA Range (Maximum)</label>
                <input type="number" step="0.01" name="maximum_gwa" value="<?= $rule['maximum_gwa'] !== null ? h((string) $rule['maximum_gwa']) : '' ?>" placeholder="e.g. 1.20">
            </div>
            <div class="settings-field">
                <label>Lowest Allowed Grade</label>
                <input type="number" step="0.01" name="minimum_passing_grade" value="<?= $rule['minimum_passing_grade'] !== null ? h((string) $rule['minimum_passing_grade']) : '' ?>" placeholder="e.g. 1.50">
            </div>
        </div>
        <div class="settings-form-grid" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;">
            <div class="settings-field">
                <label>Required Earned Units (optional)</label>
                <input type="number" step="0.5" name="required_units" value="<?= $rule['required_units'] !== null ? h((string) $rule['required_units']) : '' ?>" placeholder="Leave blank if no requirement">
            </div>
        </div>
        <div class="settings-form-grid" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;">
            <label class="settings-checkbox"><input type="checkbox" name="allow_failed_grade" <?= $rule['allow_failed_grade'] ? 'checked' : '' ?>> Allow Failed Grades</label>
            <label class="settings-checkbox"><input type="checkbox" name="allow_inc" <?= $rule['allow_inc'] ? 'checked' : '' ?>> Allow INC</label>
            <label class="settings-checkbox"><input type="checkbox" name="allow_drp" <?= $rule['allow_drp'] ? 'checked' : '' ?>> Allow DRP</label>
            <label class="settings-checkbox"><input type="checkbox" name="allow_withdrawal" <?= $rule['allow_withdrawal'] ? 'checked' : '' ?>> Allow Withdrawal (W)</label>
            <label class="settings-checkbox"><input type="checkbox" name="is_active" <?= $rule['is_active'] ? 'checked' : '' ?>> Active</label>
        </div>
        <div class="settings-actions">
            <button type="button" class="btn" onclick="saveHonor(<?= $rule['id'] ?>)">Save</button>
            <button type="button" class="btn danger" onclick="deleteHonor(<?= $rule['id'] ?>)">Delete</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<div class="card" style="margin-top:16px;">
    <h3>Add New Honor Rule</h3>
    <form id="addHonorForm" onsubmit="return addHonor()">
        <div class="settings-form-grid">
            <div class="settings-field">
                <label>Honor Name</label>
                <input type="text" name="new_honor_name" required placeholder="e.g. Dean's List">
            </div>
        </div>
        <div class="settings-actions">
            <button type="submit" class="btn">Add Honor Rule</button>
        </div>
    </form>
</div>

<script>
function saveHonor(id) {
    var form = document.querySelector('.honor-form[data-id="' + id + '"]');
    var formData = new FormData(form);
    formData.append('action', 'update_honor');
    formData.append('honor_id', id);

    fetch('<?= h(app_url('registrar/academic_honors.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { flashMsg('success', data.message); }
        else { flashMsg('error', data.message); }
    }).catch(() => flashMsg('error', 'Request failed.'));
}

function deleteHonor(id) {
    if (!confirm('Delete this honor rule?')) return;
    var formData = new FormData();
    formData.append('action', 'delete_honor');
    formData.append('honor_id', id);

    fetch('<?= h(app_url('registrar/academic_honors.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { flashMsg('success', data.message); setTimeout(() => location.reload(), 600); }
        else { flashMsg('error', data.message); }
    }).catch(() => flashMsg('error', 'Request failed.'));
}

function addHonor() {
    var form = document.getElementById('addHonorForm');
    var formData = new FormData(form);
    formData.append('action', 'add_honor');

    fetch('<?= h(app_url('registrar/academic_honors.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { flashMsg('success', data.message); setTimeout(() => location.reload(), 600); }
        else { flashMsg('error', data.message); }
    }).catch(() => flashMsg('error', 'Request failed.'));
    return false;
}

function flashMsg(type, msg) {
    var existing = document.querySelector('.settings-flash');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'settings-flash settings-flash-' + type;
    el.textContent = msg;
    document.querySelector('.card').insertBefore(el, document.querySelector('.card').firstChild);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3000);
}
</script>
<?php
render_page('Academic Honors', 'Academic Honors Configuration', (string) ob_get_clean());
