<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$currentTerm = current_term();
$currentSubjects = [];
if ($currentTerm !== null) {
    $currentSubjects = fetch_all(
        'SELECT sub.subject_code, sub.subject_description, ss.units, o.day_of_week, o.time_range, o.room
         FROM student_subjects ss
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         WHERE ss.student_id = :student_id AND ss.term_id = :term_id AND ss.enrollment_status = "enrolled"
         ORDER BY sub.subject_code',
        ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
    );
}

$latestRequest = $currentTerm ? fetch_one(
    'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id ORDER BY id DESC LIMIT 1',
    ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
) : null;
$financial = financial_profile($student, $currentTerm);

// ============================================================
// Academic analytics: GWA per term, cumulative credits, standing
// ============================================================
$gradeRows = fetch_all(
    'SELECT ss.final_grade, ss.units, t.id AS term_id, t.semester,
            ay.year_label, ay.start_year
     FROM student_subjects ss
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.student_id = :student_id AND ss.final_grade IS NOT NULL AND ss.final_grade <> ""
     ORDER BY ay.start_year ASC, FIELD(t.semester, "1", "2", "mid")',
    ['student_id' => (int) $student['id']]
);

$termAgg = []; // term_id => [label, weighted, units, passing_units, all_units]
foreach ($gradeRows as $r) {
    $tid = (int) $r['term_id'];
    if (!isset($termAgg[$tid])) {
        $termAgg[$tid] = [
            'label'         => $r['year_label'] . ' ' . semester_label((string) $r['semester']),
            'order'         => ((int) $r['start_year']) * 10 + (($r['semester'] === '1') ? 1 : (($r['semester'] === '2') ? 2 : 3)),
            'weighted'      => 0.0,
            'graded_units'  => 0.0,
            'passing_units' => 0.0,
            'all_units'     => 0.0,
        ];
    }
    $units = (float) $r['units'];
    $termAgg[$tid]['all_units'] += $units;
    $numeric = parse_numeric_grade((string) $r['final_grade']);
    if ($numeric !== null) {
        $termAgg[$tid]['weighted']     += $numeric * $units;
        $termAgg[$tid]['graded_units'] += $units;
    }
    if (grade_is_passing((string) $r['final_grade'])) {
        $termAgg[$tid]['passing_units'] += $units;
    }
}

uasort($termAgg, static fn($a, $b) => $a['order'] <=> $b['order']);

$termLabels  = [];
$termGwa     = [];
$cumCredits  = [];
$runningCred = 0.0;
$latestGwa   = null;
foreach ($termAgg as $row) {
    $gwa = $row['graded_units'] > 0 ? round($row['weighted'] / $row['graded_units'], 3) : null;
    $termLabels[] = $row['label'];
    $termGwa[]    = $gwa;
    $runningCred += $row['passing_units'];
    $cumCredits[] = $runningCred;
    if ($gwa !== null) {
        $latestGwa = $gwa;
    }
}

// Required units from program curriculum
$requiredUnits = (float) (fetch_one(
    'SELECT COALESCE(SUM(sub.units),0) AS total
     FROM program_curriculum pc
     INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
     WHERE pc.program_id = :pid',
    ['pid' => (int) ($student['program_id'] ?? 0)]
)['total'] ?? 0);

$earnedUnits   = $runningCred;
$completionPct = $requiredUnits > 0 ? min(100.0, round(($earnedUnits / $requiredUnits) * 100, 1)) : 0.0;

// Cumulative GWA (weighted across all graded subjects)
$totalWeighted = 0.0;
$totalGraded   = 0.0;
foreach ($termAgg as $row) {
    $totalWeighted += $row['weighted'];
    $totalGraded   += $row['graded_units'];
}
$cumulativeGwa = $totalGraded > 0 ? round($totalWeighted / $totalGraded, 3) : null;

// Academic standing classification (Philippine 1.0 best / 5.0 worst scale)
// Uses LATEST term GWA primarily; falls back to cumulative
$standingBasis = $latestGwa ?? $cumulativeGwa;
if ($standingBasis === null) {
    $standing = ['label' => 'No Records', 'class' => 'info', 'desc' => 'No final grades posted yet.'];
} elseif ($standingBasis <= 1.25) {
    $standing = ['label' => "President's List", 'class' => 'success', 'desc' => 'GWA ≤ 1.25 with no failing grades.'];
} elseif ($standingBasis <= 1.75) {
    $standing = ['label' => "Dean's List", 'class' => 'success', 'desc' => 'GWA between 1.26 and 1.75.'];
} elseif ($standingBasis <= 2.50) {
    $standing = ['label' => 'Good Standing', 'class' => 'info', 'desc' => 'GWA between 1.76 and 2.50.'];
} elseif ($standingBasis <= 3.00) {
    $standing = ['label' => 'Satisfactory', 'class' => 'warning', 'desc' => 'GWA between 2.51 and 3.00.'];
} else {
    $standing = ['label' => 'Academic Probation', 'class' => 'danger', 'desc' => 'GWA above 3.00 — improvement required.'];
}

$chartData = [
    'labels'        => $termLabels,
    'gwa'           => $termGwa,
    'cumCredits'    => $cumCredits,
    'requiredUnits' => $requiredUnits,
    'earnedUnits'   => $earnedUnits,
    'completionPct' => $completionPct,
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Dashboard</h1>
        <p>Student information, present semester subjects, downloadable forms, grades, checklist, and online enrollment status.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('student/enrollment.php')) ?>">Online Enrollment</a>
        <a class="btn secondary" href="<?= h(app_url('student/grades.php')) ?>">Grades / COG</a>
    </div>
</div>

<div class="grid cols-4">
    <div class="card slim"><div class="metric-label">Student Number</div><div class="metric" style="font-size:22px;"><?= h($student['student_number']) ?></div></div>
    <div class="card slim"><div class="metric-label">Program</div><div class="metric" style="font-size:22px;"><?= h($student['program_code']) ?></div></div>
    <div class="card slim"><div class="metric-label">Year / Section</div><div class="metric" style="font-size:22px;"><?= h($student['year_level'] . ($student['section_name'] ? $student['section_name'] : '')) ?></div></div>
    <div class="card slim"><div class="metric-label">RA / Tuition</div><div class="metric" style="font-size:18px;"><?= h($financial['label']) ?></div></div>
</div>
<div class="grid cols-2" style="margin-top:16px;">
    <div class="card">
        <h3>Student Information</h3>
        <div class="kv-list">
            <div class="item"><div class="k">Full Name</div><div class="v"><?= h($student['full_name']) ?></div></div>
            <div class="item"><div class="k">Address</div><div class="v"><?= h($student['address']) ?></div></div>
            <div class="item"><div class="k">Entry Year</div><div class="v"><?= h($student['entry_year']) ?></div></div>
            <div class="item"><div class="k">Enrollment Recommendation</div><div class="v"><?= h(ucfirst(student_status_recommendation((int) $student['id']))) ?></div></div>
        </div>
    </div>
    <div class="card">
        <h3>Current enrollment status</h3>
        <?php if ($latestRequest): ?>
            <p><span class="badge <?= h(workflow_badge_class((string) $latestRequest['workflow_status'])) ?>"><?= h(request_workflow_label((string) $latestRequest['workflow_status'])) ?></span></p>
            <p class="helper">Requested status: <?= h($latestRequest['requested_status']) ?> · Units: <?= h($latestRequest['total_units']) ?> · Amount: ₱<?= h(format_money($latestRequest['total_amount'])) ?></p>
        <?php else: ?>
            <p class="helper">No request submitted for the active term yet.</p>
        <?php endif; ?>
        <div class="actions-row">
            <a class="btn secondary" href="<?= h(app_url('student/enrollment_status.php')) ?>">View request history</a>
            <a class="btn secondary" href="<?= h(app_url('student/registration_form.php')) ?>">Latest registration form</a>
            <a class="btn secondary" href="<?= h(app_url('checklist.php')) ?>">Checklist</a>
        </div>
    </div>
</div>

<!-- ============== Academic Analytics (Progress Report) ============== -->
<style>
.pr-wrap { display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-top:16px; }
@media (max-width: 900px) { .pr-wrap { grid-template-columns: 1fr; } }
.pr-hero {
    position:relative; overflow:hidden; border-radius:18px; padding:28px;
    background: linear-gradient(135deg,#0f5132 0%, #14532d 60%, #0b3d24 100%);
    color:#eafff3; min-height:240px;
}
.pr-hero::before, .pr-hero::after {
    content:""; position:absolute; right:-30px; top:-30px; width:220px; height:220px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius:50%;
}
.pr-hero::after { right:60px; top:80px; width:140px; height:140px; }
.pr-eyebrow {
    display:inline-block; padding:5px 12px; border-radius:999px;
    background: rgba(255,255,255,0.12); color:#d6f5e3;
    font-size:11px; font-weight:600; letter-spacing:0.12em; text-transform:uppercase;
}
.pr-title { font-size:34px; font-weight:700; margin:14px 0 10px; line-height:1.15; }
.pr-sub { color:#bfe7d0; font-size:14px; max-width:480px; margin-bottom:22px; }
.pr-stats { display:flex; gap:48px; flex-wrap:wrap; position:relative; z-index:1; }
.pr-stat .lbl { color:#9ed1b3; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; font-weight:600; }
.pr-stat .val { color:#fff; font-size:30px; font-weight:700; margin-top:4px; }
.pr-side { background:#fff; border-radius:18px; padding:22px; box-shadow:0 1px 2px rgba(0,0,0,0.04); border:1px solid #eee; }
.pr-side-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.pr-side-title { font-size:18px; font-weight:600; color:#1a1a1a; margin:0; }
.pr-pill { background:#0f5132; color:#fff; font-size:10px; font-weight:700; letter-spacing:0.1em;
    padding:6px 10px; border-radius:8px; text-transform:uppercase; white-space:nowrap; }
.pr-pill.warn { background:#a16207; } .pr-pill.danger { background:#b91c1c; } .pr-pill.info { background:#475569; }
.pr-bar-row { display:flex; justify-content:space-between; font-size:13px; color:#374151; margin:18px 0 6px; }
.pr-bar { height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
.pr-bar > span { display:block; height:100%; background:#0f5132; border-radius:999px; }
.pr-side-note { color:#6b7280; font-size:13px; margin:14px 0 18px; }
.pr-side-btn { display:block; text-align:center; padding:11px 14px; border-radius:10px;
    background:#f3f4f6; color:#111827; font-weight:500; text-decoration:none; }
.pr-side-btn:hover { background:#e5e7eb; }
.pr-charts { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:16px; }
@media (max-width: 900px) { .pr-charts { grid-template-columns: 1fr; } }
.pr-chart-card { background:#fff; border:1px solid #eee; border-radius:18px; padding:22px; }
.pr-chart-card h3 { margin:0 0 4px; font-size:18px; font-weight:600; color:#1a1a1a; }
.pr-chart-card .helper { color:#6b7280; font-size:13px; margin:0 0 12px; }
.pr-standing-list { list-style:none; padding:0; margin:0; }
.pr-standing-list li { display:flex; justify-content:space-between; align-items:center;
    padding:10px 0; border-bottom:1px dashed #eee; font-size:14px; color:#374151; }
.pr-standing-list li:last-child { border-bottom:0; }
</style>

<div class="pr-wrap">
    <!-- LEFT: Progress Report hero -->
    <div class="pr-hero">
        <span class="pr-eyebrow">Academic Excellence</span>
        <div class="pr-title">Progress Report:<br><?= h($currentTerm ? ($currentTerm['year_label'] ?? '') . ' ' . semester_label((string) ($currentTerm['semester'] ?? '')) : 'Current Term') ?></div>
        <div class="pr-sub">
            <?php if ($cumulativeGwa !== null): ?>
                Your cumulative GWA is <strong style="color:#fff;"><?= h(number_format($cumulativeGwa, 2)) ?></strong> across <?= count($termAgg) ?> term<?= count($termAgg) === 1 ? '' : 's' ?>. Keep focusing on the remaining <?= h(number_format(max(0, $requiredUnits - $earnedUnits), 0)) ?> units to graduate.
            <?php else: ?>
                No final grades posted yet. Your progress will appear here once your first term grades are submitted.
            <?php endif; ?>
        </div>
        <div class="pr-stats">
            <div class="pr-stat">
                <div class="lbl">Current GWA</div>
                <div class="val"><?= $latestGwa !== null ? h(number_format($latestGwa, 2)) : '—' ?></div>
            </div>
            <div class="pr-stat">
                <div class="lbl">Credits Earned</div>
                <div class="val"><?= h(number_format($earnedUnits, 0)) ?> / <?= h(number_format($requiredUnits, 0)) ?></div>
            </div>
            <div class="pr-stat">
                <div class="lbl">Cumulative GWA</div>
                <div class="val"><?= $cumulativeGwa !== null ? h(number_format($cumulativeGwa, 2)) : '—' ?></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Academic Standing + completion -->
    <div class="pr-side">
        <div class="pr-side-head">
            <h3 class="pr-side-title">Academic<br>Standing</h3>
            <span class="pr-pill <?= $standing['class'] === 'success' ? '' : ($standing['class'] === 'warning' ? 'warn' : ($standing['class'] === 'danger' ? 'danger' : 'info')) ?>"><?= h($standing['label']) ?></span>
        </div>
        <div class="pr-bar-row">
            <span>Degree Completion</span>
            <span style="font-weight:600;color:#0f5132;"><?= h(number_format($completionPct, 0)) ?>%</span>
        </div>
        <div class="pr-bar"><span style="width:<?= h((string) $completionPct) ?>%;"></span></div>
        <p class="pr-side-note"><?= h($standing['desc']) ?></p>
        <a class="pr-side-btn" href="<?= h(app_url('student/grades.php')) ?>">View Full Grade Report</a>
    </div>
</div>

<div class="pr-charts">
    <div class="pr-chart-card">
        <h3>Cumulative Credits Earned</h3>
        <p class="helper">Passing units accumulated each term vs. <?= h(number_format($requiredUnits, 0)) ?> required.</p>
        <div style="position:relative;height:240px;"><canvas id="creditsChart"></canvas></div>
    </div>
    <div class="pr-chart-card">
        <h3>GPA Over Time</h3>
        <p class="helper">Term GWA trend (lower is better, 1.00–5.00 scale).</p>
        <div style="position:relative;height:240px;"><canvas id="gwaChart"></canvas></div>
    </div>
</div>

<div class="pr-charts">
    <div class="pr-chart-card">
        <h3>Degree Completion</h3>
        <p class="helper">Earned vs. remaining units required by your program.</p>
        <div style="position:relative;height:240px;"><canvas id="completionChart"></canvas></div>
    </div>
    <div class="pr-chart-card">
        <h3>Standing Reference</h3>
        <p class="helper">Philippine 1.00–5.00 grading scale.</p>
        <ul class="pr-standing-list">
            <li><span><span class="pr-pill">President's List</span></span><span>GWA ≤ 1.25</span></li>
            <li><span><span class="pr-pill">Dean's List</span></span><span>1.26 – 1.75</span></li>
            <li><span><span class="pr-pill info">Good Standing</span></span><span>1.76 – 2.50</span></li>
            <li><span><span class="pr-pill warn">Satisfactory</span></span><span>2.51 – 3.00</span></li>
            <li><span><span class="pr-pill danger">Probation</span></span><span>&gt; 3.00</span></li>
        </ul>
    </div>
</div>
<!-- ============ /Academic Analytics ============ -->



<div class="card" style="margin-top:16px;">
    <h3>Present academic year and semester subjects</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Schedule</th><th>Room</th></tr></thead>
            <tbody>
            <?php foreach ($currentSubjects as $subject): ?>
                <tr>
                    <td><?= h($subject['subject_code']) ?></td>
                    <td><?= h($subject['subject_description']) ?></td>
                    <td><?= h($subject['units']) ?></td>
                    <td><?= h(trim(($subject['day_of_week'] ?: 'TBA') . ' ' . ($subject['time_range'] ?: ''))) ?></td>
                    <td><?= h($subject['room'] ?: 'TBA') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var data = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
        if (typeof Chart === 'undefined') return;

        var hasTerms = data.labels && data.labels.length > 0;

        // GWA per term (line)
        var gwaEl = document.getElementById('gwaChart');
        if (gwaEl) {
            new Chart(gwaEl, {
                type: 'line',
                data: {
                    labels: hasTerms ? data.labels : ['No data'],
                    datasets: [{
                        label: 'Term GWA',
                        data: hasTerms ? data.gwa : [null],
                        borderColor: '#0f5132',
                        backgroundColor: 'rgba(15,81,50,0.15)',
                        tension: 0.25,
                        spanGaps: true,
                        pointRadius: 4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { reverse: true, suggestedMin: 1.0, suggestedMax: 5.0, title: { display: true, text: 'GWA (1.00 best)' } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // Cumulative credits (bar + required line)
        var credEl = document.getElementById('creditsChart');
        if (credEl) {
            new Chart(credEl, {
                type: 'bar',
                data: {
                    labels: hasTerms ? data.labels : ['No data'],
                    datasets: [
                        { label: 'Cumulative Credits', data: hasTerms ? data.cumCredits : [0], backgroundColor: '#0f5132', borderRadius: 6 },
                        { label: 'Required (' + data.requiredUnits + ')', type: 'line', data: (hasTerms ? data.labels : ['No data']).map(function(){ return data.requiredUnits; }), borderColor: '#ef4444', borderDash: [6,4], pointRadius: 0, fill: false }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Units' } } } }
            });
        }

        // Degree completion (doughnut)
        var compEl = document.getElementById('completionChart');
        if (compEl) {
            var earned = data.earnedUnits || 0;
            var remaining = Math.max(0, (data.requiredUnits || 0) - earned);
            new Chart(compEl, {
                type: 'doughnut',
                data: {
                    labels: ['Earned (' + earned + ')', 'Remaining (' + remaining + ')'],
                    datasets: [{ data: [earned, remaining], backgroundColor: ['#0f5132', '#e5e7eb'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom' } } }
            });
        }
    });
})();
</script>
<?php
render_page('Student Dashboard', 'Dashboard', (string) ob_get_clean());
