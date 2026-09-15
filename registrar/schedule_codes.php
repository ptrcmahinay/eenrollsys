<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'chair']);

if (is_post()) {
    $action = trim($_POST['action'] ?? '');
    if ($action === 'generate') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        $programId = (int) ($_POST['program_id'] ?? 0);
        if ($termId > 0 && $programId > 0) {
            $result = generate_all_sched_codes_for_term_program($termId, $programId);
            flash($result['success'] ? 'success' : 'info', $result['message']);
        }
        redirect('registrar/schedule_codes.php');
    }

    if ($action === 'save_dept_digits') {
        $deptCodes = $_POST['dept_code'] ?? [];
        $digits = $_POST['dept_digit'] ?? [];
        $mapping = [];
        foreach ($deptCodes as $i => $code) {
            $code = trim((string) $code);
            $digit = trim((string) ($digits[$i] ?? ''));
            if ($code !== '' && $digit !== '' && preg_match('/^\d$/', $digit)) {
                $mapping[$code] = $digit;
            }
        }
        set_setting('dept_sched_digits', json_encode($mapping));
        flash('success', 'Department digit mapping saved.');
        redirect('registrar/schedule_codes.php#dept-digits');
    }

    if ($action === 'update_sched_code') {
        $scId = (int) ($_POST['sc_id'] ?? 0);
        $newCode = trim($_POST['new_sched_code'] ?? '');
        if ($scId > 0 && $newCode !== '') {
            $existing = fetch_one('SELECT id FROM section_subject_offerings WHERE sched_code = :code AND id != :id', ['code' => $newCode, 'id' => $scId]);
            if ($existing) {
                flash('error', 'Schedule code "' . $newCode . '" already exists.');
            } else {
                execute_sql('UPDATE section_subject_offerings SET sched_code = :code WHERE id = :id', ['code' => $newCode, 'id' => $scId]);
                flash('success', 'Schedule code updated to "' . $newCode . '".');
            }
        }
        $retailTerm = (int) ($_POST['retail_term_id'] ?? 0);
        $retailProgram = (int) ($_POST['retail_program_id'] ?? 0);
        $retailParams = $retailTerm > 0 && $retailProgram > 0 ? '?term_id=' . $retailTerm . '&program_id=' . $retailProgram : '';
        redirect('registrar/schedule_codes.php' . $retailParams);
    }
}

$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label,
            t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$programs = fetch_all(
    'SELECT p.programs_id, p.program_code, p.program_name, d.department_code, d.dept_id
     FROM programs p
     INNER JOIN departments d ON d.dept_id = p.department_id
     ORDER BY d.department_code, p.program_code'
);

$filterTerm = (int) ($_GET['term_id'] ?? 0);
$filterProgram = (int) ($_GET['program_id'] ?? 0);

$codes = [];
$selectedTerm = null;
$selectedProgram = null;
$uncodedCount = 0;

if ($filterTerm > 0 && $filterProgram > 0) {
    $selectedTerm = fetch_one('SELECT t.id, ay.start_year, t.semester, CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = :id', ['id' => $filterTerm]);
    $selectedProgram = fetch_one('SELECT programs_id, program_code, program_name FROM programs WHERE programs_id = :id', ['id' => $filterProgram]);

    $semesterMap = ['1' => '1st', '2' => '2nd', 'mid' => 'mid'];
    $pcSemester = $semesterMap[(string) ($selectedTerm['semester'] ?? '')] ?? (string) ($selectedTerm['semester'] ?? '');

    $codes = fetch_all(
        'SELECT o.sched_code, o.id AS sc_id, sec.year_level, sec.section_name,
                sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
                p.program_code
         FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
         WHERE o.term_id = :term_id AND sec.program_id = :program_id
           AND pc.semester = :semester
         ORDER BY o.sched_code',
        ['term_id' => $filterTerm, 'program_id' => $filterProgram, 'semester' => $pcSemester]
    );

    $uncodedCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
         WHERE o.term_id = :term_id AND sec.program_id = :program_id
           AND pc.semester = :semester
           AND (o.sched_code IS NULL OR o.sched_code = "")',
        ['term_id' => $filterTerm, 'program_id' => $filterProgram, 'semester' => $pcSemester]
    )['cnt'] ?? 0);
}

$deptDigits = [];
$deptRaw = setting('dept_sched_digits', '');
if ($deptRaw !== '') {
    $deptDigits = json_decode($deptRaw, true) ?: [];
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Schedule Codes</h1>
        <p>View, generate, and edit schedule codes for each term and program.</p>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 8px;">Schedule Code Format Guide</h3>
    <p style="margin:0 0 8px;color:#64748b;font-size:13px;">Each schedule code follows this structure:</p>
    <div style="display:flex;gap:4px;align-items:center;font-family:monospace;font-size:18px;margin-bottom:12px;flex-wrap:wrap;">
        <span class="badge" style="padding:6px 10px;" title="Academic Year (4 digits)">YYYY</span>
        <span style="color:#94a3b8;">+</span>
        <span class="badge" style="padding:6px 10px;" title="Semester: 1=1st, 2=2nd, 3=Midyear">S</span>
        <span style="color:#94a3b8;">+</span>
        <span class="badge info" style="padding:6px 10px;" title="Department digit (1 digit)">D</span>
        <span style="color:#94a3b8;">+</span>
        <span class="badge" style="padding:6px 10px;" title="Running sequence (3 digits)">NNN</span>
    </div>
    <div style="font-size:12px;color:#64748b;margin-bottom:12px;">
        <strong>Example:</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">202511001</code> = AY 2025, 1st Sem, Dept digit 1, sequence 001
    </div>
</div>

<div class="card" style="margin-bottom:16px;" id="dept-digits">
    <div style="margin-bottom:12px;">
        <h3 style="margin:0;">Department Digit Mapping</h3>
        <span class="helper">Each department is assigned a single digit (0-9) for schedule codes. New departments are auto-assigned the next available digit.</span>
    </div>
    <?php
    $allDepts = fetch_all('SELECT dept_id, department_code, department_name FROM departments WHERE status = "active" ORDER BY department_code');
    ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_dept_digits">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Department Code</th>
                        <th>Department Name</th>
                        <th style="width:120px;">Digit (0-9)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allDepts as $d): ?>
                    <tr>
                        <td><span class="badge" style="font-family:monospace;"><?= h($d['department_code']) ?></span></td>
                        <td><?= h($d['department_name']) ?></td>
                        <td>
                            <input type="hidden" name="dept_code[]" value="<?= h($d['department_code']) ?>">
                            <input type="number" name="dept_digit[]" min="0" max="9" required
                                   value="<?= h($deptDigits[$d['department_code']] ?? '9') ?>"
                                   style="width:80px;text-align:center;font-family:monospace;">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions" style="margin-top:12px;">
            <button class="btn" type="submit">Save Digit Mapping</button>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get">
        <div class="filter-bar">
            <div>
                <label>Academic Year / Semester</label>
                <select name="term_id">
                    <option value="">— Select Term —</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?><?= (int)$t['is_active'] === 1 ? ' (Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Program</label>
                <select name="program_id">
                    <option value="">— Select Program —</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h((string)$p['programs_id']) ?>" <?= $filterProgram === (int)$p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn secondary" type="submit">View Codes</button>
            <?php if ($filterTerm > 0 || $filterProgram > 0): ?>
                <a class="btn small secondary" href="<?= h(app_url('registrar/schedule_codes.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($filterTerm > 0 && $filterProgram > 0): ?>
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;">
            <?= h(($selectedTerm['label'] ?? '') . ' — ' . ($selectedProgram['program_code'] ?? '')) ?>
            <span class="badge info" style="font-size:11px;margin-left:8px;"><?= h((string)count($codes)) ?> codes</span>
        </h3>
        <?php if ($uncodedCount > 0): ?>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="term_id" value="<?= h((string)$filterTerm) ?>">
                <input type="hidden" name="program_id" value="<?= h((string)$filterProgram) ?>">
                <button class="btn" type="submit">Generate <?= $uncodedCount ?> Code(s)</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($codes === []): ?>
        <p class="helper" style="text-align:center;padding:20px;">
            No schedule codes yet.
            <?php if ($uncodedCount > 0): ?>
                Click <strong>Generate</strong> to create codes for uncoded offerings.
            <?php else: ?>
                No offerings found for this term and program.
            <?php endif; ?>
        </p>
    <?php else: ?>
    <div class="table-wrap" style="margin-top:12px;">
        <table>
            <thead>
                <tr>
                    <th>Sched Code</th>
                    <th>Section</th>
                    <th>Course Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th data-dt-no-sort>Edit</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($codes as $c): ?>
                <tr>
                    <td>
                        <span class="badge" style="font-family:monospace;" id="sched-display-<?= $c['sc_id'] ?>"><?= h($c['sched_code']) ?></span>
                    </td>
                    <td><?= h($c['program_code'] . ' ' . $c['year_level'] . $c['section_name']) ?></td>
                    <td><span class="badge"><?= h($c['subject_code']) ?></span></td>
                    <td><?= h($c['subject_description']) ?></td>
                    <td><?= h($c['units']) ?></td>
                    <td>
                        <button class="icon-btn" type="button" title="Edit" onclick="toggleEdit(<?= $c['sc_id'] ?>, '<?= h($c['sched_code']) ?>')">
                            <span class="material-symbols-outlined" style="font-size:18px;">edit</span>
                        </button>
                    </td>
                </tr>
                <tr id="edit-row-<?= $c['sc_id'] ?>" style="display:none;">
                    <td colspan="6" style="padding:8px 12px;background:#f8fafc;">
                        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_sched_code">
                            <input type="hidden" name="sc_id" value="<?= h((string)$c['sc_id']) ?>">
                            <input type="hidden" name="retail_term_id" value="<?= h((string)$filterTerm) ?>">
                            <input type="hidden" name="retail_program_id" value="<?= h((string)$filterProgram) ?>">
                            <label style="font-size:12px;">New Code:</label>
                            <input type="text" name="new_sched_code" value="<?= h($c['sched_code']) ?>" pattern="[A-Za-z0-9]{7,20}" required style="width:160px;font-family:monospace;">
                            <button class="btn small" type="submit">Save</button>
                            <button class="btn small secondary" type="button" onclick="toggleEdit(<?= $c['sc_id'] ?>, null)">Cancel</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function toggleEdit(id, code) {
    var row = document.getElementById('edit-row-' + id);
    if (row.style.display === 'none' || !row.style.display) {
        row.style.display = '';
    } else {
        row.style.display = 'none';
    }
}
</script>
<?php
render_page('Schedule Codes', 'Schedule Codes', (string) ob_get_clean());
