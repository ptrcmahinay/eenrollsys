<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}
$chairDeptId = (int) $staff['dept_id'];

$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label,
            t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$filterTerm = (int) ($_GET['term_id'] ?? 0);
if ($filterTerm === 0) {
    foreach ($terms as $t) {
        if ((int) $t['is_active'] === 1) { $filterTerm = (int) $t['id']; break; }
    }
}

$selectedTerm = null;
foreach ($terms as $t) {
    if ((int) $t['id'] === $filterTerm) { $selectedTerm = $t; break; }
}

$loading = [];
if ($filterTerm > 0) {
    $loading = fetch_all(
        'SELECT st.staff_id, st.full_name,
                sub.subject_code, sub.subject_description,
                sub.lec_credit, sub.lab_credit,
                p.program_code, sec.year_level, sec.section_name,
                COUNT(DISTINCT o.id) AS section_count
         FROM section_subject_offerings o
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN staff st ON st.staff_id = o.instructor_id
         WHERE o.term_id = :term_id
           AND sub.teaching_department_id = :dept_id
           AND o.instructor_id IS NOT NULL
         GROUP BY st.staff_id, st.full_name, sub.subject_code, sub.subject_description,
                  sub.lec_credit, sub.lab_credit, p.program_code, sec.year_level, sec.section_name
         ORDER BY st.full_name, sub.subject_code, p.program_code, sec.year_level, sec.section_name',
        ['term_id' => $filterTerm, 'dept_id' => $chairDeptId]
    );
}

$instructorSummary = [];
foreach ($loading as $row) {
    $key = $row['staff_id'];
    if (!isset($instructorSummary[$key])) {
        $instructorSummary[$key] = [
            'full_name' => $row['full_name'],
            'subjects' => [],
            'total_sections' => 0,
            'total_lec' => 0.0,
            'total_lab' => 0.0,
            'total_units' => 0.0,
        ];
    }
    $subKey = $row['subject_code'];
    if (!isset($instructorSummary[$key]['subjects'][$subKey])) {
        $instructorSummary[$key]['subjects'][$subKey] = [
            'subject_code' => $row['subject_code'],
            'subject_description' => $row['subject_description'],
            'lec_credit' => (float) $row['lec_credit'],
            'lab_credit' => (float) $row['lab_credit'],
            'sections' => [],
            'section_count' => 0,
        ];
    }
    $secLabel = $row['program_code'] . ' Y' . $row['year_level'] . $row['section_name'];
    if (!in_array($secLabel, $instructorSummary[$key]['subjects'][$subKey]['sections'], true)) {
        $instructorSummary[$key]['subjects'][$subKey]['sections'][] = $secLabel;
        $instructorSummary[$key]['subjects'][$subKey]['section_count']++;
        $instructorSummary[$key]['total_sections']++;
        $instructorSummary[$key]['total_lec'] += (float) $row['lec_credit'];
        $instructorSummary[$key]['total_lab'] += (float) $row['lab_credit'];
        $instructorSummary[$key]['total_units'] += (float) $row['lec_credit'] + (float) $row['lab_credit'];
    }
}

$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($isExport && $filterTerm > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    $termLabel = $selectedTerm['label'] ?? 'Unknown Term';
    $deptCode = fetch_one('SELECT department_code FROM departments WHERE dept_id = :id', ['id' => $chairDeptId])['department_code'] ?? 'DEPT';
    header('Content-Disposition: attachment; filename="faculty_loading_' . $deptCode . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $termLabel) . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['FACULTY LOADING — ' . $deptCode . ' — ' . $termLabel]);
    fputcsv($out, []);
    fputcsv($out, ['Professor', 'Subject Code', 'Course Description', 'Program / Year Section', '# of Sections', 'Lec Unit', 'Lab Unit', 'Total Unit']);

    foreach ($instructorSummary as $inst) {
        $first = true;
        foreach ($inst['subjects'] as $sub) {
            $secStr = implode('; ', $sub['sections']);
            fputcsv($out, [
                $first ? $inst['full_name'] : '',
                $sub['subject_code'],
                $sub['subject_description'],
                $secStr,
                $sub['section_count'],
                $sub['lec_credit'],
                $sub['lab_credit'],
                $sub['lec_credit'] + $sub['lab_credit'],
            ]);
            $first = false;
        }
        fputcsv($out, [
            '', '', '', 'SUBTOTAL',
            $inst['total_sections'],
            $inst['total_lec'],
            $inst['total_lab'],
            $inst['total_units'],
        ]);
        fputcsv($out, []);
    }
    fclose($out);
    exit;
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Faculty Loading</h1>
        <p>Subject load per instructor for <?= h($selectedTerm['label'] ?? '—') ?></p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('chair/faculty_loading.php?term_id=' . $filterTerm . '&export=csv')) ?>">
            <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">download</span>
            Export CSV
        </a>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <label>Academic Year / Semester</label>
            <select name="term_id" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?><?= (int)$t['is_active'] === 1 ? ' (Active)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:200px;">
            <label>Search</label>
            <input type="text" id="loadingSearch" placeholder="Search instructor or subject..." style="width:100%;box-sizing:border-box;">
        </div>
    </form>
</div>

<?php if ($filterTerm > 0 && $instructorSummary !== []): ?>
<div class="card">
    <div class="table-wrap">
        <table id="loadingTable">
            <thead>
                <tr>
                    <th>Professor</th>
                    <th>Code</th>
                    <th>Course Description</th>
                    <th>Program / Year Section</th>
                    <th># of Sec</th>
                    <th>Lec Unit</th>
                    <th>Lab Unit</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instructorSummary as $inst): ?>
                    <?php $first = true; ?>
                    <?php foreach ($inst['subjects'] as $sub): ?>
                        <tr>
                            <td><?= $first ? h($inst['full_name']) : '' ?></td>
                            <td><span class="badge" style="font-family:monospace;"><?= h($sub['subject_code']) ?></span></td>
                            <td><?= h($sub['subject_description']) ?></td>
                            <td><?= h(implode('; ', $sub['sections'])) ?></td>
                            <td style="text-align:center;"><?= (int) $sub['section_count'] ?></td>
                            <td style="text-align:center;"><?= h((string) $sub['lec_credit']) ?></td>
                            <td style="text-align:center;"><?= h((string) $sub['lab_credit']) ?></td>
                            <td style="text-align:center;font-weight:600;"><?= h((string) ($sub['lec_credit'] + $sub['lab_credit'])) ?></td>
                            <?php $first = false; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background:var(--line);font-weight:600;">
                        <td colspan="4" style="text-align:right;">Subtotal — <?= h($inst['full_name']) ?></td>
                        <td style="text-align:center;"><?= (int) $inst['total_sections'] ?></td>
                        <td style="text-align:center;"><?= h((string) $inst['total_lec']) ?></td>
                        <td style="text-align:center;"><?= h((string) $inst['total_lab']) ?></td>
                        <td style="text-align:center;"><?= h((string) $inst['total_units']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--primary);color:#fff;font-weight:700;">
                    <td colspan="4" style="text-align:right;">DEPARTMENT TOTAL</td>
                    <?php
                    $grandSections = 0; $grandLec = 0.0; $grandLab = 0.0; $grandTotal = 0.0;
                    foreach ($instructorSummary as $inst) {
                        $grandSections += $inst['total_sections'];
                        $grandLec += $inst['total_lec'];
                        $grandLab += $inst['total_lab'];
                        $grandTotal += $inst['total_units'];
                    }
                    ?>
                    <td style="text-align:center;"><?= $grandSections ?></td>
                    <td style="text-align:center;"><?= h((string) $grandLec) ?></td>
                    <td style="text-align:center;"><?= h((string) $grandLab) ?></td>
                    <td style="text-align:center;"><?= h((string) $grandTotal) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php elseif ($filterTerm > 0): ?>
<div class="card" style="text-align:center;padding:40px;color:#94a3b8;">
    No faculty loading data for this term. Assign instructors to subject offerings first.
</div>
<?php endif; ?>

<script>
document.getElementById('loadingSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#loadingTable tbody tr').forEach(function(row) {
        if (row.style.background) return;
        var text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
    });
});
</script>
<?php
render_page('Faculty Loading', 'Faculty Loading', (string) ob_get_clean());
