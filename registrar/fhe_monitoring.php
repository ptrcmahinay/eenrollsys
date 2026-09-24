<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('registrar');

$action  = trim($_GET['action'] ?? 'list');
$studentId = (int) ($_GET['student_id'] ?? 0);

if (is_post()) {
    $postAction = trim($_POST['action'] ?? '');

    if ($postAction === 'save_fhe_settings') {
        save_fhe_settings([
            'max_allowed_years'              => (int) ($_POST['max_allowed_years'] ?? 5),
            'max_allowed_semesters'          => (int) ($_POST['max_allowed_semesters'] ?? 10),
            'max_university_residency_years' => (int) ($_POST['max_university_residency_years'] ?? 6),
            'university_residency_action'    => $_POST['university_residency_action'] ?? 'BLOCK',
            'allow_registrar_override'       => (int) ($_POST['allow_registrar_override'] ?? 1),
        ], $_SESSION['user_id'] ?? null);
        flash('success', 'FHE settings saved.');
        redirect('registrar/fhe_monitoring.php?action=settings');
    }

    if ($postAction === 'create_override') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $type = trim($_POST['override_type'] ?? 'FHE');
        $reason = trim($_POST['reason'] ?? '');
        $untilTerm = !empty($_POST['approved_until_term_id']) ? (int) $_POST['approved_until_term_id'] : null;
        if ($sid > 0 && $reason !== '') {
            create_enrollment_override($sid, $type, $reason, $untilTerm, $_SESSION['user_id']);
            flash('success', 'Override recorded.');
        } else {
            flash('error', 'Provide a reason for the override.');
        }
        redirect('registrar/fhe_monitoring.php?action=student&student_id=' . $sid);
    }

    if ($postAction === 'revoke_override') {
        $oid = (int) ($_POST['override_id'] ?? 0);
        if ($oid > 0) {
            revoke_enrollment_override($oid);
            flash('success', 'Override revoked.');
        }
        redirect($_SERVER['HTTP_REFERER'] ?? 'registrar/fhe_monitoring.php');
    }

    if ($postAction === 'record_previous_fhe') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        if ($sid > 0) {
            save_previous_financial_assistance($sid, [
                [
                    'previous_hei'         => trim($_POST['previous_hei'] ?? ''),
                    'previous_hei_type'    => $_POST['previous_hei_type'] ?? 'OTHER',
                    'program_name'         => trim($_POST['program_name'] ?? ''),
                    'previous_fhe_semesters' => (int) ($_POST['previous_fhe_semesters'] ?? 0),
                    'government_funded'    => (int) ($_POST['government_funded'] ?? 1),
                    'fhe_verified'         => (int) ($_POST['fhe_verified'] ?? 0),
                    'verified_by'          => $_SESSION['user_id'] ?? null,
                    'verified_at'          => (int) ($_POST['fhe_verified'] ?? 0) ? date('Y-m-d H:i:s') : null,
                    'remarks'              => trim($_POST['remarks'] ?? ''),
                    'encoded_by'           => $_SESSION['user_id'] ?? null,
                ]
            ]);
            flash('success', 'Previous FHE record saved.');
        }
        redirect('registrar/fhe_monitoring.php?action=student&student_id=' . $sid);
    }
}

$fheSettings = get_fhe_settings();
ob_start();

$studentSearch = trim($_GET['q'] ?? '');
$filterProgram = trim($_GET['program'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterElig    = trim($_GET['eligibility'] ?? '');

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');

$studentRows = [];
$fheScholarship = get_scholarship_program_by_code('RA10931');
if ($fheScholarship) {
    $sql = 'SELECT s.id, s.student_number, s.first_name, s.last_name, s.middle_name, s.program_id, s.year_level,
                   p.program_code, p.program_name, ss.id AS ss_id
            FROM students s
            INNER JOIN programs p ON p.programs_id = s.program_id
            LEFT JOIN student_scholarships ss ON ss.student_id = s.id AND ss.scholarship_id = :schid AND ss.status = "ACTIVE"
            WHERE s.record_status = "active"';
    $params = ['schid' => (int) $fheScholarship['id']];

    if ($studentSearch !== '') {
        $sql .= ' AND (s.student_number LIKE :q OR CONCAT(s.first_name, " ", s.last_name) LIKE :q2)';
        $params['q'] = '%' . $studentSearch . '%';
        $params['q2'] = '%' . $studentSearch . '%';
    }
    if ($filterProgram !== '') {
        $sql .= ' AND s.program_id = :pid';
        $params['pid'] = $filterProgram;
    }
    $sql .= ' ORDER BY s.student_number ASC LIMIT 200';
    $allStudents = fetch_all($sql, $params);

    foreach ($allStudents as $s) {
        $elig = check_fhe_eligibility((int) $s['id'], $s);
        if ($filterElig !== '' && (($filterElig === 'eligible' && !$elig['eligible']) || ($filterElig === 'exhausted' && $elig['eligible']))) {
            continue;
        }
        $s['fhe_eligibility'] = $elig;
        $studentRows[] = $s;
    }
}

if ($action === 'settings' && $action !== 'student') :
    $maxYears = (int) $fheSettings['max_allowed_years'];
    $maxSems = (int) $fheSettings['max_allowed_semesters'];
    $maxRes = (int) $fheSettings['max_university_residency_years'];
    $resAction = $fheSettings['university_residency_action'];
    $allowOverride = (int) $fheSettings['allow_registrar_override'];
?>
<div class="page-header">
    <div>
        <h1>FHE Settings</h1>
        <p style="color:var(--muted);font-size:13px;margin-top:2px;">Configure FHE allowance and university residency rules.</p>
    </div>
    <a href="fhe_monitoring.php" class="btn secondary">Back to Monitoring</a>
</div>

<div class="card" style="max-width:600px;">
    <form method="post">
        <input type="hidden" name="action" value="save_fhe_settings">
        <div style="display:grid;gap:14px;">
            <div class="form-group">
                <label style="font-weight:600;">Max FHE Duration (Years)</label>
                <input type="number" name="max_allowed_years" value="<?= h((string) $maxYears) ?>" min="1" max="10" required>
            </div>
            <div class="form-group">
                <label style="font-weight:600;">Max FHE Semesters</label>
                <input type="number" name="max_allowed_semesters" value="<?= h((string) $maxSems) ?>" min="2" max="20" required>
            </div>
            <hr style="border:none;border-top:1px solid var(--line);">
            <div class="form-group">
                <label style="font-weight:600;">University Max Residency (Years)</label>
                <input type="number" name="max_university_residency_years" value="<?= h((string) $maxRes) ?>" min="1" max="15" required>
                <div style="font-size:11px;color:var(--muted);">Separate from FHE. Determines if student can enroll at all.</div>
            </div>
            <div class="form-group">
                <label style="font-weight:600;">When Max Residency Exceeded</label>
                <select name="university_residency_action">
                    <option value="BLOCK" <?= $resAction === 'BLOCK' ? 'selected' : '' ?>>Block Enrollment</option>
                    <option value="WARN" <?= $resAction === 'WARN' ? 'selected' : '' ?>>Show Warning Only</option>
                </select>
            </div>
            <div class="form-group">
                <label style="font-weight:600;">Allow Registrar Override</label>
                <select name="allow_registrar_override">
                    <option value="1" <?= $allowOverride ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= !$allowOverride ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Save Settings</button>
            </div>
        </div>
    </form>
</div>

<?php elseif ($action === 'student' && $studentId > 0) :
    $student = fetch_one('SELECT s.*, p.program_code, p.program_name FROM students s INNER JOIN programs p ON p.programs_id = s.program_id WHERE s.id = :id LIMIT 1', ['id' => $studentId]);
    if (!$student) { flash('error', 'Student not found.'); redirect('registrar/fhe_monitoring.php'); }
    $elig = check_fhe_eligibility($studentId, $student);
    $overrides = get_student_overrides($studentId);
    $pfa = get_previous_financial_assistance($studentId);
    $residency = check_university_residency($studentId, $student);
    $fheScholarship = get_scholarship_program_by_code('RA10931');

    $enrolledTerms = fetch_all(
        'SELECT DISTINCT er.term_id FROM enrollment_requests er WHERE er.student_id = :sid AND er.workflow_status IN ("approved","finalized")',
        ['sid' => $studentId]
    );
    $enrolledTermIds = array_column($enrolledTerms, 'term_id');

    $loaTerms = fetch_all(
        'SELECT DISTINCT loa.semester, loa.academic_year FROM leave_of_absence loa WHERE loa.student_id = :sid AND loa.status IN ("active","extended","returned")',
        ['sid' => $studentId]
    );
    $loaSemesters = [];
    foreach ($loaTerms as $lt) {
        $loaSemesters[($lt['academic_year'] ?? '') . '-' . ($lt['semester'] ?? '')] = true;
    }

    $allTerms = fetch_all(
        'SELECT at2.id AS term_id, at2.semester, ay.start_year, ay.end_year
         FROM academic_terms at2 INNER JOIN academic_years ay ON ay.id = at2.academic_year_id
         WHERE at2.status IN ("active","closed")
         ORDER BY ay.start_year ASC, FIELD(at2.semester, "1", "2", "mid")'
    );

    $combinedHistory = [];
    foreach ($pfa as $p) {
        if ((int) ($p['government_funded'] ?? 0) && (int) ($p['fhe_verified'] ?? 0) && (int) ($p['previous_fhe_semesters'] ?? 0) > 0) {
            for ($i = 0; $i < (int) $p['previous_fhe_semesters']; $i++) {
                $combinedHistory[] = [
                    'start_year' => '', 'end_year' => '', 'semester' => '',
                    'status' => 'PREVIOUS_SUC', 'source' => $p['previous_hei'] ?? 'Previous HEI',
                    'counted' => true, 'sort_key' => '0-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ];
            }
        }
    }
    foreach ($allTerms as $t) {
        $tid = (int) $t['term_id'];
        $isEnrolled = in_array($tid, $enrolledTermIds);
        $isLoa = isset($loaSemesters[($t['start_year'] ?? '') . '-' . ($t['semester'] ?? '')])
              || isset($loaSemesters[($t['end_year'] ?? '') . '-' . ($t['semester'] ?? '')]);
        $termStatus = $isEnrolled ? 'ENROLLED' : ($isLoa ? 'LOA' : 'NOT_ENROLLED');
        $rule = $fheScholarship ? get_consumption_rule((int) $fheScholarship['id'], $termStatus) : null;
        $action = $rule ? $rule['action'] : ($termStatus === 'LOA' ? 'EXCLUDE' : 'COUNT');
        $counts = ($action === 'COUNT');
        $combinedHistory[] = [
            'start_year' => $t['start_year'], 'end_year' => $t['end_year'], 'semester' => $t['semester'],
            'status' => $termStatus, 'source' => 'CvSU', 'counted' => $counts,
            'sort_key' => ($t['start_year'] ?? '9999') . '-' . ($t['semester'] === '2' ? '5' : ($t['semester'] === 'mid' ? '3' : '1')),
        ];
    }
    usort($combinedHistory, fn($a, $b) => strcmp($a['sort_key'], $b['sort_key']));
?>
<div class="page-header">
    <div>
        <h1><?= h($student['first_name'] . ' ' . h($student['last_name'])) ?></h1>
        <p style="color:var(--muted);font-size:13px;margin-top:2px;"><?= h($student['student_number']) ?> &middot; <?= h($student['program_code'] . ' — ' . $student['program_name']) ?> &middot; Year <?= (int) $student['year_level'] ?></p>
    </div>
    <a href="fhe_monitoring.php" class="btn secondary">Back to Monitoring</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div class="card">
        <h3>FHE Allowance</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;">
            <div>
                <div style="font-size:24px;font-weight:700;color:var(--primary);"><?= (int) $elig['allowable'] ?></div>
                <div style="font-size:11px;color:var(--muted);">Allowed Semesters</div>
            </div>
            <div>
                <div style="font-size:24px;font-weight:700;color:#e74c3c;"><?= (int) $elig['consumed'] ?></div>
                <div style="font-size:11px;color:var(--muted);">Consumed</div>
            </div>
            <div>
                <div style="font-size:24px;font-weight:700;color:#27ae60;"><?= (int) $elig['remaining'] ?></div>
                <div style="font-size:11px;color:var(--muted);">Remaining</div>
            </div>
        </div>
        <div style="margin-top:12px;text-align:center;">
            <?php if ($elig['eligible']): ?>
                <span class="badge success" style="font-size:14px;padding:6px 16px;">Eligible</span>
            <?php else: ?>
                <span class="badge danger" style="font-size:14px;padding:6px 16px;">Not Eligible</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($elig['notes'])): ?>
        <div style="margin-top:12px;font-size:12px;color:var(--muted);">
            <?php foreach ($elig['notes'] as $n): ?>
                <div>&bull; <?= h($n) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>University Residency</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:center;">
            <div>
                <div style="font-size:24px;font-weight:700;"><?= (int) $residency['years_enrolled'] ?></div>
                <div style="font-size:11px;color:var(--muted);">Years Enrolled</div>
            </div>
            <div>
                <div style="font-size:24px;font-weight:700;"><?= (int) $residency['max_years'] ?></div>
                <div style="font-size:11px;color:var(--muted);">Max Allowed</div>
            </div>
        </div>
        <div style="margin-top:12px;text-align:center;">
            <?php if ($residency['blocked']): ?>
                <span class="badge danger" style="font-size:14px;padding:6px 16px;">Enrollment Blocked</span>
            <?php elseif ($residency['has_override']): ?>
                <span class="badge warning" style="font-size:14px;padding:6px 16px;">Override Active</span>
            <?php else: ?>
                <span class="badge success" style="font-size:14px;padding:6px 16px;">Within Limit</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($elig['previous_hei']): ?>
<div class="card" style="margin-bottom:16px;">
    <h3>Previous HEI</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;font-size:13px;">
        <div><strong>School:</strong> <?= h($elig['previous_hei']) ?></div>
        <div><strong>Type:</strong> <?= h($elig['previous_hei_type']) ?></div>
        <div><strong>Previous FHE:</strong> <?= (int) $elig['previous_fhe'] ?> semesters</div>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="card">
        <h3>FHE Term History</h3>
        <?php if (empty($combinedHistory)): ?>
            <p style="color:var(--muted);font-size:13px;">No FHE monitoring records yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>AY</th><th>Sem</th><th>Source</th><th>Status</th><th>Counted</th></tr></thead>
                <tbody>
                <?php foreach ($combinedHistory as $ch): ?>
                    <tr>
                        <td><?= h($ch['start_year'] ? $ch['start_year'] . '-' . $ch['end_year'] : '—') ?></td>
                        <td><?= h($ch['semester'] ?: '—') ?></td>
                        <td><span class="badge info" style="font-size:11px;"><?= h($ch['source']) ?></span></td>
                        <td>
                            <?php if ($ch['status'] === 'PREVIOUS_SUC'): ?>
                                <span class="badge warning">Previous SUC</span>
                            <?php elseif ($ch['status'] === 'ENROLLED'): ?>
                                <span class="badge success">Enrolled</span>
                            <?php elseif ($ch['status'] === 'LOA'): ?>
                                <span class="badge danger">LOA</span>
                            <?php else: ?>
                                <span class="badge info">Not Enrolled</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ch['counted'] ? 'YES' : 'NO' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Overrides</h3>
        <?php if (empty($overrides)): ?>
            <p style="color:var(--muted);font-size:13px;">No overrides recorded.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Type</th><th>Status</th><th>Reason</th><th>Approved By</th></tr></thead>
                <tbody>
                <?php foreach ($overrides as $o): ?>
                    <tr>
                        <td><?= h($o['override_type']) ?></td>
                        <td><span class="badge <?= $o['status'] === 'ACTIVE' ? 'success' : ($o['status'] === 'REVOKED' ? 'danger' : 'info') ?>"><?= h($o['status']) ?></span></td>
                        <td><?= h($o['reason']) ?></td>
                        <td><?= h($o['approved_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<div class="page-header">
    <div>
        <h1>FHE Monitoring</h1>
        <p style="color:var(--muted);font-size:13px;margin-top:2px;">RA 10931 / Free Higher Education — Student FHE status and term history.</p>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="fhe_monitoring.php?action=settings" class="btn secondary btn-sm">Settings</a>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:10px;align-items:end;">
        <div>
            <label style="font-weight:600;font-size:12px;">Search</label>
            <input type="text" name="q" value="<?= h($studentSearch) ?>" placeholder="Student no. or name...">
        </div>
        <div>
            <label style="font-weight:600;font-size:12px;">Program</label>
            <select name="program">
                <option value="">All</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= h($p['programs_id']) ?>" <?= $filterProgram === $p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-weight:600;font-size:12px;">Eligibility</label>
            <select name="eligibility">
                <option value="">All</option>
                <option value="eligible" <?= $filterElig === 'eligible' ? 'selected' : '' ?>>Eligible</option>
                <option value="exhausted" <?= $filterElig === 'exhausted' ? 'selected' : '' ?>>Exhausted</option>
            </select>
        </div>
        <div></div>
        <button class="btn" type="submit" style="height:fit-content;">Filter</button>
    </form>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Student No.</th>
                <th>Name</th>
                <th>Program</th>
                <th>Allowed</th>
                <th>Used</th>
                <th>Remaining</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($studentRows)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px;">No students found.</td></tr>
        <?php endif; ?>
        <?php foreach ($studentRows as $row): ?>
            <?php $e = $row['fhe_eligibility']; ?>
            <tr>
                <td><strong style="color:var(--primary);"><?= h($row['student_number']) ?></strong></td>
                <td><?= h($row['last_name'] . ', ' . $row['first_name'] . ($row['middle_name'] ? ' ' . $row['middle_name'] : '')) ?></td>
                <td><?= h($row['program_code']) ?></td>
                <td><?= (int) ($e['allowable'] ?? 0) ?></td>
                <td><?= (int) ($e['consumed'] ?? 0) ?></td>
                <td><?= (int) ($e['remaining'] ?? 0) ?></td>
                <td>
                    <?php if ($e['eligible']): ?>
                        <span class="badge success">Eligible</span>
                    <?php else: ?>
                        <span class="badge danger">Exhausted</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="icon-btn" title="View FHE Profile" href="fhe_monitoring.php?action=student&student_id=<?= (int) $row['id'] ?>">
                        <span class="material-symbols-outlined">visibility</span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_page('FHE Monitoring', 'registrar', $content);
?>