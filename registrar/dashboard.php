<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('registrar');

$term = current_term();
$counts = [
    'Students' => fetch_one('SELECT COUNT(*) AS total FROM students')['total'] ?? 0,
    'Under RA 10931' => fetch_one('SELECT COUNT(*) AS total FROM students WHERE COALESCE(ra10931_override, "auto") IN ("auto", "free") AND entry_year >= :min_year', ['min_year' => (int) date('Y') - 3])['total'] ?? 0,
    'Pending Approval' => fetch_one('SELECT COUNT(*) AS total FROM enrollment_requests WHERE workflow_status = "chair_approved"')['total'] ?? 0,
    'Enrolled This Term' => fetch_one('SELECT COUNT(DISTINCT student_id) AS total FROM student_subjects WHERE term_id = :term_id', ['term_id' => (int) ($term['id'] ?? 0)])['total'] ?? 0,
];

$queue = fetch_all(
    'SELECT er.id, er.workflow_status, er.total_units, er.total_amount, er.ra10931_status,
            s.student_number, s.full_name, p.program_code, sec.section_name
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = er.requested_section_id
     WHERE er.workflow_status IN ("chair_approved", "registrar_approved", "rejected")
     ORDER BY er.updated_at DESC
     LIMIT 10'
);

$studentsBySection = fetch_all(
    'SELECT p.program_code, s.year_level, sec.section_name, COUNT(DISTINCT s.id) AS student_count
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     GROUP BY p.program_code, s.year_level, sec.section_name
     ORDER BY p.program_code, s.year_level, sec.section_name'
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Registrar Dashboard</h1>
        <p>Face-to-face student intake, student number generation, section slot monitoring, RA 10931 tagging, enrollment approval, registration forms, COG, and grade editing.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('registrar/students.php')) ?>">Manage Students</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/enrollment.php')) ?>">Enrollment Queue</a>
    </div>
</div>

<div class="grid cols-4">
    <?php foreach ($counts as $label => $value): ?>
        <div class="card slim">
            <div class="metric-label"><?= h($label) ?></div>
            <div class="metric"><?= h((string) $value) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid cols-2" style="margin-top: 16px;">
    <div class="card">
        <h3>Approval queue snapshot</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Program</th><th>Section</th><th>Status</th><th>Units</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach ($queue as $row): ?>
                    <tr>
                        <td><?= h($row['student_number'] . ' - ' . $row['full_name']) ?></td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['section_name'] ?: '-') ?></td>
                        <td><span class="badge <?= h(workflow_badge_class((string) $row['workflow_status'])) ?>"><?= h(request_workflow_label((string) $row['workflow_status'])) ?></span></td>
                        <td><?= h($row['total_units']) ?></td>
                        <td>₱<?= h(format_money($row['total_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>Student counts by program / section</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Program</th><th>Year</th><th>Section</th><th>Total Students</th></tr></thead>
                <tbody>
                <?php foreach ($studentsBySection as $row): ?>
                    <tr>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['year_level']) ?></td>
                        <td><?= h($row['section_name'] ?: '-') ?></td>
                        <td><?= h($row['student_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
render_page('Registrar Dashboard', 'Dashboard', (string) ob_get_clean());
