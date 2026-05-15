<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/document_renderer.php';

$user = require_login();
if (!in_array($user['role'], ['student', 'registrar', 'admin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$studentId = (int) ($_GET['student_id'] ?? ($user['role'] === 'student' ? ($user['student_id'] ?? 0) : 0));
if ($studentId <= 0) {
    exit('Student not found.');
}

if (isset($_GET['print'])) {
    render_checklist_document($studentId);
}

$data = checklist_data($studentId);
$student = $data['student'];

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Checklist</h1>
        <p><?= h(($student['full_name'] ?? '') . ' - ' . ($student['program_code'] ?? '')) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('checklist.php?student_id=' . $studentId . '&print=1')) ?>">Print / Save Checklist</a>
    </div>
</div>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Year</th><th>Semester</th><th>Code</th><th>Description</th><th>Units</th><th>Grade</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <tr>
                    <td><?= h($row['year_level']) ?></td>
                    <td><?= h($row['semester']) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td><?= h($row['units']) ?></td>
                    <td><?= h($row['grade'] ?: '-') ?></td>
                    <td><span class="badge <?= $row['status'] === 'Completed' ? 'success' : ($row['status'] === 'Pending' ? 'info' : 'danger') ?>"><?= h($row['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Checklist', 'Checklist', (string) ob_get_clean());
