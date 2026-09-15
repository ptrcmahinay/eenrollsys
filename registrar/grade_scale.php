<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

// Handle AJAX save
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_grade_scale') {
        $gradeCodes = $_POST['grade_code'] ?? [];
        $numericValues = $_POST['numeric_value'] ?? [];
        $isPassing = $_POST['is_passing'] ?? [];
        $isBlocking = $_POST['is_blocking'] ?? [];
        $countsForGwa = $_POST['counts_for_gwa'] ?? [];
        $isWithdrawal = $_POST['is_withdrawal'] ?? [];

        $update = db()->prepare(
            "UPDATE grade_scale SET numeric_value = ?, is_passing = ?, is_blocking = ?, counts_for_gwa = ?, is_withdrawal = ? WHERE grade_code = ?"
        );

        foreach ($gradeCodes as $i => $code) {
            $nv = $numericValues[$i] ?? null;
            $nv = $nv !== '' && $nv !== null ? (float) $nv : null;
            $update->execute([
                $nv,
                isset($isPassing[$i]) ? 1 : 0,
                isset($isBlocking[$i]) ? 1 : 0,
                isset($countsForGwa[$i]) ? 1 : 0,
                isset($isWithdrawal[$i]) ? 1 : 0,
                $code,
            ]);
        }

        GradingEngine::clearCache();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Grade scale updated.']);
        exit;
    }

    if ($action === 'add_grade') {
        $newCode = strtoupper(trim($_POST['new_grade_code'] ?? ''));
        $newNumeric = $_POST['new_numeric_value'] ?? null;
        $newPassing = isset($_POST['new_is_passing']) ? 1 : 0;
        $newBlocking = isset($_POST['new_is_blocking']) ? 1 : 0;
        $newGwa = isset($_POST['new_counts_for_gwa']) ? 1 : 0;
        $newWithdrawal = isset($_POST['new_is_withdrawal']) ? 1 : 0;

        if ($newCode === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Grade code is required.']);
            exit;
        }

        $maxOrder = db()->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM grade_scale")->fetchColumn();

        try {
            db()->prepare(
                "INSERT INTO grade_scale (grade_code, numeric_value, is_passing, is_blocking, counts_for_gwa, is_withdrawal, display_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $newCode,
                $newNumeric !== '' && $newNumeric !== null ? (float) $newNumeric : null,
                $newPassing,
                $newBlocking,
                $newGwa,
                $newWithdrawal,
                $maxOrder,
            ]);
            GradingEngine::clearCache();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Grade {$newCode} added."]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Grade code already exists.']);
        }
        exit;
    }

    if ($action === 'delete_grade') {
        $deleteCode = $_POST['delete_code'] ?? '';
        if ($deleteCode !== '') {
            db()->prepare("DELETE FROM grade_scale WHERE grade_code = ?")->execute([$deleteCode]);
            GradingEngine::clearCache();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Grade {$deleteCode} removed."]);
        exit;
    }
}

$scale = GradingEngine::getGradeScale();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Grade Scale Configuration</h1>
        <p>Configure the institutional grade scale. Changes take effect immediately for all grading operations.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('includes/settings.php?tab=academic')) ?>">&larr; Back to Settings</a>
    </div>
</div>

<div class="card">
    <h3>Current Grade Scale</h3>
    <form id="gradeScaleForm">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Grade Code</th>
                        <th>Numeric Value</th>
                        <th>Passing</th>
                        <th>Blocking</th>
                        <th>Counts for GWA</th>
                        <th>Withdrawal</th>
                        <th data-dt-no-sort>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scale as $code => $entry): ?>
                    <tr>
                        <td>
                            <strong><?= h($entry['grade_code']) ?></strong>
                            <input type="hidden" name="grade_code[]" value="<?= h($code) ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" name="numeric_value[]" value="<?= $entry['numeric_value'] !== null ? h((string) $entry['numeric_value']) : '' ?>" style="width:80px;" <?= $entry['numeric_value'] === null ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="is_passing[<?= h($code) ?>]" <?= $entry['is_passing'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="is_blocking[<?= h($code) ?>]" <?= $entry['is_blocking'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="counts_for_gwa[<?= h($code) ?>]" <?= $entry['counts_for_gwa'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="is_withdrawal[<?= h($code) ?>]" <?= $entry['is_withdrawal'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <button type="button" class="btn small danger" onclick="deleteGrade('<?= h($code) ?>')">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="settings-actions">
            <button type="button" class="btn" onclick="saveGradeScale()">Save Grade Scale</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Add New Grade</h3>
    <form id="addGradeForm" onsubmit="return addGrade()">
        <div class="settings-form-grid cols-3">
            <div class="settings-field">
                <label>Grade Code</label>
                <input type="text" name="new_grade_code" required maxlength="10" placeholder="e.g. WP">
            </div>
            <div class="settings-field">
                <label>Numeric Value (optional)</label>
                <input type="number" step="0.01" name="new_numeric_value" placeholder="Leave blank for non-numeric">
            </div>
        </div>
        <div class="settings-form-grid" style="margin-top:12px;">
            <label class="settings-checkbox"><input type="checkbox" name="new_is_passing"> Passing</label>
            <label class="settings-checkbox"><input type="checkbox" name="new_is_blocking"> Blocking</label>
            <label class="settings-checkbox"><input type="checkbox" name="new_counts_for_gwa"> Counts for GWA</label>
            <label class="settings-checkbox"><input type="checkbox" name="new_is_withdrawal"> Withdrawal</label>
        </div>
        <div class="settings-actions">
            <button type="submit" class="btn">Add Grade</button>
        </div>
    </form>
</div>

<script>
function saveGradeScale() {
    var form = document.getElementById('gradeScaleForm');
    var formData = new FormData(form);
    formData.append('action', 'update_grade_scale');

    fetch('<?= h(app_url('registrar/grade_scale.php')) ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            flashMsg('success', data.message);
            setTimeout(() => location.reload(), 600);
        } else {
            flashMsg('error', data.message);
        }
    })
    .catch(() => flashMsg('error', 'Request failed.'));
}

function addGrade() {
    var form = document.getElementById('addGradeForm');
    var formData = new FormData(form);
    formData.append('action', 'add_grade');

    fetch('<?= h(app_url('registrar/grade_scale.php')) ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            flashMsg('success', data.message);
            setTimeout(() => location.reload(), 600);
        } else {
            flashMsg('error', data.message);
        }
    })
    .catch(() => flashMsg('error', 'Request failed.'));
    return false;
}

function deleteGrade(code) {
    if (!confirm('Delete grade "' + code + '"?')) return;
    var formData = new FormData();
    formData.append('action', 'delete_grade');
    formData.append('delete_code', code);

    fetch('<?= h(app_url('registrar/grade_scale.php')) ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            flashMsg('success', data.message);
            setTimeout(() => location.reload(), 600);
        } else {
            flashMsg('error', data.message);
        }
    })
    .catch(() => flashMsg('error', 'Request failed.'));
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
render_page('Grade Scale', 'Grade Scale Configuration', (string) ob_get_clean());
