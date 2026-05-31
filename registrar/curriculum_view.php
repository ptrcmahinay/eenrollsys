<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'dept_chair']);

$filters = [
    'program_id' => (int) ($_GET['program_id'] ?? 0),
    'year_level' => trim($_GET['year_level'] ?? ''),
    'semester' => trim($_GET['semester'] ?? ''),
];

$programs = fetch_all('SELECT programs_id, program_code FROM programs ORDER BY program_code');
$sql = 'SELECT pc.curriculum_id, pc.curriculum_label, pc.year_level, pc.semester,
               p.program_code, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
               pre.subject_code AS prerequisite_code
        FROM program_curriculum pc
        INNER JOIN programs p ON p.programs_id = pc.program_id
        INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
        LEFT JOIN subjects pre ON pre.subject_id = pc.prerequisite_subject_id
        WHERE 1=1';
$params = [];
if ($filters['program_id'] > 0) {
    $sql .= ' AND pc.program_id = :program_id';
    $params['program_id'] = $filters['program_id'];
}
if ($filters['year_level'] !== '') {
    $sql .= ' AND pc.year_level = :year_level';
    $params['year_level'] = $filters['year_level'];
}
if ($filters['semester'] !== '') {
    $sql .= ' AND pc.semester = :semester';
    $params['semester'] = $filters['semester'];
}
$sql .= ' ORDER BY p.program_code, CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code';
$rows = fetch_all($sql, $params);

if (current_user()['role'] === 'student') {
    $student = current_student();
    if ($student !== null && $filters['program_id'] === 0) {
        $rows = array_values(array_filter($rows, static fn($row) => (int) $row['program_id'] === (int) $student['program_id']));
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Curriculum View</h1>
        <p>Read-only curriculum list by course, year level, semester, and prerequisite.</p>
    </div>
</div>
<div class="card">
    <form method="get" class="filter-bar">
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
                <?php foreach (['1', '2', '3', '4'] as $year): ?>
                    <option value="<?= h($year) ?>" <?= $filters['year_level'] === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Semester</label>
            <select name="semester">
                <option value="">All</option>
                <option value="1st" <?= $filters['semester'] === '1st' ? 'selected' : '' ?>>1st</option>
                <option value="2nd" <?= $filters['semester'] === '2nd' ? 'selected' : '' ?>>2nd</option>
                <option value="mid" <?= $filters['semester'] === 'mid' ? 'selected' : '' ?>>Midyear</option>
            </select>
        </div>
        <div><button class="btn secondary" type="submit">Filter</button></div>
    </form>
</div>
<div class="card" style="margin-top:16px;">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Program</th><th>Curriculum</th><th>Year</th><th>Semester</th><th>Code</th><th>Description</th><th>Prerequisite</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['program_code']) ?></td>
                    <td><?= h($row['curriculum_label']) ?></td>
                    <td><?= h($row['year_level']) ?></td>
                    <td><?= h($row['semester']) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td><?= h($row['prerequisite_code'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Curriculum View', current_user()['role'] === 'student' ? 'Checklist' : 'Curriculum View', (string) ob_get_clean());
