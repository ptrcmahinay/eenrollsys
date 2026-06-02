<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$terms = student_terms_with_enrollment((int) $student['id']);
$selectedTermId = (int) ($_GET['term_id'] ?? 0);
$rows = fetch_all(
    'SELECT ss.final_grade, ss.units, ss.enrollment_status,
            sub.subject_code, sub.subject_description,
            ay.year_label, ay.start_year, t.id AS term_id, t.semester
     FROM student_subjects ss
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.student_id = :student_id' . ($selectedTermId > 0 ? ' AND t.id = :term_id' : '') . '
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
    array_filter([
        'student_id' => (int) $student['id'],
        'term_id' => $selectedTermId > 0 ? $selectedTermId : null,
    ], static fn($value) => $value !== null)
);

// Group rows by term and compute GWA per term + cumulative
$grouped = [];
foreach ($rows as $r) {
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

function compute_gwa(array $items): array {
    $totalUnits = 0.0; $weighted = 0.0; $earned = 0.0; $allUnits = 0.0;
    foreach ($items as $it) {
        $units = (float) $it['units'];
        $allUnits += $units;
        $grade = $it['final_grade'];
        if ($grade !== null && $grade !== '' && is_numeric($grade)) {
            $weighted   += ((float) $grade) * $units;
            $totalUnits += $units;
            if (grade_is_passing($grade)) {
                $earned += $units;
            }
        }
    }
    return [
        'gwa'          => $totalUnits > 0 ? round($weighted / $totalUnits, 2) : null,
        'units_total'  => $allUnits,
        'units_taken'  => $totalUnits,
        'units_earned' => $earned,
    ];
}

$cumWeighted = 0.0; $cumUnits = 0.0;
foreach ($rows as $r) {
    if ($r['final_grade'] !== null && $r['final_grade'] !== '' && is_numeric($r['final_grade'])) {
        $cumWeighted += ((float) $r['final_grade']) * (float) $r['units'];
        $cumUnits    += (float) $r['units'];
    }
}
$cumGwa = $cumUnits > 0 ? round($cumWeighted / $cumUnits, 2) : null;

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
        <h3>Available terms</h3>
        <div class="actions-row">
            <a class="btn secondary small" href="<?= h(app_url('student/grades.php')) ?>">All Terms</a>
            <?php foreach ($terms as $term): ?>
                <a class="btn secondary small" href="<?= h(app_url('student/grades.php?term_id=' . $term['id'])) ?>"><?= h($term['year_label'] . ' ' . semester_label((string) $term['semester'])) ?></a>
                <a class="btn small" href="<?= h(app_url('student/cog.php?term_id=' . $term['id'])) ?>">COG</a>
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
            <span class="badge info">Cumulative Units: <strong><?= h((string) $cumUnits) ?></strong></span>
            <span class="badge <?= $cumGwa !== null && $cumGwa <= 3.0 ? 'success' : 'danger' ?>">
                Cumulative GWA: <strong><?= $cumGwa !== null ? h(number_format($cumGwa, 2)) : '—' ?></strong>
            </span>
        </div>
    </div>
</div>

<!-- Per-term grouped tables -->
<?php foreach ($grouped as $group): $stats = compute_gwa($group['rows']); ?>
    <div class="card" style="margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
            <h3 style="margin:0;"><?= h($group['year_label'] . ' — ' . semester_label((string) $group['semester'])) ?></h3>
            <div class="actions-row">
                <span class="badge info">Units: <strong><?= h((string) $stats['units_total']) ?></strong></span>
                <span class="badge success">Earned: <strong><?= h((string) $stats['units_earned']) ?></strong></span>
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
                        <th>Final Grade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['rows'] as $row): ?>
                    <tr>
                        <td><?= h($row['subject_code']) ?></td>
                        <td><?= h($row['subject_description']) ?></td>
                        <td><?= h($row['units']) ?></td>
                        <td><?= h($row['final_grade'] ?: '-') ?></td>
                        <td>
                            <span class="badge <?= grade_is_passing($row['final_grade']) ? 'success' : (($row['enrollment_status'] ?? '') === 'enrolled' && !$row['final_grade'] ? 'info' : '') ?>">
                                <?= grade_is_passing($row['final_grade']) ? 'Passed' : (($row['enrollment_status'] ?? '') === 'enrolled' && !$row['final_grade'] ? 'Enrolled' : '--') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;"></th>
                        <th><?= h((string) $stats['units_total']) ?> Units</th>
                        <th colspan="2">GWA: <?= $stats['gwa'] !== null ? h(number_format($stats['gwa'], 2)) : '—' ?></th>
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