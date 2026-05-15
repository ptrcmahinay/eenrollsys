<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/forms.php';
require_role(['admin', 'registrar']);

if (is_post() && ($_POST['action'] ?? '') === 'delete_student') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    if ($studentId > 0) {
        if (soft_delete('students', 'id', $studentId)) {
            flash('success', 'Student marked inactive.');
        } else {
            flash('error', 'Unable to deactivate student.');
        }
    }
    redirect('registrar/students.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_delete_students') {
    $ids = $_POST['student_id'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        execute_sql("UPDATE students SET status = 'inactive' WHERE id IN ({$ph})", $ids);
        flash('success', count($ids) . ' student(s) deleted.');
    }
    redirect('registrar/students.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'create_student') {
    $studentNumber = trim($_POST['student_number'] ?? generate_student_number());
    $fullName = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $programId = (int) ($_POST['program_id'] ?? 0);
    $yearLevel = (int) ($_POST['year_level'] ?? 1);
    $sectionId = ($_POST['section_id'] ?? '') !== '' ? (int) $_POST['section_id'] : null;
    $entryYear = (int) ($_POST['entry_year'] ?? date('Y'));
    $raOverride = trim($_POST['ra10931_override'] ?? 'auto');

    if ($fullName === '' || $address === '' || $programId <= 0) {
        flash('error', 'Please fill out the required face-to-face intake fields.');
        redirect('registrar/students.php');
    }

    execute_sql(
        'INSERT INTO students (student_number, full_name, address, program_id, year_level, section_id, entry_year, ra10931_override, status, created_at)
         VALUES (:student_number, :full_name, :address, :program_id, :year_level, :section_id, :entry_year, :ra10931_override, "active", NOW())',
        [
            'student_number' => $studentNumber,
            'full_name' => $fullName,
            'address' => $address,
            'program_id' => $programId,
            'year_level' => $yearLevel,
            'section_id' => $sectionId,
            'entry_year' => $entryYear,
            'ra10931_override' => $raOverride,
        ]
    );
    flash('success', 'Student intake completed. Student number generated: ' . $studentNumber);
    redirect('registrar/students.php');
}

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');
$sections = fetch_all(
    'SELECT sec.id, sec.program_id, sec.year_level, sec.section_name, p.program_code
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     ORDER BY p.program_code, sec.year_level, sec.section_name'
);

$filters = [
    'program_id' => (int) ($_GET['program_id'] ?? 0),
    'year_level' => trim($_GET['year_level'] ?? ''),
    'section_id' => (int) ($_GET['section_id'] ?? 0),
    'ra10931' => trim($_GET['ra10931'] ?? ''),
    'query' => trim($_GET['query'] ?? ''),
];

$sql = 'SELECT s.*, p.program_code, p.program_name, sec.section_name
        FROM students s
        INNER JOIN programs p ON p.programs_id = s.program_id
        LEFT JOIN sections sec ON sec.id = s.section_id
        WHERE 1=1';
$params = [];
if ($filters['program_id'] > 0) {
    $sql .= ' AND s.program_id = :program_id';
    $params['program_id'] = $filters['program_id'];
}
if ($filters['year_level'] !== '') {
    $sql .= ' AND s.year_level = :year_level';
    $params['year_level'] = $filters['year_level'];
}
if ($filters['section_id'] > 0) {
    $sql .= ' AND s.section_id = :section_id';
    $params['section_id'] = $filters['section_id'];
}
if ($filters['query'] !== '') {
    $sql .= ' AND (s.student_number LIKE :query OR s.full_name LIKE :query OR s.address LIKE :query)';
    $params['query'] = '%' . $filters['query'] . '%';
}
$sql .= ' ORDER BY s.student_number';
$rows = fetch_all($sql, $params);

ob_start();
$generatedNumber = generate_student_number();

$addStudentModal = '
<form method="post">
    <input type="hidden" name="action" value="create_student">

    <div class="form-grid cols-3">

        <div>
            <label>Student Number</label>
            <input type="text" name="student_number" value="' . h(generate_student_number()) . '">
        </div>

        <div>
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>

        <div>
            <label>Address</label>
            <input type="text" name="address" required>
        </div>

        <div>
            <label>Program</label>
            <select name="program_id" required>
                ' . implode('', array_map(fn($p) =>
                    '<option value="'.h($p['programs_id']).'">'.h($p['program_code']).'</option>',
                    $programs
                )) . '
            </select>
        </div>

        <div>
            <label>Year Level</label>
            <select name="year_level">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
            </select>
        </div>

        <div>
            <label>Section</label>
            <select name="section_id">
                <option value="">None</option>
                ' . implode('', array_map(fn($s) =>
                    '<option value="'.h($s['id']).'">'.h($s['program_code'].' '.$s['year_level'].'-'.$s['section_name']).'</option>',
                    $sections
                )) . '
            </select>
        </div>

        <div>
            <label>Entry Year</label>
            <input type="number" name="entry_year" value="' . date('Y') . '">
        </div>

        <div>
            <label>RA 10931 Override</label>
            <select name="ra10931_override">
                <option value="auto">Auto</option>
                <option value="free">Free</option>
                <option value="extension_tuition">Extension Tuition</option>
                <option value="tuition">Tuition</option>
            </select>
        </div>

    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Save Student</button>
    </div>
</form>
';
?>
<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1>Students</h1>
        <p>Manage student records and enrollment data.</p>
    </div>

    <button class="btn" data-open="modal-add-student">
        + Add Student
    </button>
</div>
<!-- 
<div class="grid">
    <div class="card">
        <h3>Filters</h3>
        <form method="get">
            <div class="filter-bar">
                <div>
                    <label>Program</label>
                    <select name="program_id">
                        <option value="">All</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= h($program['programs_id']) ?>" <?= $filters['program_id'] === (int) $program['programs_id'] ? 'selected' : '' ?>><?= h($program['program_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Year Level</label>
                    <select name="year_level">
                        <option value="">All</option>
                        <?php foreach (['1','2','3','4'] as $year): ?>
                            <option value="<?= h($year) ?>" <?= $filters['year_level'] === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Section</label>
                    <select name="section_id">
                        <option value="">All</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= h($section['id']) ?>" <?= $filters['section_id'] === (int) $section['id'] ? 'selected' : '' ?>><?= h($section['program_code'] . ' ' . $section['year_level'] . $section['section_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>RA 10931 / Tuition</label>
                    <select name="ra10931">
                        <option value="">All</option>
                        <option value="free" <?= $filters['ra10931'] === 'free' ? 'selected' : '' ?>>Free Education</option>
                        <option value="extension_tuition" <?= $filters['ra10931'] === 'extension_tuition' ? 'selected' : '' ?>>Extension Tuition</option>
                        <option value="tuition" <?= $filters['ra10931'] === 'tuition' ? 'selected' : '' ?>>Tuition Paying</option>
                    </select>
                </div>
                <div>
                    <label>Search</label>
                    <input type="text" name="query" value="<?= h($filters['query']) ?>" placeholder="Student number or name">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn secondary" type="submit">Apply Filters</button>
            </div>
        </form>
        <p class="helper">Use the detail page to edit grades, generate registration forms, and issue the COG.</p>
    </div>
</div> -->

<div class="card" style="margin-top: 16px;">
    <h3>Student list</h3>
    <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('registrar/students.php')) ?>" data-dt-bulk-id-field="student_id" data-dt-bulk-action="bulk_delete_students" data-dt-bulk-confirm="Delete selected students?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                        <th data-dt-key="student_number">Student Number</th>
                        <th data-dt-key="name">Name</th>
                        <th data-dt-key="program" data-dt-filter="select">Program</th>
                        <th data-dt-key="year_section">Year / Section</th>
                        <th data-dt-key="address">Address</th>
                        <th data-dt-key="ra10931" data-dt-filter="select">RA 10931 / Tuition</th>
                        <th data-dt-no-sort>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $financial = financial_profile($row, $term ?? null); ?>
                    <?php if ($filters['ra10931'] !== '' && $financial['status'] !== $filters['ra10931']) { continue; } ?>
                    <tr data-dt-row-id="<?= h((string)$row['id']) ?>">
                        <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$row['id']) ?>" aria-label="Select row"></td>
                        <td><?= h($row['student_number']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['year_level'] . ($row['section_name'] ?: '')) ?></td>
                        <td><?= h($row['address']) ?></td>
                        <td data-dt-value="<?= h($financial['label']) ?>">
                            <span class="badge <?= in_array($financial['status'], ['free'], true) ? 'success' : 'warning' ?>">
                                <?= h($financial['label']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" title="View" aria-label="View"
                                   href="<?= h(app_url('registrar/student_detail.php?student_id=' . $row['id'])) ?>">
                                    <span class="material-symbols-outlined">visibility</span>
                                </a>
                                <a class="icon-btn" title="Edit" aria-label="Edit"
                                   href="<?= h(app_url('registrar/student_detail.php?student_id=' . $row['id'])) ?>">
                                    <span class="material-symbols-outlined">edit</span>
                                </a>
                                <form class="inline-form" method="post"
                                      action="<?= h(app_url('registrar/students.php')) ?>"
                                      onsubmit="return confirm('Mark this student as inactive?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?= h($row['id']) ?>">
                                    <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    document.addEventListener('click', function(e) {
    if (e.target.matches('[data-open="modal-add-student"]')) {
        setTimeout(() => {
            document.querySelector('#modal-add-student input[name="full_name"]')?.focus();
        }, 100);
    }
});

</script>
<?= render_modal('modal-add-student', 'Add Student (Intake)', $addStudentModal) ?>
<?php
render_page('Registrar Students', 'Students', (string) ob_get_clean());
