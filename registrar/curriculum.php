<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/forms.php';
$currentUser = require_role(['admin', 'registrar', 'chair', 'instructor']);
$role = $currentUser['role'] ?? '';
$canManage = in_array($role, ['admin', 'registrar'], true);

$deptScopeId = 0;
if (!$canManage) {
    $staff = current_staff();
    $deptScopeId = (int) ($staff['dept_id'] ?? 0);
}

/* ══════════════════════════════════════════════════════════════════════════
   POST handlers
   ══════════════════════════════════════════════════════════════════════════ */
if (is_post()) {
    $action = trim($_POST['action'] ?? '');
    $backProgram = (int) ($_POST['program_id'] ?? 0);
    if ($backProgram === 0) $backProgram = (int) ($_GET['program_id'] ?? 0);

    /* ── Program CRUD ── */
    if ($action === 'add_program') {
        $deptId  = (int)   ($_POST['department_id'] ?? 0);
        $code    = trim($_POST['program_code']  ?? '');
        $name    = trim($_POST['program_name']  ?? '');
        if ($deptId > 0 && $code !== '' && $name !== '') {
            execute_sql(
                'INSERT INTO programs (department_id, program_code, program_name, status, created_at)
                 VALUES (:dept, :code, :name, "active", NOW())',
                ['dept' => $deptId, 'code' => $code, 'name' => $name]
            );
            flash('success', 'Program created.');
        } else {
            flash('error', 'Fill in all program fields.');
        }
    }

    if ($action === 'update_program') {
        $progId  = (int) ($_POST['programs_id'] ?? 0);
        $deptId  = (int) ($_POST['department_id'] ?? 0);
        $code    = trim($_POST['program_code']  ?? '');
        $name    = trim($_POST['program_name']  ?? '');
        if ($progId > 0 && $deptId > 0 && $code !== '' && $name !== '') {
            execute_sql(
                'UPDATE programs SET department_id = :dept, program_code = :code, program_name = :name
                 WHERE programs_id = :id',
                ['dept' => $deptId, 'code' => $code, 'name' => $name, 'id' => $progId]
            );
            flash('success', 'Program updated.');
        } else {
            flash('error', 'Fill in all program fields.');
        }
    }

    if ($action === 'delete_program') {
        $progId = (int) ($_POST['programs_id'] ?? 0);
        if ($progId > 0) {
            execute_sql('UPDATE programs SET status = "inactive" WHERE programs_id = :id', ['id' => $progId]);
            flash('success', 'Program marked inactive.');
        }
    }

    if ($action === 'bulk_delete_programs' && $canManage) {
        $ids = $_POST['programs_id'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            execute_sql("UPDATE programs SET status = 'inactive' WHERE programs_id IN ({$ph})", $ids);
            flash('success', count($ids) . ' program(s) deleted.');
        }
    }

    /* ── Subject CRUD ── */
    if ($action === 'add_subject') {
        $code = trim($_POST['subject_code'] ?? '');
        $desc = trim($_POST['subject_description'] ?? '');
        $units = (float) ($_POST['units'] ?? 3);
        if ($code !== '' && $desc !== '') {
            execute_sql(
                'INSERT INTO subjects (subject_code, subject_description, units, created_at)
                 VALUES (:code, :desc, :units, NOW())',
                ['code' => $code, 'desc' => $desc, 'units' => $units]
            );
            flash('success', 'Subject created.');
        }
    }

    if ($action === 'update_subject') {
        $sid   = (int) ($_POST['subject_id'] ?? 0);
        $code  = trim($_POST['subject_code'] ?? '');
        $desc  = trim($_POST['subject_description'] ?? '');
        $units = (float) ($_POST['units'] ?? 3);
        if ($sid > 0 && $code !== '' && $desc !== '') {
            execute_sql(
                'UPDATE subjects SET subject_code = :code, subject_description = :desc, units = :units
                 WHERE subject_id = :id',
                ['code' => $code, 'desc' => $desc, 'units' => $units, 'id' => $sid]
            );
            flash('success', 'Subject updated.');
        }
    }

    if ($action === 'delete_subject') {
        $sid = (int) ($_POST['subject_id'] ?? 0);
        if ($sid > 0) {
            execute_sql('UPDATE subjects SET status = "inactive" WHERE subject_id = :id', ['id' => $sid]);
            flash('success', 'Subject marked inactive.');
        }
    }

    /* ── Curriculum CRUD ── */
    if ($action === 'add_curriculum') {
        $progId = (int) ($_POST['program_id'] ?? 0);
        $subjId = (int) ($_POST['subject_id'] ?? 0);
        $year   = trim($_POST['year_level'] ?? '1');
        $sem    = trim($_POST['semester'] ?? '1st');
        $label  = trim($_POST['curriculum_label'] ?? '2024');
        $prereq1 = ($_POST['prerequisite_subject_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_id'] : null;
        $prereq2 = ($_POST['prerequisite_subject_2_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_2_id'] : null;
        $prereq3 = ($_POST['prerequisite_subject_3_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_3_id'] : null;
        $standing = trim($_POST['standing'] ?? '');

        if ($progId > 0 && $subjId > 0) {
            $dup = fetch_one(
                'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid AND subject_id = :sid AND year_level = :yl AND semester = :sem AND curriculum_label = :label',
                ['pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem, 'label' => $label]
            );
            if ($dup !== null) {
                flash('error', 'This subject is already in the curriculum for Year ' . h($year) . ' ' . h($sem) . '.');
            } else {
                execute_sql(
                    'INSERT INTO program_curriculum
                        (program_id, subject_id, year_level, semester, prerequisite_subject_id,
                         prerequisite_subject_2_id, prerequisite_subject_3_id, standing, curriculum_label, created_at)
                     VALUES (:pid, :sid, :yl, :sem, :p1, :p2, :p3, :st, :label, NOW())',
                    [
                        'pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem,
                        'p1' => $prereq1, 'p2' => $prereq2, 'p3' => $prereq3,
                        'st' => $standing !== '' ? $standing : null,
                        'label' => $label,
                    ]
                );
                flash('success', 'Curriculum line added.');
            }
        }
    }

    if ($action === 'update_curriculum') {
        $cid    = (int) ($_POST['curriculum_id'] ?? 0);
        $progId = (int) ($_POST['program_id'] ?? 0);
        $subjId = (int) ($_POST['subject_id'] ?? 0);
        $year   = trim($_POST['year_level'] ?? '1');
        $sem    = trim($_POST['semester'] ?? '1st');
        $label  = trim($_POST['curriculum_label'] ?? '2024');
        $prereq1 = ($_POST['prerequisite_subject_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_id'] : null;
        $prereq2 = ($_POST['prerequisite_subject_2_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_2_id'] : null;
        $prereq3 = ($_POST['prerequisite_subject_3_id'] ?? '') !== '' ? (int) $_POST['prerequisite_subject_3_id'] : null;
        $standing = trim($_POST['standing'] ?? '');

        if ($cid > 0 && $progId > 0 && $subjId > 0) {
            $dup = fetch_one(
                'SELECT curriculum_id FROM program_curriculum WHERE curriculum_id != :cid AND program_id = :pid AND subject_id = :sid AND year_level = :yl AND semester = :sem AND curriculum_label = :label',
                ['cid' => $cid, 'pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem, 'label' => $label]
            );
            if ($dup !== null) {
                flash('error', 'Duplicate subject in this curriculum position.');
            } else {
                execute_sql(
                    'UPDATE program_curriculum
                     SET program_id = :pid, subject_id = :sid, year_level = :yl, semester = :sem,
                         prerequisite_subject_id = :p1, prerequisite_subject_2_id = :p2, prerequisite_subject_3_id = :p3,
                         standing = :st, curriculum_label = :label
                     WHERE curriculum_id = :cid',
                    [
                        'cid' => $cid, 'pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem,
                        'p1' => $prereq1, 'p2' => $prereq2, 'p3' => $prereq3,
                        'st' => $standing !== '' ? $standing : null,
                        'label' => $label,
                    ]
                );
                flash('success', 'Curriculum updated.');
            }
        }
    }

    if ($action === 'delete_curriculum') {
        $cid = (int) ($_POST['curriculum_id'] ?? 0);
        if ($cid > 0) {
            execute_sql('DELETE FROM program_curriculum WHERE curriculum_id = :id', ['id' => $cid]);
            flash('success', 'Curriculum line removed.');
        }
    }

    /* ── CSV Import ── */
    if ($action === 'import_curriculum' && isset($_FILES['curriculum_csv'])) {
        $progId = (int) ($_POST['import_program_id'] ?? 0);
        $label  = trim($_POST['import_label'] ?? '2024');
        $file   = $_FILES['curriculum_csv'];
    if ($progId > 0 && $file['error'] === UPLOAD_ERR_OK) {
        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ((int) $file['size'] > $maxSize) {
            flash('error', 'CSV file must be under 2 MB.');
            redirect('registrar/curriculum.php' . ($backProgram > 0 ? '?program_id=' . $backProgram : ''));
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            flash('error', 'Only CSV files are allowed.');
            redirect('registrar/curriculum.php' . ($backProgram > 0 ? '?program_id=' . $backProgram : ''));
        }

        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, ['text/plain', 'text/csv', 'application/vnd.ms-excel'], true)) {
            flash('error', 'File is not a valid CSV.');
            redirect('registrar/curriculum.php' . ($backProgram > 0 ? '?program_id=' . $backProgram : ''));
        }

        $tmp = $file['tmp_name'];
            $handle = fopen($tmp, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                $imported = 0; $skipped = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 4) { $skipped++; continue; }
                    $code = trim($row[0]); $desc = trim($row[1]); $units = (float) ($row[2] ?? 3);
                    $year = trim($row[3]); $sem = trim($row[4] ?? '1st');
                    $standing = isset($row[5]) ? trim($row[5]) : '';

                    $subj = fetch_one('SELECT subject_id FROM subjects WHERE subject_code = :code', ['code' => $code]);
                    if ($subj === null) {
                        execute_sql(
                            'INSERT INTO subjects (subject_code, subject_description, units) VALUES (:code, :desc, :units)',
                            ['code' => $code, 'desc' => $desc, 'units' => $units]
                        );
                        $subjId = (int) db()->lastInsertId();
                    } else {
                        $subjId = (int) $subj['subject_id'];
                    }

                    $dup = fetch_one(
                        'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid AND subject_id = :sid AND year_level = :yl AND semester = :sem AND curriculum_label = :label',
                        ['pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem, 'label' => $label]
                    );
                    if ($dup === null) {
                        execute_sql(
                            'INSERT INTO program_curriculum (program_id, subject_id, year_level, semester, standing, curriculum_label)
                             VALUES (:pid, :sid, :yl, :sem, :st, :label)',
                            ['pid' => $progId, 'sid' => $subjId, 'yl' => $year, 'sem' => $sem, 'st' => $standing !== '' ? $standing : null, 'label' => $label]
                        );
                        $imported++;
                    } else {
                        $skipped++;
                    }
                }
                fclose($handle);
                flash('success', "Imported {$imported} lines, skipped {$skipped} duplicates.");
            } else {
                flash('error', 'Could not read CSV file.');
            }
        } else {
            flash('error', 'Select a program and upload a valid CSV file.');
        }
    }

    $redirectUrl = 'registrar/curriculum.php';
    if ($backProgram > 0) $redirectUrl .= '?program_id=' . $backProgram;
    redirect($redirectUrl);
}

/* ══════════════════════════════════════════════════════════════════════════
   Data
   ══════════════════════════════════════════════════════════════════════════ */
$selectedProgramId = (int) ($_GET['program_id'] ?? 0);

$departments = fetch_all('SELECT dept_id, department_code, department_name FROM departments ORDER BY department_code');
$programs = fetch_all(
    'SELECT p.programs_id, p.program_code, p.program_name, p.status,
            d.department_code, d.department_name,
            COUNT(DISTINCT pc.curriculum_id) AS subject_count
     FROM programs p
     LEFT JOIN departments d ON d.dept_id = p.department_id
     LEFT JOIN program_curriculum pc ON pc.program_id = p.programs_id
     GROUP BY p.programs_id
     ORDER BY d.department_code, p.program_code'
);
$subjects = fetch_all('SELECT subject_id, subject_code, subject_description, units FROM subjects ORDER BY subject_code');

$curriculum = [];
$recentOfferings = [];
$selectedProgram = null;

if ($selectedProgramId > 0) {
    $selectedProgram = fetch_one(
        'SELECT p.*, d.department_code, d.department_name
         FROM programs p LEFT JOIN departments d ON d.dept_id = p.department_id
         WHERE p.programs_id = :id',
        ['id' => $selectedProgramId]
    );

    $curriculum = fetch_all(
        'SELECT pc.curriculum_id, pc.program_id, pc.subject_id, pc.year_level, pc.semester, pc.curriculum_label, pc.standing,
                pc.prerequisite_subject_id, pc.prerequisite_subject_2_id, pc.prerequisite_subject_3_id,
                sub.subject_code, sub.subject_description, sub.units, p.program_code,
                pre1.subject_code AS prereq1_code, pre2.subject_code AS prereq2_code, pre3.subject_code AS prereq3_code
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         INNER JOIN programs p ON p.programs_id = pc.program_id
         LEFT JOIN subjects pre1 ON pre1.subject_id = pc.prerequisite_subject_id
         LEFT JOIN subjects pre2 ON pre2.subject_id = pc.prerequisite_subject_2_id
         LEFT JOIN subjects pre3 ON pre3.subject_id = pc.prerequisite_subject_3_id
         WHERE pc.program_id = :pid
         ORDER BY CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code',
        ['pid' => $selectedProgramId]
    );
}
$grouped = [];
foreach ($curriculum as $line) {
    $grouped[$line['year_level']][$line['semester']][] = $line;
}

$unitsByYearSem = [];
$totalUnits = 0;
foreach ($curriculum as $line) {
    $yr = (int) $line['year_level'];
    $sem = $line['semester'];
    $units = (float) $line['units'];
    if (!isset($unitsByYearSem[$yr])) $unitsByYearSem[$yr] = [];
    if (!isset($unitsByYearSem[$yr][$sem])) $unitsByYearSem[$yr][$sem] = 0;
    $unitsByYearSem[$yr][$sem] += $units;
    $totalUnits += $units;
}
$unitsByYear = [];
foreach ($unitsByYearSem as $yr => $sems) {
    $unitsByYear[$yr] = array_sum($sems);
}

/* ══════════════════════════════════════════════════════════════════════════
   View
   ══════════════════════════════════════════════════════════════════════════ */
ob_start();
?>

<?php if ($selectedProgramId === 0): ?>
<!-- ═══════════════════════════════════════════════════════════════════
     PROGRAMS LIST (landing view)
     ═══════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div>
        <h1>Curriculum Management</h1>
        <p>Select a program to view and manage its curriculum, or add a new program below.</p>
    </div>
    <?php if ($canManage): ?>
    <div class="actions-row">
        <button class="btn" data-open="addProgramModal">Add Program</button>
        <button class="btn" data-open="editProgramModal">Edit Program</button>
        <button class="btn secondary" data-open="addSubjectModal">Add Subject</button>
        <button class="btn secondary" data-open="editSubjectModal">Edit Subject</button>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Programs</h3>
    <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('registrar/curriculum.php' . ($selectedProgramId ? '?program_id=' . $selectedProgramId : ''))) ?>" data-dt-bulk-id-field="programs_id" data-dt-bulk-action="bulk_delete_programs" data-dt-bulk-confirm="Delete selected programs?">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                    <th data-dt-key="code" data-dt-filter="select">Program Code</th>
                    <th data-dt-key="name">Program Name</th>
                    <th data-dt-key="dept" data-dt-filter="select">Department</th>
                    <th data-dt-key="count">Subjects</th>
                    <th data-dt-key="status" data-dt-filter="select">Status</th>
                    <?php if ($canManage): ?><th data-dt-no-sort data-dt-no-export>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (count($programs) === 0): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);">No programs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($programs as $prog): ?>
                <tr data-dt-row-id="<?= h((string)$prog['programs_id']) ?>">
                    <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$prog['programs_id']) ?>" aria-label="Select row"></td>
                    <td data-label="Code"><strong style="color:var(--primary);"><?= h($prog['program_code']) ?></strong></td>
                    <td data-label="Name"><?= h($prog['program_name']) ?></td>
                    <td data-label="Department"><?= h($prog['department_code'] . ' — ' . $prog['department_name']) ?></td>
                    <td data-label="Subjects"><span class="badge info"><?= (int) $prog['subject_count'] ?></span></td>
                    <td data-label="Status"><span class="badge <?= $prog['status'] === 'active' ? 'success' : 'danger' ?>"><?= h(ucfirst($prog['status'] ?? 'active')) ?></span></td>
                    <?php if ($canManage): ?>
                    <td data-label="Actions">
                        <div class="row-actions">
                            <a class="icon-btn" title="View Curriculum" href="<?= h(app_url('registrar/curriculum.php?program_id=' . $prog['programs_id'])) ?>">
                                <span class="material-symbols-outlined">visibility</span>
                            </a>
                            <button type="button" class="icon-btn" title="Edit Program"
                                onclick='openEditProgram(<?= json_encode([
                                    "id"=>$prog["programs_id"],
                                    "code"=>$prog["program_code"],
                                    "name"=>$prog["program_name"],
                                    "dept"=>(string)($prog["department_id"] ?? "")
                                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>);'>
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <form method="post" class="inline-form" onsubmit="return confirm('Mark this program inactive?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_program">
                                <input type="hidden" name="programs_id" value="<?= h((string)$prog['programs_id']) ?>">
                                <button class="icon-btn danger" type="submit" title="Delete"><span class="material-symbols-outlined">delete</span></button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════
     PROGRAM CURRICULUM DETAIL VIEW
     ═══════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="<?= h(app_url('registrar/curriculum.php')) ?>" class="icon-btn" title="Back to Programs">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1><?= h($selectedProgram['program_code'] ?? '') ?> — <?= h($selectedProgram['program_name'] ?? '') ?></h1>
            <p>
                <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;">apartment</span>
                <?= h(($selectedProgram['department_code'] ?? '') . ' — ' . ($selectedProgram['department_name'] ?? '')) ?>
            </p>
        </div>
    </div>
    <?php if ($canManage): ?>
    <div class="actions-row">
        <button class="btn secondary" data-open="subjectModal">Add Subject</button>
        <button class="btn" data-open="curriculumModal">Add Curriculum Line</button>
        <button class="btn secondary" data-open="importModal">Import CSV</button>
        <a class="btn secondary" href="<?= h(app_url('registrar/curriculum_export.php?program_id=' . $selectedProgramId . '&format=csv')) ?>">Export CSV</a>
    </div>
    <?php endif; ?>
</div>

<!-- Year summary chips -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
    <?php foreach ($unitsByYear as $yr => $units): ?>
    <div class="card slim" style="flex:none;min-width:130px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:var(--primary);"><?= h((string) $units) ?></div>
        <div style="font-size:11px;color:var(--muted);">Year <?= h((string) $yr) ?> units</div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px;">
            <?php foreach ($unitsByYearSem[$yr] as $sem => $su): ?>
                <?= h($sem) ?>: <?= h((string) $su) ?>u<?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="card slim" style="flex:none;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;"><?= h((string) $totalUnits) ?></div>
        <div style="font-size:11px;color:var(--muted);">Total units</div>
    </div>
</div>

<!-- Curriculum grouped by year + semester -->
<?php if (count($grouped) === 0): ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--muted);">
        <span class="material-symbols-outlined" style="font-size:40px;">menu_book</span>
        <p>No curriculum lines yet. Click <strong>Add Curriculum Line</strong> to start.</p>
    </div>
<?php else: ?>
<?php foreach ($grouped as $yearLevel => $semesters): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--primary);">school</span>
        Year <?= h((string) $yearLevel) ?>
        <span style="font-size:12px;font-weight:400;color:var(--muted);margin-left:6px;">
            (<?= h((string) ($unitsByYear[$yearLevel] ?? 0)) ?> units)
        </span>
    </h3>

    <?php
    $semOrder = ['1st' => '1st Semester', '2nd' => '2nd Semester', 'mid' => 'Midyear'];
    foreach ($semOrder as $semKey => $semLabel):
        if (!isset($semesters[$semKey])) continue;
        $lines    = $semesters[$semKey];
        $semUnits = array_sum(array_column($lines, 'units'));
    ?>
    <div style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <span style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;"><?= $semLabel ?></span>
            <span class="badge info" style="font-size:10px;"><?= h((string) $semUnits) ?> units</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Units</th>
                        <th>Prerequisites</th>
                        <th>Standing</th>
                        <th>Curriculum</th>
                        <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php
                    $prereqs = [];
                    if ($line['prereq1_code'] !== null) $prereqs[] = $line['prereq1_code'];
                    if ($line['prereq2_code'] !== null) $prereqs[] = $line['prereq2_code'];
                    if ($line['prereq3_code'] !== null) $prereqs[] = $line['prereq3_code'];
                    $prereqDisplay = count($prereqs) > 0 ? implode(', ', $prereqs) : '';
                    ?>
                    <tr>
                        <td><strong><?= h($line['subject_code']) ?></strong></td>
                        <td><?= h($line['subject_description']) ?></td>
                        <td><?= h((string) $line['units']) ?></td>
                        <td>
                            <?php if ($prereqDisplay !== ''): ?>
                                <span class="badge warning"><?= h($prereqDisplay) ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($line['standing'] !== null && $line['standing'] !== ''): ?>
                                <span class="badge"><?= h($line['standing']) ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);"><?= h($line['curriculum_label']) ?></td>
                        <?php if ($canManage): ?>
                        <td>
                            <div class="row-actions">
                                <button class="icon-btn" type="button" title="Edit"
                                    onclick='openEditCurriculum(<?= json_encode($line, JSON_HEX_TAG|JSON_HEX_APOS) ?>)'>
                                    <span class="material-symbols-outlined">edit</span>
                                </button>
                                <form class="inline-form" method="post" onsubmit="return confirm('Remove this curriculum line?');">
                                    <input type="hidden" name="action" value="delete_curriculum">
                                    <input type="hidden" name="curriculum_id" value="<?= h($line['curriculum_id']) ?>">
                                    <input type="hidden" name="program_id" value="<?= h((string) $selectedProgramId) ?>">
                                    <button class="icon-btn danger" type="submit" title="Remove">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function openEditCurriculum(line) {
    document.getElementById('ec_id').value = line.curriculum_id;
    document.getElementById('ec_program_id').value = line.program_id;
    document.getElementById('ec_subject_id').value = line.subject_id;
    document.getElementById('ec_year_level').value = line.year_level;
    document.getElementById('ec_semester').value = line.semester;
    document.getElementById('ec_label').value = line.curriculum_label;
    document.getElementById('ec_standing').value = line.standing || '';
    document.getElementById('ec_prereq1').value = line.prerequisite_subject_id || '';
    document.getElementById('ec_prereq2').value = line.prerequisite_subject_2_id || '';
    document.getElementById('ec_prereq3').value = line.prerequisite_subject_3_id || '';
    document.getElementById('editCurriculumModal').classList.add('active');
}
</script>

<?php endif; // end detail view ?>

<?php
/* ══════════════════════════════════════════════════════════════════════════
   Modals
   ══════════════════════════════════════════════════════════════════════════ */
ob_start(); ?>
<form method="post">
    <input type="hidden" name="action" value="add_program">
    <div class="form-grid">
        <div>
            <label>Department</label>
            <select name="department_id" required>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= h($d['dept_id']) ?>"><?= h($d['department_code'] . ' — ' . $d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Program Code</label>
            <input type="text" name="program_code" placeholder="e.g. BSCS" required>
        </div>
        <div>
            <label>Program Name</label>
            <input type="text" name="program_name" placeholder="e.g. Bachelor of Science in Computer Science" required>
        </div>
    </div>
    <div class="form-actions"><button class="btn" type="submit">Create Program</button></div>
</form>
<?php $addProgramForm = ob_get_clean();

ob_start(); ?>
<form method="post" id="editProgramForm">
    <input type="hidden" name="action" value="update_program">
    <input type="hidden" name="programs_id" id="ep_id">
    <div class="form-grid">
        <div>
            <label>Department</label>
            <select name="department_id" id="ep_dept" required>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= h($d['dept_id']) ?>"><?= h($d['department_code'] . ' — ' . $d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Program Code</label>
            <input type="text" name="program_code" id="ep_code" required>
        </div>
        <div>
            <label>Program Name</label>
            <input type="text" name="program_name" id="ep_name" required>
        </div>
    </div>
    <div class="form-actions">
        <button type="button" class="btn secondary" data-close>Cancel</button>
        <button class="btn" type="submit">Save Changes</button>
    </div>
</form>
<script>
function openEditProgram(p){
    document.getElementById('ep_id').value = p.id;
    document.getElementById('ep_code').value = p.code;
    document.getElementById('ep_name').value = p.name;
    var dept = document.getElementById('ep_dept');
    if (p.dept) dept.value = p.dept;
    document.getElementById('editProgramModal').classList.add('active');
}
</script>
<?php $editProgramForm = ob_get_clean();

ob_start(); ?>
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_curriculum">
    <input type="hidden" name="import_program_id" value="<?= $selectedProgramId ?>">
    <div class="form-grid cols-2">
        <div>
            <label>Curriculum Label</label>
            <input type="text" name="import_label" value="2024">
        </div>
        <div>
            <label>CSV File</label>
            <input type="file" name="curriculum_csv" accept=".csv" required>
        </div>
    </div>
    <p class="helper" style="margin-top:8px;">CSV format: <code>subject_code, description, units, year_level, semester, standing(optional)</code></p>
    <div class="form-actions"><button class="btn" type="submit">Import CSV</button></div>
</form>
<?php $importForm = ob_get_clean();

$modals = [
    render_modal('subjectModal',    'Add Subject',             render_curriculum_subject_form()),
    render_modal('curriculumModal', 'Add Curriculum Line', render_curriculum_line_form_multi_prereq($programs, $subjects), true),
    render_modal('addProgramModal', 'Add Program', $addProgramForm),
    render_modal('editProgramModal', 'Edit Program', $editProgramForm),
    render_modal('editSubjectModal', 'Edit Subject', render_subject_edit_modal_body()),
    render_modal('importModal', 'Import Curriculum CSV', $importForm),
    render_modal(
        'editCurriculumModal',
        'Edit Curriculum',
        render_edit_curriculum_form($programs, $subjects),
        true
    ),
];

render_page('Curriculum Management', 'Curriculum', (string) ob_get_clean(), ['modals' => $modals]);