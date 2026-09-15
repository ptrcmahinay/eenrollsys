<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$studentId = (int) $student['id'];
$terms = student_terms_with_enrollment($studentId);
$selectedTermId = (int) ($_GET['term_id'] ?? 0);

$gradingPeriods = GradingEngine::getGradingPeriods();
$midtermPeriodId = null;
$finalPeriodId = null;
foreach ($gradingPeriods as $gp) {
    if ($gp['period_code'] === 'midterm') $midtermPeriodId = (int) $gp['id'];
    if ($gp['period_code'] === 'final') $finalPeriodId = (int) $gp['id'];
}

// Fetch grades with midterm and final
$params = ['student_id' => $studentId];
$filter = '';
if ($selectedTermId > 0) {
    $filter = ' AND ss.term_id = :term_id';
    $params['term_id'] = $selectedTermId;
}

$rows = db()->prepare(
    "SELECT ss.units, ss.enrollment_status, ss.midterm_grade, ss.final_grade,
            sub.subject_code, sub.subject_description,
            ay.year_label, ay.start_year, t.id AS term_id, t.semester,
            gm.grade_value AS midterm_grade_new, gm.grade_status AS midterm_status,
            gf.grade_value AS final_grade_new, gf.grade_status AS final_status
     FROM student_subjects ss
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     LEFT JOIN grades gm ON gm.student_subject_id = ss.id AND gm.grading_period_id = :mid_pid
     LEFT JOIN grades gf ON gf.student_subject_id = ss.id AND gf.grading_period_id = :fin_pid
     WHERE ss.student_id = :student_id AND ss.enrollment_status != 'dropped'" . $filter . '
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code'
);
$params['mid_pid'] = $midtermPeriodId ?? 0;
$params['fin_pid'] = $finalPeriodId ?? 0;
$rows->execute($params);
$allRows = $rows->fetchAll(PDO::FETCH_ASSOC);

// Group by term
$grouped = [];
foreach ($allRows as $r) {
    $key = $r['term_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'year_label' => $r['year_label'],
            'semester'   => $r['semester'],
            'term_id'    => $r['term_id'],
            'rows'       => [],
        ];
    }
    $grouped[$key]['rows'][] = $r;
}

// Compute cumulative GWA
$cumStats = GradingEngine::computeCumulativeGwa($studentId);

// Academic honors
$honorResults = [];
if ($selectedTermId > 0) {
    $honorResults = GradingEngine::evaluateHonors($studentId, $selectedTermId);
} else {
    // Use latest term for honors evaluation
    $latestTerm = !empty($terms) ? $terms[0]['id'] : 0;
    if ($latestTerm > 0) {
        $honorResults = GradingEngine::evaluateHonors($studentId, $latestTerm);
    }
}
$officialHonors = GradingEngine::getOfficialHonors($studentId);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Grades and Certificate of Grades</h1>
        <p>View grade history for all semesters and download the COG per term.</p>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>Available Terms</h3>
        <div class="actions-row">
            <a class="btn secondary small" href="<?= h(app_url('student/grades.php')) ?>">All Terms</a>
            <?php foreach ($terms as $term): ?>
                <a class="btn secondary small" href="<?= h(app_url('student/grades.php?term_id=' . $term['id'])) ?>"><?= h($term['year_label'] . ' ' . semester_label((string) $term['semester'])) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <h3>Downloads</h3>
        <div class="actions-row">
            <a class="btn" href="<?= h(app_url('student/cog.php')) ?>">Request COG</a>
            <a class="btn secondary" href="<?= h(app_url('student/registration_form.php')) ?>">Registration Form</a>
            <a class="btn secondary" href="<?= h(app_url('checklist.php')) ?>">Checklist</a>
        </div>
    </div>
</div>

<!-- Cumulative summary -->
<div class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <h3 style="margin:0;">Academic Summary</h3>
        <div class="actions-row">
            <span class="badge info">Units Taken: <strong><?= h(number_format($cumStats['units_taken'], 1)) ?></strong></span>
            <span class="badge success">Units Earned: <strong><?= h(number_format($cumStats['units_earned'], 1)) ?></strong></span>
            <span class="badge <?= $cumStats['gwa'] !== null && $cumStats['gwa'] <= 3.0 ? 'success' : 'danger' ?>">
                Cumulative GWA: <strong><?= $cumStats['gwa'] !== null ? h(number_format($cumStats['gwa'], 2)) : '—' ?></strong>
            </span>
        </div>
    </div>
</div>

<!-- Academic Honors -->
<?php if (!empty($honorResults) || !empty($officialHonors)): ?>
<div class="card" style="margin-top:16px;">
    <h3>Academic Honors</h3>
    <?php if (!empty($officialHonors)): ?>
        <div style="margin-bottom:12px;">
            <?php foreach ($officialHonors as $oh): ?>
                <span class="badge success" style="font-size:14px;padding:6px 12px;margin-right:8px;"><?= h($oh['honor_name']) ?> (Official)</span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($honorResults)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($honorResults as $hr): ?>
                <div style="padding:8px 14px;border-radius:8px;border:1px solid <?= $hr['eligible'] ? '#bbf7d0' : '#e2e8f0' ?>;background:<?= $hr['eligible'] ? '#f0fdf4' : '#f8fafc' ?>;">
                    <strong><?= h($hr['rule']['honor_name']) ?></strong>
                    <?php if ($hr['eligible']): ?>
                        <span class="badge success" style="margin-left:6px;">Eligible</span>
                    <?php else: ?>
                        <span class="badge secondary" style="margin-left:6px;">Not Eligible</span>
                        <?php if (!empty($hr['reasons'])): ?>
                            <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= h(implode('; ', $hr['reasons'])) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Per-term grouped tables -->
<?php foreach ($grouped as $group):
    $stats = GradingEngine::computeGwa($group['rows']);
?>
    <div class="card" style="margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
            <h3 style="margin:0;"><?= h($group['year_label'] . ' — ' . semester_label((string) $group['semester'])) ?></h3>
            <div class="actions-row">
                <span class="badge info">Units: <strong><?= h(number_format($stats['units_total'], 1)) ?></strong></span>
                <span class="badge success">Earned: <strong><?= h(number_format($stats['units_earned'], 1)) ?></strong></span>
                <span class="badge <?= $stats['gwa'] !== null && $stats['gwa'] <= 3.0 ? 'success' : 'danger' ?>">
                    Term GWA: <strong><?= $stats['gwa'] !== null ? h(number_format($stats['gwa'], 2)) : '—' ?></strong>
                </span>
                <a class="btn small" href="<?= h(app_url('student/cog.php?term_id=' . $group['term_id'])) ?>">Download COG</a>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Units</th>
                        <th>Midterm</th>
                        <th>Final</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['rows'] as $row):
                    $midGrade = $row['midterm_grade_new'] ?? $row['midterm_grade'] ?? null;
                    $finGrade = $row['final_grade_new'] ?? $row['final_grade'] ?? null;
                ?>
                    <tr>
                        <td><?= h($row['subject_code']) ?></td>
                        <td><?= h($row['subject_description']) ?></td>
                        <td><?= h($row['units']) ?></td>
                        <td><?= h($midGrade ?: '—') ?></td>
                        <td>
                            <?php if ($finGrade): ?>
                                <strong><?= h($finGrade) ?></strong>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= GradingEngine::isPassing($finGrade) ? 'success' : (($row['enrollment_status'] ?? '') === 'enrolled' && !$finGrade ? 'info' : 'danger') ?>">
                                <?= GradingEngine::isPassing($finGrade) ? 'Passed' : (($row['enrollment_status'] ?? '') === 'enrolled' && !$finGrade ? 'Enrolled' : '—') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;"></th>
                        <th><?= h(number_format($stats['units_total'], 1)) ?> Units</th>
                        <th colspan="3">GWA: <?= $stats['gwa'] !== null ? h(number_format($stats['gwa'], 2)) : '—' ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if (empty($grouped)): ?>
    <div class="card" style="margin-top:16px;text-align:center;color:#666;">
        No graded subjects found<?= $selectedTermId > 0 ? ' for the selected term' : '' ?>.
    </div>
<?php endif; ?>

<?php
render_page('Grades', 'Grades / COG', (string) ob_get_clean());
