<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_role(['admin', 'registrar']);

$flashes = get_flashes();

$program_id = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
$semester   = trim((string) ($_GET['semester'] ?? ''));

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs WHERE status = "active" ORDER BY program_code');
$academicYears = fetch_all('SELECT id, year_label FROM academic_years WHERE status = "active" ORDER BY start_year DESC');
$semesterOpts = ['1' => 'First Semester', '2' => 'Second Semester', 'mid' => 'Midyear'];

$feeItems = [];
$grouped  = ['laboratory' => [], 'other' => [], 'assessment' => []];
$totals   = ['laboratory' => 0, 'other' => 0, 'assessment' => 0];

if ($program_id > 0) {
    $params = ['program_id' => $program_id];
    $sql = "SELECT * FROM fee_items
            WHERE is_active = 1
              AND (program_id = :program_id OR program_id IS NULL)";

    if ($semester !== '') {
        $sql .= " AND (semester = :semester OR semester IS NULL)";
        $params['semester'] = $semester;
    }

    $sql .= " ORDER BY FIELD(category, 'laboratory','other','assessment'), fee_name";
    $feeItems = fetch_all($sql, $params);

    foreach ($feeItems as $item) {
        $cat = $item['category'];
        $grouped[$cat][] = $item;
        $totals[$cat] += (float) $item['amount'];
    }
}

$grandTotal = $totals['laboratory'] + $totals['other'] + $totals['assessment'];

$catLabels = [
    'laboratory' => 'Laboratory Fees',
    'other'      => 'Other Fees',
    'assessment' => 'Assessment Fees',
];

$catIcons = [
    'laboratory' => 'biotech',
    'other'      => 'receipt_long',
    'assessment' => 'account_balance',
];

$editModalBodies = [];

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Fee Management</h1>
        <p>Configure tuition fee breakdown for programs.</p>
    </div>
    <?php if ($program_id > 0 && $feeItems !== []): ?>
    <button class="btn" onclick="verifyFees()" id="verifyBtn">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">verified</span>
        Verify Fee
    </button>
    <?php endif; ?>
</div>

<div id="verifyResult" style="display:none;margin-bottom:16px;"></div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <form method="get" class="form-inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569;">Program</label>
            <select name="program_id" style="min-width:180px;">
                <option value="">-- Select Program --</option>
                <?php foreach ($programs as $p): ?>
                <option value="<?= (int) $p['programs_id'] ?>" <?= $program_id === (int) $p['programs_id'] ? 'selected' : '' ?>>
                    <?= h($p['program_code'] . ' — ' . $p['program_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569;">School Year</label>
            <select name="year_id" style="min-width:160px;">
                <option value="">-- Select Year --</option>
                <?php foreach ($academicYears as $y): ?>
                <option value="<?= (int) $y['id'] ?>" <?= (isset($_GET['year_id']) && (int) $_GET['year_id'] === (int) $y['id']) ? 'selected' : '' ?>>
                    <?= h($y['year_label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569;">Semester</label>
            <select name="semester" style="min-width:160px;">
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesterOpts as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $semester === $val ? 'selected' : '' ?>>
                    <?= h($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">search</span>
                Load
            </button>
            <a href="fees.php" class="btn secondary">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">clear</span>
                Clear
            </a>
        </div>
    </form>
</div>

<?php if ($program_id > 0): ?>

<div class="fee-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px;">
    <?php foreach (['laboratory', 'other', 'assessment'] as $cat): $items = $grouped[$cat]; ?>
    <div class="card" style="display:flex;flex-direction:column;">
        <div class="card-header" style="display:flex;align-items:center;gap:8px;padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px;">
            <span class="material-symbols-outlined" style="color:var(--primary);"><?= h($catIcons[$cat]) ?></span>
            <h3 style="flex:1;font-size:15px;"><?= h($catLabels[$cat]) ?></h3>
            <span style="font-size:13px;font-weight:700;color:var(--primary);">₱<?= h(format_money($totals[$cat])) ?></span>
        </div>

        <?php if ($items === []): ?>
        <div style="text-align:center;padding:24px 0;color:#94a3b8;font-size:13px;">
            <span class="material-symbols-outlined" style="font-size:32px;display:block;margin-bottom:8px;">playlist_add</span>
            No fees configured
        </div>
        <?php else: ?>
        <div style="flex:1;">
            <?php foreach ($items as $fee): ?>
            <div class="fee-row" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:500;color:var(--ink);"><?= h($fee['fee_name']) ?></div>
                    <div style="font-size:11px;color:#94a3b8;display:flex;gap:8px;margin-top:2px;">
                        <?php if ($fee['is_mandatory']): ?>
                        <span style="color:var(--warning);">Mandatory</span>
                        <?php endif; ?>
                        <?php if ($fee['program_id'] === null): ?>
                        <span>All programs</span>
                        <?php endif; ?>
                        <?php if ($fee['year_level'] !== null): ?>
                        <span>Year <?= (int) $fee['year_level'] ?></span>
                        <?php endif; ?>
                        <?php if ($fee['semester'] !== null): ?>
                        <span><?= h(semester_label($fee['semester'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:14px;font-weight:700;color:var(--ink);white-space:nowrap;">₱<?= h(format_money($fee['amount'])) ?></div>
                <div class="row-actions" style="display:flex;gap:2px;flex-shrink:0;">
                    <button class="icon-btn" title="Edit" onclick="openEditModal(<?= (int) $fee['id'] ?>)">
                        <span class="material-symbols-outlined">edit</span>
                    </button>
                    <form method="post" action="fees_handler.php" onsubmit="return confirm('Delete this fee entry?');" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $fee['id'] ?>">
                        <input type="hidden" name="program_id" value="<?= (int) $program_id ?>">
                        <input type="hidden" name="semester" value="<?= h($semester) ?>">
                        <button class="icon-btn danger" type="submit" title="Delete">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);">
            <button class="btn secondary" style="width:100%;font-size:12px;" onclick="openAddModal('<?= h($cat) ?>')">
                <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">add</span>
                Add Fee
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;gap:24px;flex-wrap:wrap;">
            <div><span style="color:#64748b;font-size:13px;">Lab Fees:</span> <strong>₱<?= h(format_money($totals['laboratory'])) ?></strong></div>
            <div><span style="color:#64748b;font-size:13px;">Other Fees:</span> <strong>₱<?= h(format_money($totals['other'])) ?></strong></div>
            <div><span style="color:#64748b;font-size:13px;">Assessment Fees:</span> <strong>₱<?= h(format_money($totals['assessment'])) ?></strong></div>
            <div style="border-left:2px solid var(--line);padding-left:16px;">
                <span style="color:var(--ink);font-size:13px;font-weight:700;">Grand Total:</span>
                <strong style="font-size:18px;color:var(--primary);">₱<?= h(format_money($grandTotal)) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="card" style="text-align:center;padding:48px 24px;">
    <span class="material-symbols-outlined" style="font-size:48px;color:#cbd5e1;display:block;margin-bottom:12px;">receipt_long</span>
    <h3 style="margin-bottom:8px;">Select a Program to Begin</h3>
    <p style="color:#64748b;font-size:14px;">Choose a program, school year, and semester above, then click <strong>Load</strong> to view and manage fees.</p>
</div>
<?php endif; ?>

<?php
// Build edit modals for each fee item
foreach ($feeItems as $fee):
    $editId = 'editModal_' . $fee['id'];
    ob_start();
?>
<form method="post" action="fees_handler.php">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= (int) $fee['id'] ?>">
    <input type="hidden" name="program_id" value="<?= (int) $program_id ?>">
    <input type="hidden" name="semester" value="<?= h($semester) ?>">
    <div class="form-grid">
        <div>
            <label>Category</label>
            <select name="category" required>
                <?php foreach (['laboratory' => 'Laboratory Fees', 'other' => 'Other Fees', 'assessment' => 'Assessment Fees'] as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= $fee['category'] === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Fee Name</label>
            <input type="text" name="fee_name" value="<?= h($fee['fee_name']) ?>" required>
        </div>
        <div>
            <label>Amount (₱)</label>
            <input type="number" name="amount" step="0.01" min="0" value="<?= h($fee['amount']) ?>" required>
        </div>
        <div>
            <label>Program</label>
            <select name="program_id_fk">
                <option value="">All Programs</option>
                <?php foreach ($programs as $p): ?>
                <option value="<?= (int) $p['programs_id'] ?>" <?= ($fee['program_id'] !== null && (int) $fee['program_id'] === (int) $p['programs_id']) ? 'selected' : '' ?>>
                    <?= h($p['program_code']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level">
                <option value="">All Years</option>
                <?php for ($yl = 1; $yl <= 4; $yl++): ?>
                <option value="<?= $yl ?>" <?= $fee['year_level'] !== null && (int) $fee['year_level'] === $yl ? 'selected' : '' ?>>
                    Year <?= $yl ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label>Semester</label>
            <select name="semester_filter">
                <option value="">All Semesters</option>
                <?php foreach ($semesterOpts as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= $fee['semester'] !== null && $fee['semester'] === $v ? 'selected' : '' ?>>
                    <?= h($l) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:24px;">
                <input type="checkbox" name="is_mandatory" value="1" <?= $fee['is_mandatory'] ? 'checked' : '' ?>>
                Mandatory fee
            </label>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn">Update Fee</button>
        <button type="button" class="btn secondary" data-close="<?= h($editId) ?>">Cancel</button>
    </div>
</form>
<?php
    $editModalBodies[] = render_modal($editId, 'Edit Fee — ' . h($fee['fee_name']), (string) ob_get_clean());
endforeach;
?>

<?php
// Add fee modal (reused for all categories, category pre-selected via JS)
ob_start();
?>
<form method="post" action="fees_handler.php" id="addFeeForm">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="program_id" value="<?= (int) $program_id ?>">
    <input type="hidden" name="semester" value="<?= h($semester) ?>">
    <input type="hidden" name="category" id="addCategory" value="">
    <div class="form-grid">
        <div>
            <label>Fee Name</label>
            <input type="text" name="fee_name" id="addFeeName" required>
        </div>
        <div>
            <label>Amount (₱)</label>
            <input type="number" name="amount" id="addAmount" step="0.01" min="0" required>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level" id="addYearLevel">
                <option value="">All Years</option>
                <?php for ($yl = 1; $yl <= 4; $yl++): ?>
                <option value="<?= $yl ?>">Year <?= $yl ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label>Semester</label>
            <select name="semester_filter" id="addSemester">
                <option value="">All Semesters</option>
                <?php foreach ($semesterOpts as $v => $l): ?>
                <option value="<?= h($v) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:24px;">
                <input type="checkbox" name="is_mandatory" value="1">
                Mandatory fee
            </label>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn">Add Fee</button>
        <button type="button" class="btn secondary" data-close="addModal">Cancel</button>
    </div>
</form>
<?php
$addModal = render_modal('addModal', 'Add New Fee', (string) ob_get_clean());

$allModals = array_merge([$addModal], $editModalBodies);
$content = ob_get_clean();

render_page('Fee Management', 'Fee Management', $content, [
    'modals' => $allModals,
]);
?>
<script>
function openAddModal(category) {
    document.getElementById('addCategory').value = category;

    const labels = { laboratory: 'Laboratory Fees', other: 'Other Fees', assessment: 'Assessment Fees' };
    const modal = document.getElementById('addModal');
    if (modal) {
        const h3 = modal.querySelector('.modal-header h3');
        if (h3) h3.textContent = 'Add Fee — ' + (labels[category] || category);
    }

    document.getElementById('addFeeForm').querySelector('input[name="fee_name"]').value = '';
    document.getElementById('addFeeForm').querySelector('input[name="amount"]').value = '';
    document.getElementById('addFeeForm').querySelector('select[name="year_level"]').value = '';
    document.getElementById('addFeeForm').querySelector('select[name="semester_filter"]').value = '';
    document.getElementById('addFeeForm').querySelector('input[name="is_mandatory"]').checked = false;

    document.getElementById('addModal').classList.add('active');
}

function openEditModal(feeId) {
    const el = document.getElementById('editModal_' + feeId);
    if (el) el.classList.add('active');
}

function verifyFees() {
    const params = new URLSearchParams({
        action: 'verify',
        program_id: <?= json_encode((string) $program_id) ?>,
        semester: <?= json_encode($semester) ?>
    });

    fetch('fees_handler.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('verifyResult');
            if (data.success) {
                container.innerHTML =
                    '<div class="card" style="border-left:4px solid var(--success);padding:16px;">' +
                    '<div style="display:flex;align-items:center;gap:12px;">' +
                    '<span class="material-symbols-outlined" style="color:var(--success);font-size:28px;">verified</span>' +
                    '<div>' +
                    '<strong style="font-size:16px;">Fees Verified</strong>' +
                    '<div style="margin-top:4px;color:#475569;font-size:13px;">Total fees: <strong>₱' + data.grand_total + '</strong></div>' +
                    '<div style="margin-top:2px;color:#64748b;font-size:12px;">Laboratory: ₱' + data.laboratory + ' &middot; Other: ₱' + data.other + ' &middot; Assessment: ₱' + data.assessment + '</div>' +
                    '</div></div></div>';
            } else {
                container.innerHTML =
                    '<div class="card" style="border-left:4px solid var(--danger);padding:16px;">' +
                    '<div style="display:flex;align-items:center;gap:12px;">' +
                    '<span class="material-symbols-outlined" style="color:var(--danger);font-size:28px;">warning</span>' +
                    '<div><strong>Verification Failed</strong><div style="color:#64748b;font-size:13px;">' + (data.message || 'No fees found for the selected criteria.') + '</div></div>' +
                    '</div></div>';
            }
            container.style.display = 'block';
            container.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })
        .catch(() => {
            document.getElementById('verifyResult').innerHTML =
                '<div class="card" style="border-left:4px solid var(--danger);padding:16px;">Error verifying fees. Please try again.</div>';
            document.getElementById('verifyResult').style.display = 'block';
        });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-close');
            var modal = document.getElementById(id);
            if (modal) modal.classList.remove('active');
        });
    });

    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
});
</script>
