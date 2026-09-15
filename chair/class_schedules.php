<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
$user = require_role(['chair', 'admin', 'registrar']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('index.php');
}

$deptId = (int) $staff['dept_id'];

$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester, t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$programs = fetch_all(
    'SELECT programs_id, program_code, program_name
     FROM programs WHERE department_id = :dept_id ORDER BY program_code',
    ['dept_id' => $deptId]
);

$filterTerm    = (int) ($_GET['term_id'] ?? 0);
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterYear    = (int) ($_GET['year_level'] ?? 0);

if ($filterTerm === 0) {
    foreach ($terms as $t) {
        if ((int) $t['is_active'] === 1) { $filterTerm = (int) $t['id']; break; }
    }
}

$termLabel = '';
foreach ($terms as $t) {
    if ((int) $t['id'] === $filterTerm) {
        $termLabel = $t['year_label'] . ' · ' . semester_label((string) $t['semester']);
        break;
    }
}

$semesterMap = ['1' => '1st', '2' => '2nd', 'mid' => 'mid'];
$filterSemester = '';
foreach ($terms as $t) {
    if ((int) $t['id'] === $filterTerm) {
        $filterSemester = $semesterMap[(string) $t['semester']] ?? (string) $t['semester'];
        break;
    }
}

$sectionSql = 'SELECT sec.id, sec.program_id, sec.year_level, sec.section_name,
                      p.program_code, p.program_name,
                      (SELECT COUNT(*) FROM section_subject_offerings o
                       INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
                       WHERE o.section_id = sec.id AND o.term_id = :term_id AND pc.semester = :semester) AS total_subjects
               FROM sections sec
               INNER JOIN programs p ON p.programs_id = sec.program_id
               WHERE p.department_id = :dept_id AND COALESCE(sec.status, "active") = "active"';
$sectionParams = ['term_id' => $filterTerm, 'semester' => $filterSemester, 'dept_id' => $deptId];

if ($filterProgram > 0) {
    $sectionSql .= ' AND p.programs_id = :program_id';
    $sectionParams['program_id'] = $filterProgram;
}
if ($filterYear > 0) {
    $sectionSql .= ' AND sec.year_level = :year_level';
    $sectionParams['year_level'] = $filterYear;
}

$sectionSql .= ' ORDER BY p.program_code, sec.year_level, sec.section_name';
$allSections = fetch_all($sectionSql, $sectionParams);

$sectionIds = array_column($allSections, 'id');
$scheduledCounts = [];
$approvedCounts = [];
if ($sectionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $db = db();

    $stmt = $db->prepare(
        "SELECT section_id, COUNT(*) AS cnt
         FROM class_schedules
         WHERE section_id IN ($placeholders) AND term_id = ? AND status IN ('draft','submitted','approved')
         GROUP BY section_id"
    );
    $params = array_merge($sectionIds, [$filterTerm]);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $scheduledCounts[(int) $row['section_id']] = (int) $row['cnt'];
    }

    $stmt2 = $db->prepare(
        "SELECT section_id, COUNT(*) AS cnt
         FROM class_schedules
         WHERE section_id IN ($placeholders) AND term_id = ? AND status = 'approved'
         GROUP BY section_id"
    );
    $stmt2->execute($params);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $approvedCounts[(int) $row['section_id']] = (int) $row['cnt'];
    }
}

$enrolledCounts = [];
if ($sectionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $db = db();
    $stmt = $db->prepare(
        "SELECT er.requested_section_id AS section_id, COUNT(DISTINCT er.student_id) AS cnt
         FROM enrollment_requests er
         WHERE er.requested_section_id IN ($placeholders) AND er.term_id = ? AND er.workflow_status = 'registrar_approved'
         GROUP BY er.requested_section_id"
    );
    $params = array_merge($sectionIds, [$filterTerm]);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $enrolledCounts[(int) $row['section_id']] = (int) $row['cnt'];
    }
}

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Class Schedules</h1>
        <p>Select a section to manage its class schedules.</p>
    </div>
</div>

<?php $flashes = get_flashes(); if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;">Academic Term</label>
            <select name="term_id" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= h((string) $t['id']) ?>" <?= (int) $t['id'] === $filterTerm ? 'selected' : '' ?>>
                        <?= h($t['year_label'] . ' · ' . semester_label((string) $t['semester'])) ?><?= (int) $t['is_active'] === 1 ? ' (Active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;">Program</label>
            <select name="program_id" onchange="this.form.submit()">
                <option value="0">All Programs</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= h((string) $p['programs_id']) ?>" <?= (int) $p['programs_id'] === $filterProgram ? 'selected' : '' ?>>
                        <?= h($p['program_code'] . ' — ' . $p['program_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;">Year Level</label>
            <select name="year_level" onchange="this.form.submit()">
                <option value="0">All Years</option>
                <?php foreach ([1,2,3,4] as $y): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filterTerm > 0 || $filterProgram > 0 || $filterYear > 0): ?>
            <a class="btn secondary" href="?">Reset</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($allSections === []): ?>
    <div class="card" style="text-align:center;padding:40px 16px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);">groups</span>
        <p style="margin-top:8px;color:var(--muted);">No sections found for the selected filters.</p>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th style="text-align:center;">Subjects</th>
                    <th style="text-align:center;">Scheduled</th>
                    <th style="text-align:center;">Approved</th>
                    <th style="text-align:center;">Enrolled</th>
                    <th style="text-align:center;">Progress</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allSections as $sec): ?>
                <?php
                    $sid = (int) $sec['id'];
                    $total = (int) $sec['total_subjects'];
                    $scheduled = $scheduledCounts[$sid] ?? 0;
                    $approved = $approvedCounts[$sid] ?? 0;
                    $enrolled = $enrolledCounts[$sid] ?? 0;
                    $unscheduled = $total - $scheduled;
                    $pct = $total > 0 ? round(($scheduled / $total) * 100) : 0;
                    $statusClass = $unscheduled === 0 && $total > 0 ? 'success' : ($scheduled > 0 ? 'warning' : 'info');
                    $statusLabel = $unscheduled === 0 && $total > 0 ? 'Complete' : ($scheduled > 0 ? 'In Progress' : 'No Schedules');
                ?>
                <tr style="cursor:pointer;" onclick="window.location='<?= h(app_url('chair/section_schedule.php?section_id=' . $sid . '&term_id=' . $filterTerm)) ?>'" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td><strong><?= h($sec['program_code'] . ' ' . $sec['year_level'] . $sec['section_name']) ?></strong></td>
                    <td><?= h($sec['program_name']) ?></td>
                    <td><?= h((string) $sec['year_level']) ?></td>
                    <td style="text-align:center;"><?= $total ?></td>
                    <td style="text-align:center;"><?= $scheduled ?></td>
                    <td style="text-align:center;color:<?= $approved > 0 ? 'var(--success)' : 'inherit' ?>;font-weight:<?= $approved > 0 ? '600' : '400' ?>;"><?= $approved ?></td>
                    <td style="text-align:center;"><?= $enrolled ?></td>
                    <td style="text-align:center;min-width:120px;">
                        <?php if ($total > 0): ?>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                                <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct === 100 ? 'var(--success)' : 'var(--info,#3b82f6)' ?>;border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--muted);white-space:nowrap;"><?= $pct ?>%</span>
                        </div>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
render_page('Class Schedules', 'Class Schedules', (string) ob_get_clean());
