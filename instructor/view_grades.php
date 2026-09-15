<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['instructor', 'adviser']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$gradingPeriods = GradingEngine::getGradingPeriods();

// Get offerings for this instructor
$offerings = db()->prepare(
    "SELECT o.id, o.sched_code, sub.subject_code, sub.subject_description,
            p.program_code, sec.year_level, sec.section_name,
            (SELECT COUNT(*) FROM student_subjects ss WHERE ss.offering_id = o.id AND ss.enrollment_status = 'enrolled') AS enrolled_count
     FROM section_subject_offerings o
     LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     WHERE (o.instructor_id = :iid1 OR cs.instructor_id = :iid2) AND o.status = 'active'
     GROUP BY o.id
     ORDER BY sub.subject_code"
);
$offerings->execute(['iid1' => (int) $staff['staff_id'], 'iid2' => (int) $staff['staff_id']]);
$offerings = $offerings->fetchAll(PDO::FETCH_ASSOC);

$selectedOfferingId = (int) ($_GET['offering_id'] ?? 0);
$students = [];
$offeringInfo = null;

if ($selectedOfferingId > 0) {
    $offeringInfo = db()->prepare(
        "SELECT o.*, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name,
                COALESCE(st.full_name, 'TBA') AS instructor_name
         FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
         LEFT JOIN staff st ON st.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
         WHERE o.id = :id"
    );
    $offeringInfo->execute(['id' => $selectedOfferingId]);
    $offeringInfo = $offeringInfo->fetch(PDO::FETCH_ASSOC);

    if ($offeringInfo) {
        $midPeriod = GradingEngine::getGradingPeriod('midterm');
        $finPeriod = GradingEngine::getGradingPeriod('final');

        $students = db()->prepare(
            "SELECT ss.id AS student_subject_id, s.student_number,
                    CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
                    s.year_level AS student_year,
                    ss.midterm_grade, ss.final_grade, ss.units, ss.enrollment_status,
                    gm.grade_value AS midterm_grade_new, gm.grade_status AS midterm_status, gm.submitted_at AS midterm_submitted,
                    gf.grade_value AS final_grade_new, gf.grade_status AS final_status, gf.submitted_at AS final_submitted
             FROM student_subjects ss
             INNER JOIN students s ON s.id = ss.student_id
             LEFT JOIN grades gm ON gm.student_subject_id = ss.id AND gm.grading_period_id = :mid_pid
             LEFT JOIN grades gf ON gf.student_subject_id = ss.id AND gf.grading_period_id = :fin_pid
             WHERE ss.offering_id = :offering_id AND ss.enrollment_status = 'enrolled'
             ORDER BY s.student_number"
        );
        $students->execute([
            'offering_id' => $selectedOfferingId,
            'mid_pid' => $midPeriod['id'] ?? 0,
            'fin_pid' => $finPeriod['id'] ?? 0,
        ]);
        $students = $students->fetchAll(PDO::FETCH_ASSOC);
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>View Grades</h1>
        <p>View enrolled students and their grades for each subject offering.</p>
    </div>
</div>

<div class="card">
    <form method="get" class="filter-bar">
        <div>
            <label>Select Offering</label>
            <select name="offering_id" onchange="this.form.submit()">
                <option value="0">-- Select a subject offering --</option>
                <?php foreach ($offerings as $off): ?>
                    <option value="<?= $off['id'] ?>" <?= $selectedOfferingId === (int) $off['id'] ? 'selected' : '' ?>>
                        <?= h($off['sched_code'] . ' | ' . $off['subject_code'] . ' [' . $off['program_code'] . ' ' . $off['year_level'] . $off['section_name'] . '] (' . $off['enrolled_count'] . ' students)') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($offeringInfo !== null): ?>
<div class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <h3 style="margin:0;"><?= h($offeringInfo['sched_code'] ?? '---') ?> — <?= h($offeringInfo['subject_code'] . ' - ' . $offeringInfo['subject_description']) ?></h3>
            <p style="margin:4px 0 0;color:#64748b;"><?= h($offeringInfo['program_code'] . ' ' . $offeringInfo['year_level'] . $offeringInfo['section_name']) ?> | Instructor: <?= h($offeringInfo['instructor_name']) ?> | Enrolled: <?= count($students) ?></p>
        </div>
        <div class="actions-row">
            <a class="btn secondary" href="<?= h(app_url('instructor/students.php?offering_id=' . $selectedOfferingId)) ?>">Grade Input</a>
        </div>
    </div>

    <?php if (empty($students)): ?>
        <p style="color:#64748b;text-align:center;">No enrolled students found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Number</th>
                        <th>Name</th>
                        <th>Units</th>
                        <th>Midterm</th>
                        <th>Midterm Status</th>
                        <th>Final</th>
                        <th>Final Status</th>
                        <th>Overall</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s):
                    $midGrade = $s['midterm_grade_new'] ?? $s['midterm_grade'] ?? null;
                    $finGrade = $s['final_grade_new'] ?? $s['final_grade'] ?? null;
                    $midStatus = $s['midterm_status'] ?? 'draft';
                    $finStatus = $s['final_status'] ?? 'draft';

                    // Overall status
                    if (GradingEngine::isPassing($finGrade)) {
                        $overall = 'Passed';
                        $overallClass = 'success';
                    } elseif ($finGrade !== null && $finGrade !== '') {
                        $overall = 'Failed';
                        $overallClass = 'danger';
                    } elseif ($midGrade !== null && $midGrade !== '') {
                        $overall = 'In Progress';
                        $overallClass = 'info';
                    } else {
                        $overall = 'No Grades';
                        $overallClass = 'secondary';
                    }
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($s['student_number']) ?></td>
                        <td><?= h($s['full_name']) ?></td>
                        <td><?= h($s['units']) ?></td>
                        <td>
                            <?php if ($midGrade): ?>
                                <strong><?= h($midGrade) ?></strong>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($midStatus === 'locked'): ?>
                                <span class="badge success">Locked</span>
                            <?php elseif ($midStatus === 'submitted'): ?>
                                <span class="badge info">Submitted</span>
                            <?php elseif ($midGrade): ?>
                                <span class="badge secondary">Draft</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($finGrade): ?>
                                <strong><?= h($finGrade) ?></strong>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($finStatus === 'locked'): ?>
                                <span class="badge success">Locked</span>
                            <?php elseif ($finStatus === 'submitted'): ?>
                                <span class="badge info">Submitted</span>
                            <?php elseif ($finGrade): ?>
                                <span class="badge secondary">Draft</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $overallClass ?>"><?= $overall ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" style="text-align:right;">Totals</th>
                        <th><?= count($students) ?> Students</th>
                        <th colspan="2">
                            Graded (Midterm): <?= count(array_filter($students, fn($s) => ($s['midterm_grade_new'] ?? $s['midterm_grade'] ?? null) !== null)) ?> / <?= count($students) ?>
                        </th>
                        <th colspan="3">
                            Graded (Final): <?= count(array_filter($students, fn($s) => ($s['final_grade_new'] ?? $s['final_grade'] ?? null) !== null)) ?> / <?= count($students) ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php elseif ($selectedOfferingId > 0): ?>
    <div class="card" style="margin-top:16px;text-align:center;color:#64748b;">
        Offering not found or you do not have access.
    </div>
<?php endif; ?>

<?php if (empty($offerings)): ?>
    <div class="card" style="margin-top:16px;text-align:center;color:#64748b;">
        No subject offerings assigned to you.
    </div>
<?php endif; ?>

<?php
render_page('View Grades', 'View Grades', (string) ob_get_clean());
