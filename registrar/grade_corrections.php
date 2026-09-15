<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$staff = current_staff();

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_correction') {
        $gradeId = (int) ($_POST['grade_id'] ?? 0);
        $result = GradingEngine::approveCorrection($gradeId, (int) $staff['staff_id']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

$pendingGrades = db()->prepare(
    "SELECT g.id AS grade_id, g.grade_value, g.grade_status, g.sched_code,
            g.entered_at, g.submitted_at,
            s.student_number, CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS student_name,
            sub.subject_code, sub.subject_description,
            sec.year_level, sec.section_name, p.program_code,
            gp.period_name,
            req.full_name AS requested_by_name,
            (SELECT gal.reason FROM grade_audit_log gal WHERE gal.grade_id = g.id AND gal.action = 'request_correction' ORDER BY gal.performed_at DESC LIMIT 1) AS correction_reason,
            (SELECT gal.new_value FROM grade_audit_log gal WHERE gal.grade_id = g.id AND gal.action = 'request_correction' ORDER BY gal.performed_at DESC LIMIT 1) AS corrected_value
     FROM grades g
     INNER JOIN student_subjects ss ON ss.id = g.student_subject_id
     INNER JOIN students s ON s.id = ss.student_id
     INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN grading_periods gp ON gp.id = g.grading_period_id
     LEFT JOIN staff req ON req.staff_id = g.updated_by
     WHERE g.grade_status = 'correction_pending'
     ORDER BY g.updated_at DESC"
);
$pendingGrades->execute();
$pending = $pendingGrades->fetchAll(PDO::FETCH_ASSOC);

// Recent corrections
$recentCorrections = db()->prepare(
    "SELECT g.id AS grade_id, g.grade_value, g.grade_status, g.sched_code,
            s.student_number, CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS student_name,
            sub.subject_code, gp.period_name,
            l.full_name AS locked_by_name, g.locked_at,
            gal.old_value AS previous_grade, gal.new_value AS corrected_grade, gal.performed_at AS corrected_at,
            approver.full_name AS approved_by_name
     FROM grade_audit_log gal
     INNER JOIN grades g ON g.id = gal.grade_id
     INNER JOIN student_subjects ss ON ss.id = g.student_subject_id
     INNER JOIN students s ON s.id = ss.student_id
     INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     INNER JOIN grading_periods gp ON gp.id = g.grading_period_id
     LEFT JOIN staff l ON l.staff_id = g.locked_by
     LEFT JOIN staff approver ON approver.staff_id = gal.performed_by
     WHERE gal.action = 'approve_correction'
     ORDER BY gal.performed_at DESC
     LIMIT 20"
);
$recentCorrections->execute();
$recent = $recentCorrections->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Grade Corrections</h1>
        <p>Review and approve grade correction requests from instructors.</p>
    </div>
</div>

<div class="card">
    <h3>Pending Corrections (<?= count($pending) ?>)</h3>
    <?php if (empty($pending)): ?>
        <p style="color:#64748b;">No pending correction requests.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Sched Code</th>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Period</th>
                        <th>Current Grade</th>
                        <th>Requested Change</th>
                        <th>Reason</th>
                        <th>Requested By</th>
                        <th data-dt-no-sort>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <tr>
                        <td><?= h($row['student_number'] . ' - ' . $row['student_name']) ?></td>
                        <td><strong><?= h($row['sched_code']) ?></strong></td>
                        <td><?= h($row['subject_code']) ?></td>
                        <td><?= h($row['program_code'] . ' ' . $row['year_level'] . $row['section_name']) ?></td>
                        <td><?= h($row['period_name']) ?></td>
                        <td><?= h($row['grade_value'] ?? '-') ?></td>
                        <td><strong><?= h($row['corrected_value'] ?? '-') ?></strong></td>
                        <td><?= h($row['correction_reason'] ?? '-') ?></td>
                        <td><?= h($row['requested_by_name'] ?? '-') ?></td>
                        <td>
                            <button class="btn small" onclick="approveCorrection(<?= $row['grade_id'] ?>)">Approve</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($recent)): ?>
<div class="card" style="margin-top:16px;">
    <h3>Recent Corrections</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Sched Code</th>
                    <th>Subject</th>
                    <th>Period</th>
                    <th>Previous Grade</th>
                    <th>Corrected Grade</th>
                    <th>Approved By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= h($row['student_number'] . ' - ' . $row['student_name']) ?></td>
                    <td><?= h($row['sched_code']) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['period_name']) ?></td>
                    <td><?= h($row['previous_grade'] ?? '-') ?></td>
                    <td><strong><?= h($row['corrected_grade'] ?? '-') ?></strong></td>
                    <td><?= h($row['approved_by_name'] ?? '-') ?></td>
                    <td><?= h($row['corrected_at'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function approveCorrection(gradeId) {
    if (!confirm('Approve this grade correction?')) return;
    var formData = new FormData();
    formData.append('action', 'approve_correction');
    formData.append('grade_id', gradeId);

    fetch('<?= h(app_url('registrar/grade_corrections.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            flashMsg('success', data.message);
            setTimeout(() => location.reload(), 600);
        } else {
            flashMsg('error', data.message);
        }
    }).catch(() => flashMsg('error', 'Request failed.'));
}

function flashMsg(type, msg) {
    var existing = document.querySelector('.settings-flash');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'settings-flash settings-flash-' + type;
    el.textContent = msg;
    document.querySelector('.card').insertBefore(el, document.querySelector('.card').firstChild);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3000);
}
</script>
<?php
render_page('Grade Corrections', 'Grade Corrections', (string) ob_get_clean());
