<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$sectionId = (int)($_GET['id'] ?? 0);

$section = fetch_one(
    'SELECT sec.*, p.program_code, p.program_name, d.department_code,
            s.full_name AS adviser_name
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN departments d ON d.dept_id = p.department_id
     LEFT JOIN staff s ON s.staff_id = sec.adviser_id
     WHERE sec.id = :id',
    ['id' => $sectionId]
);

if (!$section) {
    flash('error', 'Section not found.');
    redirect('registrar/departments.php');
}

$academicYears = fetch_all('SELECT id, year_label FROM academic_years WHERE status = "active" ORDER BY start_year DESC');
$semesterOpts = ['1' => 'First Semester', '2' => 'Second Semester', 'mid' => 'Midyear'];

$yearId  = isset($_GET['year_id']) ? (int) $_GET['year_id'] : 0;
$semester = trim((string) ($_GET['semester'] ?? ''));

$students = [];
$totalStudents = 0;
$maxSlots = (int)($section['max_slots'] ?? 0);

$params = ['section_id' => $sectionId];
$sql = "SELECT DISTINCT st.id, st.student_number, st.full_name, st.year_level, st.address,
               p.program_code, sec.section_name,
               ay.year_label, t.semester AS term_semester,
               er.workflow_status
        FROM students st
        INNER JOIN sections sec ON sec.id = st.section_id
        INNER JOIN programs p ON p.programs_id = sec.program_id
        INNER JOIN enrollment_requests er ON er.student_id = st.id
        INNER JOIN academic_terms t ON t.id = er.term_id
        INNER JOIN academic_years ay ON ay.id = t.academic_year_id
        WHERE st.section_id = :section_id";

if ($yearId > 0) {
    $sql .= " AND ay.id = :year_id";
    $params['year_id'] = $yearId;
}

if ($semester !== '') {
    $sql .= " AND t.semester = :semester";
    $params['semester'] = $semester;
}

$sql .= " ORDER BY st.full_name";

$students = fetch_all($sql, $params);
$totalStudents = count($students);

ob_start();
?>

<div style="margin-bottom:16px;">
    <a href="<?= h(app_url('registrar/departments.php')) ?>"
       style="font-size:13px; color:var(--muted); text-decoration:none;">
        ← Back to Departments &amp; Sections
    </a>
</div>

<div class="page-header">
    <div>
        <h1><?= h($section['program_code'] . ' ' . $section['year_level'] . '-' . $section['section_name']) ?></h1>
        <p><?= h($section['program_name']) ?> • <?= h($section['department_code']) ?></p>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <p style="margin-right:16px;"><strong>Adviser:</strong> <?= $section['adviser_name'] ? h($section['adviser_name']) : 'None' ?></p>
        <p style="margin-right:16px;"><strong>Slots:</strong> <?= $totalStudents ?> / <?= $maxSlots ?: '∞' ?></p>
        <p><strong>Status:</strong> <?= h($section['status'] ?? 'active') ?></p>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get" class="form-inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="id" value="<?= (int) $sectionId ?>">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569;">School Year</label>
            <select name="year_id" style="min-width:160px;">
                <option value="">All Years</option>
                <?php foreach ($academicYears as $y): ?>
                <option value="<?= (int) $y['id'] ?>" <?= $yearId === (int) $y['id'] ? 'selected' : '' ?>>
                    <?= h($y['year_label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#475569;">Semester</label>
            <select name="semester" style="min-width:160px;">
                <option value="">All Semesters</option>
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
                Filter
            </button>
            <a href="section_detail.php?id=<?= (int) $sectionId ?>" class="btn secondary">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">clear</span>
                Clear
            </a>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;">Enrolled Students</h3>
        <span class="helper"><?= $totalStudents ?> total</span>
    </div>

    <?php if (empty($students)): ?>
        <p class="helper" style="text-align:center;padding:24px 0;">No students found for the selected filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student No</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Year / Section</th>
                        <th>Term</th>
                        <th>Enrollment Status</th>
                        <th>Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $st): ?>
                        <tr>
                            <td><?= h($st['student_number']) ?></td>
                            <td><strong><?= h($st['full_name']) ?></strong></td>
                            <td><?= h($st['program_code']) ?></td>
                            <td><?= h($st['year_level'] . '-' . $st['section_name']) ?></td>
                            <td><?= h($st['year_label'] . ' / ' . semester_label($st['term_semester'])) ?></td>
                            <td>
                                <span class="badge <?= h(($st['workflow_status'] ?? '') === 'registrar_approved' ? 'success' : '') ?>">
                                    <?= h(str_replace('_', ' ', $st['workflow_status'] ?? 'N/A')) ?>
                                </span>
                            </td>
                            <td><?= h($st['address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
render_page('Section Detail', 'Departments & Sections', ob_get_clean());
