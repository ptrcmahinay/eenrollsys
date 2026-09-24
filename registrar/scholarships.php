<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('registrar');

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_program') {
        $id = create_scholarship_program([
            'code'            => trim($_POST['code'] ?? ''),
            'name'            => trim($_POST['name'] ?? ''),
            'description'     => trim($_POST['description'] ?? ''),
            'type'            => trim($_POST['type'] ?? 'TUITION_WAIVER'),
            'duration_type'   => trim($_POST['duration_type'] ?? 'TERMS'),
            'duration_value'  => (int) ($_POST['duration_value'] ?? 10),
            'grace_period_terms' => (int) ($_POST['grace_period_terms'] ?? 2),
            'status'          => trim($_POST['status'] ?? 'ACTIVE'),
        ]);
        flash('success', 'Scholarship program created.');
        redirect('registrar/scholarships.php?action=edit&id=' . $id);
    }

    if ($postAction === 'update_program' && $id > 0) {
        update_scholarship_program($id, [
            'code'            => trim($_POST['code'] ?? ''),
            'name'            => trim($_POST['name'] ?? ''),
            'description'     => trim($_POST['description'] ?? ''),
            'type'            => trim($_POST['type'] ?? 'TUITION_WAIVER'),
            'duration_type'   => trim($_POST['duration_type'] ?? 'TERMS'),
            'duration_value'  => (int) ($_POST['duration_value'] ?? 10),
            'grace_period_terms' => (int) ($_POST['grace_period_terms'] ?? 2),
            'status'          => trim($_POST['status'] ?? 'ACTIVE'),
        ]);

        save_scholarship_rules($id, [
            'requires_regular_status'    => isset($_POST['requires_regular_status']) ? 1 : 0,
            'requires_active_enrollment' => isset($_POST['requires_active_enrollment']) ? 1 : 0,
            'allow_during_loa'           => isset($_POST['allow_during_loa']) ? 1 : 0,
            'max_year_level'             => $_POST['max_year_level'] ?? '',
            'min_year_level'             => $_POST['min_year_level'] ?? '',
            'priority'                   => (int) ($_POST['priority'] ?? 1),
            'status'                     => 'ACTIVE',
        ]);

        $benefits = [];
        $bNames = $_POST['benefit_fee_name'] ?? [];
        $bTypes = $_POST['benefit_type'] ?? [];
        $bValues = $_POST['benefit_value'] ?? [];
        for ($i = 0; $i < count($bNames); $i++) {
            $benefits[] = [
                'fee_item_name' => $bNames[$i] ?? '',
                'benefit_type'  => $bTypes[$i] ?? 'WAIVE',
                'benefit_value' => $bValues[$i] ?? 0,
                'status'        => 'ACTIVE',
            ];
        }
        save_scholarship_benefits($id, $benefits);

        flash('success', 'Scholarship program updated.');
        redirect('registrar/scholarships.php?action=edit&id=' . $id);
    }

    if ($postAction === 'assign_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $scholarshipId = (int) ($_POST['scholarship_id'] ?? 0);
        $termId = (int) ($_POST['term_id'] ?? 0);
        if ($studentId > 0 && $scholarshipId > 0 && $termId > 0) {
            assign_student_scholarship($studentId, $scholarshipId, $termId, $_SESSION['user_id'] ?? null, trim($_POST['remarks'] ?? ''));
            flash('success', 'Scholarship assigned to student.');
        }
        redirect('registrar/scholarships.php?action=view&id=' . $scholarshipId);
    }

    if ($postAction === 'revoke_student') {
        $ssid = (int) ($_POST['student_scholarship_id'] ?? 0);
        if ($ssid > 0) {
            revoke_student_scholarship($ssid, trim($_POST['remarks'] ?? ''));
            flash('success', 'Student scholarship revoked.');
        }
        redirect($_SERVER['HTTP_REFERER'] ?? 'registrar/scholarships.php');
    }

    if ($postAction === 'seed_ra10931') {
        seed_ra10931_scholarship();
        flash('success', 'RA 10931 Free Higher Education seeded.');
        redirect('registrar/scholarships.php');
    }
}

function seed_ra10931_scholarship(): void
{
    $existing = get_scholarship_program_by_code('RA10931');
    if ($existing) return;

    $id = create_scholarship_program([
        'code'            => 'RA10931',
        'name'            => 'Free Higher Education',
        'description'     => 'Tuition and other school fees waiver under RA 10931 (Universal Access to Quality Tertiary Education Act). Covers tuition, admission, athletic, computer, cultural, development, entrance, guidance, handbook, laboratory, library, medical/dental, registration, and school ID fees.',
        'type'            => 'TUITION_WAIVER',
        'duration_type'   => 'YEARS',
        'duration_value'  => 5,
        'grace_period_terms' => 2,
        'status'          => 'ACTIVE',
    ]);

    save_scholarship_rules($id, [
        'requires_regular_status'    => 0,
        'requires_active_enrollment' => 1,
        'allow_during_loa'           => 0,
        'max_year_level'             => '',
        'min_year_level'             => '',
        'priority'                   => 1,
        'status'                     => 'ACTIVE',
    ]);

    $coveredFees = ['tuition', 'admission', 'athletic', 'computer', 'cultural', 'development', 'entrance', 'guidance', 'handbook', 'laboratory', 'library', 'medical', 'registration', 'school_id'];
    $benefits = [];
    foreach ($coveredFees as $fee) {
        $benefits[] = [
            'fee_item_name' => $fee,
            'benefit_type'  => 'WAIVE',
            'benefit_value' => 0,
            'status'        => 'ACTIVE',
        ];
    }
    save_scholarship_benefits($id, $benefits);
}

$programs = get_all_scholarship_programs();
$feeItems = fetch_all('SELECT DISTINCT fee_name FROM fee_items WHERE is_active = 1 ORDER BY fee_name');
$editProgram = null;
$editRules = null;
$editBenefits = [];
$assignedStudents = [];

if ($action === 'edit' && $id > 0) {
    $editProgram = get_scholarship_program($id);
    $editRules = get_scholarship_rules($id);
    $editBenefits = get_scholarship_benefits($id);
    $assignedStudents = fetch_all(
        'SELECT ss.*, s.student_number, s.first_name, s.last_name,
                sp.code AS scholarship_code, sp.name AS scholarship_name
         FROM student_scholarships ss
         INNER JOIN students s ON s.id = ss.student_id
         INNER JOIN scholarship_programs sp ON sp.id = ss.scholarship_id
         WHERE ss.scholarship_id = :sid ORDER BY s.student_number',
        ['sid' => $id]
    );
}

$flashes = get_flashes();
$terms = fetch_all(
    'SELECT t.id, t.semester, ay.year_label, ay.start_year FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

ob_start();
?>
<div class="page-header">
    <h1 class="page-title">Scholarship Management</h1>
</div>

<?php if (!empty($flashes)): ?>
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <h2 style="font-size:15px;font-weight:700;">Scholarship Programs</h2>
    <div style="display:flex;gap:8px;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="seed_ra10931">
            <button class="btn btn-sm" type="submit">Seed RA 10931</button>
        </form>
        <a href="scholarships.php?action=new" class="btn btn-sm">+ Add Program</a>
    </div>
</div>

<div class="card" style="overflow-x:auto;">
<table class="table" style="font-size:13px;width:100%;">
    <thead>
        <tr><th>Code</th><th>Name</th><th>Type</th><th>Duration</th><th>Status</th><th>Assigned</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($programs)): ?>
        <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px;">No scholarship programs found.</td></tr>
    <?php else: foreach ($programs as $p): ?>
        <tr>
            <td><strong><?= h($p['code']) ?></strong></td>
            <td><?= h($p['name']) ?></td>
            <td><?= h(str_replace('_', ' ', $p['type'])) ?></td>
            <td><?= h($p['duration_value'] . ' ' . strtolower(str_replace('_', ' ', $p['duration_type']))) ?></td>
            <td><span style="color:<?= $p['status'] === 'ACTIVE' ? '#16a34a' : '#dc2626' ?>;"><?= h($p['status']) ?></span></td>
            <td><?= (int) $p['assigned_count'] ?></td>
            <td><a href="scholarships.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm">Edit</a></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<?php elseif ($action === 'new' || ($action === 'edit' && $editProgram)): ?>

<?php $isEdit = $action === 'edit' && $editProgram; ?>
<h2 style="font-size:15px;font-weight:700;margin-bottom:12px;"><?= $isEdit ? 'Edit Scholarship' : 'New Scholarship Program' ?></h2>

<div class="card">
<form method="post">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update_program' : 'create_program' ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">
        <div><label style="font-weight:600;display:block;">Code *</label><input type="text" name="code" value="<?= h($editProgram['code'] ?? '') ?>" style="width:100%;" required></div>
        <div><label style="font-weight:600;display:block;">Name *</label><input type="text" name="name" value="<?= h($editProgram['name'] ?? '') ?>" style="width:100%;" required></div>
    </div>
    <div style="font-size:13px;margin-top:8px;"><label style="font-weight:600;display:block;">Description</label><textarea name="description" style="width:100%;height:60px;"><?= h($editProgram['description'] ?? '') ?></textarea></div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;font-size:13px;margin-top:10px;">
        <div><label style="font-weight:600;display:block;">Type</label><select name="type" style="width:100%;">
            <?php foreach (['TUITION_WAIVER','DISCOUNT','GRANT','SUBSIDY'] as $t): ?>
            <option value="<?= $t ?>" <?= ($editProgram['type'] ?? '') === $t ? 'selected' : '' ?>><?= str_replace('_', ' ', $t) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label style="font-weight:600;display:block;">Duration Type</label><select name="duration_type" style="width:100%;">
            <?php foreach (['TERMS','YEARS','UNLIMITED'] as $dt): ?>
            <option value="<?= $dt ?>" <?= ($editProgram['duration_type'] ?? 'TERMS') === $dt ? 'selected' : '' ?>><?= $dt ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label style="font-weight:600;display:block;">Duration Value</label><input type="number" name="duration_value" value="<?= h((string) ($editProgram['duration_value'] ?? 10)) ?>" style="width:100%;"></div>
        <div><label style="font-weight:600;display:block;">Grace Period (terms)</label><input type="number" name="grace_period_terms" value="<?= h((string) ($editProgram['grace_period_terms'] ?? 2)) ?>" style="width:100%;"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;margin-top:10px;">
        <div><label style="font-weight:600;display:block;">Status</label><select name="status" style="width:100%;">
            <option value="ACTIVE" <?= ($editProgram['status'] ?? 'ACTIVE') === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
            <option value="INACTIVE" <?= ($editProgram['status'] ?? '') === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
        </select></div>
    </div>

    <?php if ($isEdit): ?>
    <hr class="soft" style="margin:12px 0;">
    <div style="font-weight:700;font-size:13px;margin-bottom:8px;">Eligibility Rules</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;font-size:13px;">
        <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="requires_regular_status" value="1" <?= ($editRules['requires_regular_status'] ?? 0) ? 'checked' : '' ?>> Requires Regular Status</label>
        <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="requires_active_enrollment" value="1" <?= ($editRules['requires_active_enrollment'] ?? 1) ? 'checked' : '' ?>> Requires Active Enrollment</label>
        <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" name="allow_during_loa" value="1" <?= ($editRules['allow_during_loa'] ?? 0) ? 'checked' : '' ?>> Allow During LOA</label>
        <div><label style="display:block;">Priority</label><input type="number" name="priority" value="<?= h((string) ($editRules['priority'] ?? 1)) ?>" style="width:100%;"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;margin-top:8px;">
        <div><label style="font-weight:600;display:block;">Min Year Level</label><input type="number" name="min_year_level" value="<?= h((string) ($editRules['min_year_level'] ?? '')) ?>" placeholder="None" style="width:100%;"></div>
        <div><label style="font-weight:600;display:block;">Max Year Level</label><input type="number" name="max_year_level" value="<?= h((string) ($editRules['max_year_level'] ?? '')) ?>" placeholder="None" style="width:100%;"></div>
    </div>

    <hr class="soft" style="margin:12px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div style="font-weight:700;font-size:13px;">Fee Benefits</div>
        <button type="button" class="btn btn-sm" onclick="addBenefit()">+ Add Benefit</button>
    </div>
    <div id="benefits-container" style="font-size:13px;">
        <?php if (empty($editBenefits)): ?>
        <div class="benefit-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;">
            <select name="benefit_fee_name[]" style="width:100%;"><option value="">— Select Fee —</option><?php foreach ($feeItems as $fi): ?><option value="<?= h($fi['fee_name']) ?>"><?= h($fi['fee_name']) ?></option><?php endforeach; ?></select>
            <select name="benefit_type[]" style="width:100%;"><option value="WAIVE">100% Waive</option><option value="DISCOUNT_PERCENT">% Discount</option><option value="DISCOUNT_FIXED">Fixed Discount</option></select>
            <input type="number" name="benefit_value[]" value="0" step="0.01" style="width:100%;">
            <button type="button" onclick="this.closest('.benefit-row').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;">✕</button>
        </div>
        <?php else: foreach ($editBenefits as $b): ?>
        <div class="benefit-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;">
            <select name="benefit_fee_name[]" style="width:100%;"><option value="">— Select Fee —</option><?php foreach ($feeItems as $fi): ?><option value="<?= h($fi['fee_name']) ?>" <?= $fi['fee_name'] === $b['fee_item_name'] ? 'selected' : '' ?>><?= h($fi['fee_name']) ?></option><?php endforeach; ?></select>
            <select name="benefit_type[]" style="width:100%;">
                <option value="WAIVE" <?= $b['benefit_type'] === 'WAIVE' ? 'selected' : '' ?>>100% Waive</option>
                <option value="DISCOUNT_PERCENT" <?= $b['benefit_type'] === 'DISCOUNT_PERCENT' ? 'selected' : '' ?>>% Discount</option>
                <option value="DISCOUNT_FIXED" <?= $b['benefit_type'] === 'DISCOUNT_FIXED' ? 'selected' : '' ?>>Fixed Discount</option>
            </select>
            <input type="number" name="benefit_value[]" value="<?= h($b['benefit_value']) ?>" step="0.01" style="width:100%;">
            <button type="button" onclick="this.closest('.benefit-row').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;">✕</button>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:12px;display:flex;gap:8px;">
        <button class="btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Program' ?></button>
        <a href="scholarships.php" style="line-height:2.2;">Cancel</a>
    </div>
</form>
</div>

<?php if ($isEdit): ?>
<hr class="soft" style="margin:16px 0;">
<h2 style="font-size:15px;font-weight:700;margin-bottom:12px;">Assigned Students (<?= count($assignedStudents) ?>)</h2>

<div class="card" style="margin-bottom:12px;">
    <div style="font-weight:600;font-size:13px;margin-bottom:8px;">Assign Student</div>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;font-size:13px;">
        <input type="hidden" name="action" value="assign_student">
        <input type="hidden" name="scholarship_id" value="<?= $id ?>">
        <div><label style="display:block;">Student ID</label><input type="number" name="student_id" style="width:120px;" required></div>
        <div><label style="display:block;">Start Term</label><select name="term_id" style="width:160px;">
            <?php foreach ($terms as $t): ?>
            <option value="<?= $t['id'] ?>"><?= h($t['year_label'] . ' — Sem ' . $t['semester']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label style="display:block;">Remarks</label><input type="text" name="remarks" style="width:200px;"></div>
        <button class="btn btn-sm" type="submit">Assign</button>
    </form>
</div>

<div class="card" style="overflow-x:auto;">
<table class="table" style="font-size:13px;width:100%;">
    <thead>
        <tr><th>Student #</th><th>Name</th><th>Status</th><th>Assigned</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($assignedStudents)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:12px;">No students assigned.</td></tr>
    <?php else: foreach ($assignedStudents as $as): ?>
        <tr>
            <td><?= h($as['student_number']) ?></td>
            <td><?= h($as['last_name'] . ', ' . $as['first_name']) ?></td>
            <td><span style="color:<?= $as['status'] === 'ACTIVE' ? '#16a34a' : ($as['status'] === 'REVOKED' ? '#dc2626' : '#ca8a04') ?>;"><?= h($as['status']) ?></span></td>
            <td><?= h($as['created_at'] ?? '') ?></td>
            <td>
                <?php if ($as['status'] === 'ACTIVE'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this scholarship?')">
                    <input type="hidden" name="action" value="revoke_student">
                    <input type="hidden" name="student_scholarship_id" value="<?= $as['id'] ?>">
                    <input type="hidden" name="remarks" value="Revoked by registrar">
                    <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:12px;">Revoke</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<script>
var feeItems = <?= json_encode(array_column($feeItems, 'fee_name')) ?>;
function addBenefit() {
    const c = document.getElementById('benefits-container');
    const d = document.createElement('div');
    d.className = 'benefit-row';
    d.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;';
    var opts = '<option value="">— Select Fee —</option>';
    feeItems.forEach(function(f) { opts += '<option value="' + f + '">' + f + '</option>'; });
    d.innerHTML = '<select name="benefit_fee_name[]" style="width:100%;">' + opts + '</select>' +
        '<select name="benefit_type[]" style="width:100%;"><option value="WAIVE">100% Waive</option><option value="DISCOUNT_PERCENT">% Discount</option><option value="DISCOUNT_FIXED">Fixed Discount</option></select>' +
        '<input type="number" name="benefit_value[]" value="0" step="0.01" style="width:100%;">' +
        '<button type="button" onclick="this.closest(\'.benefit-row\').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;">✕</button>';
    c.appendChild(d);
}
</script>

<?php endif; ?>
<?php
$content = ob_get_clean();
render_page('Scholarship Management', 'Scholarships', $content);
