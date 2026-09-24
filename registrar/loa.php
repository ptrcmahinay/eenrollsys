<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$user = current_user();

$tableExists = false;
try {
    $stmt = db()->query("SHOW TABLES LIKE 'leave_of_absence'");
    $tableExists = $stmt && $stmt->fetch();
} catch (\Throwable $e) {}

if (!$tableExists) {
    flash('error', 'Leave of Absence table not yet created. Please run database setup first.');
    redirect('registrar/dashboard.php');
}

expire_overdue_loa();

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');
    $loaId = (int) ($_POST['loa_id'] ?? 0);

    if ($action === 'add_loa') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $studentNo = trim($_POST['student_no'] ?? '');
        $programId = trim($_POST['program_id'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
        $dateFiled = trim($_POST['date_filed'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $academicYear = trim($_POST['academic_year'] ?? '');
        $effectiveFrom = trim($_POST['effective_date_from'] ?? '');
        $effectiveTo = trim($_POST['effective_date_to'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $retSem = trim($_POST['expected_return_semester'] ?? '');
        $retAy = trim($_POST['expected_return_academic_year'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($studentId <= 0 || $studentNo === '' || $programId === '' || $dateFiled === '' || $semester === '' || $academicYear === '' || $effectiveFrom === '' || $effectiveTo === '') {
            flash('error', 'Please fill in all required fields.');
            redirect('registrar/loa.php');
        }

        $id = create_loa_record(
            $studentId, $studentNo, $programId, $departmentId, $dateFiled,
            $semester, $academicYear, $effectiveFrom, $effectiveTo,
            $reason ?: null, $retSem ?: null, $retAy ?: null,
            (int) $user['users_id'], $remarks ?: null
        );
        flash('success', 'LOA record created (Record #' . $id . '). Student status set to ON LEAVE.');
        redirect('registrar/loa.php');
    }

    if ($action === 'mark_returned' && $loaId > 0) {
        mark_loa_returned($loaId, (int) $user['users_id']);
        flash('success', 'Student marked as returned. Status restored to ACTIVE.');
        redirect('registrar/loa.php?view=' . $loaId);
    }

    if ($action === 'extend_loa' && $loaId > 0) {
        $newTo = trim($_POST['new_effective_to'] ?? '');
        $retSem = trim($_POST['expected_return_semester'] ?? '');
        $retAy = trim($_POST['expected_return_academic_year'] ?? '');

        if ($newTo === '') {
            flash('error', 'Please provide new effective date.');
            redirect('registrar/loa.php?view=' . $loaId);
        }

        extend_loa($loaId, $newTo, $retSem ?: null, $retAy ?: null, (int) $user['users_id']);
        flash('success', 'LOA extended. New LOA record created.');
        redirect('registrar/loa.php');
    }

    if ($action === 'cancel_loa' && $loaId > 0) {
        cancel_loa($loaId, (int) $user['users_id']);
        flash('success', 'LOA cancelled. Student status restored to ACTIVE.');
        redirect('registrar/loa.php');
    }

    if ($action === 'update_remarks' && $loaId > 0) {
        $remarks = trim($_POST['remarks'] ?? '');
        update_loa_record($loaId, remarks: $remarks);
        flash('success', 'Remarks updated.');
        redirect('registrar/loa.php?view=' . $loaId);
    }
}

$filterAY = trim($_GET['ay'] ?? '');
$filterSem = trim($_GET['sem'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterProgram = trim($_GET['program'] ?? '');
$viewId = (int) ($_GET['view'] ?? 0);

$where = ['1=1'];
$params = [];

if ($filterAY !== '') { $where[] = 'loa.academic_year = :ay'; $params['ay'] = $filterAY; }
if ($filterSem !== '') { $where[] = 'loa.semester = :sem'; $params['sem'] = $filterSem; }
if ($filterStatus !== '') { $where[] = 'loa.status = :status'; $params['status'] = $filterStatus; }
if ($filterProgram !== '') { $where[] = 'loa.program_id = :pid'; $params['pid'] = $filterProgram; }

$loaRecords = fetch_all(
    'SELECT loa.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
            p.program_name
     FROM leave_of_absence loa
     INNER JOIN students s ON s.id = loa.student_id
     INNER JOIN programs p ON p.programs_id = loa.program_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY FIELD(loa.status, "active","extended","returned","expired","cancelled") ASC, loa.created_at DESC',
    $params
);

$stats = get_loa_stats();
$allPrograms = fetch_all('SELECT programs_id, program_name FROM programs ORDER BY program_name');

$currentAY = trim($_GET['mon_ay'] ?? date('Y') . '-' . (date('Y') + 1));
$monitoringSem = trim($_GET['mon_sem'] ?? '1');
$returnMonitoring = get_loa_return_monitoring($currentAY, $monitoringSem);

$selectedLoa = null;
if ($viewId > 0) {
    $selectedLoa = fetch_one(
        'SELECT loa.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
                p.program_name, d.department_name
         FROM leave_of_absence loa
         INNER JOIN students s ON s.id = loa.student_id
         INNER JOIN programs p ON p.programs_id = loa.program_id
         LEFT JOIN departments d ON d.dept_id = p.department_id
         WHERE loa.id = :id',
        ['id' => $viewId]
    );
}

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Leave of Absence</h1><p>Manage and monitor official LOA records.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
    <div class="card" style="text-align:center;"><div style="font-size:24px;font-weight:700;color:var(--primary);"><?= $stats['active'] ?></div><div style="font-size:12px;color:#64748b;">Active LOA</div></div>
    <div class="card" style="text-align:center;"><div style="font-size:24px;font-weight:700;color:#16a34a;"><?= $stats['returned'] ?></div><div style="font-size:12px;color:#64748b;">Returned</div></div>
    <div class="card" style="text-align:center;"><div style="font-size:24px;font-weight:700;color:#f59e0b;"><?= $stats['extended'] ?></div><div style="font-size:12px;color:#64748b;">Extended</div></div>
    <div class="card" style="text-align:center;"><div style="font-size:24px;font-weight:700;color:#dc2626;"><?= $stats['expired'] ?></div><div style="font-size:12px;color:#64748b;">Expired</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:16px;">
<div>
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h3 style="margin:0;">LOA Records</h3>
        <button class="btn" onclick="document.getElementById('addLoaModal').style.display='flex';">+ Add LOA Record</button>
    </div>
    <form method="get" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <input type="hidden" name="view" value="<?= $viewId ?>">
        <select name="ay" style="font-size:12px;"><option value="">All AY</option>
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?>
            <option value="<?= $y ?>-<?= $y + 1 ?>" <?= $filterAY === "$y-" . ($y + 1) ? 'selected' : '' ?>><?= $y ?>-<?= $y + 1 ?></option>
            <?php endfor; ?>
        </select>
        <select name="sem" style="font-size:12px;"><option value="">All Sem</option>
            <option value="1" <?= $filterSem === '1' ? 'selected' : '' ?>>1st Semester</option>
            <option value="2" <?= $filterSem === '2' ? 'selected' : '' ?>>2nd Semester</option>
            <option value="summer" <?= $filterSem === 'summer' ? 'selected' : '' ?>>Summer</option>
        </select>
        <select name="status" style="font-size:12px;"><option value="">All Status</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="returned" <?= $filterStatus === 'returned' ? 'selected' : '' ?>>Returned</option>
            <option value="extended" <?= $filterStatus === 'extended' ? 'selected' : '' ?>>Extended</option>
            <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <select name="program" style="font-size:12px;"><option value="">All Programs</option>
            <?php foreach ($allPrograms as $p): ?>
            <option value="<?= h($p['programs_id']) ?>" <?= $filterProgram === $p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit" style="font-size:12px;">Filter</button>
    </form>

    <?php if ($loaRecords === []): ?>
    <p style="text-align:center;color:#94a3b8;padding:24px;">No LOA records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Student No.</th><th>Name</th><th>Program</th><th>Applied For</th><th>Effective Dates</th><th>Expected Return</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($loaRecords as $r): ?>
        <tr style="cursor:pointer;<?= $viewId === (int) $r['id'] ? 'background:var(--bg-secondary,#f0f4ff);' : '' ?>" onclick="location.href='?view=<?= (int) $r['id'] ?><?= $filterAY ? '&ay=' . h($filterAY) : '' ?><?= $filterSem ? '&sem=' . h($filterSem) : '' ?><?= $filterStatus ? '&status=' . h($filterStatus) : '' ?>'">
            <td><?= h($r['student_no']) ?></td>
            <td><?= h($r['full_name']) ?></td>
            <td style="font-size:12px;"><?= h($r['program_name']) ?></td>
            <td style="font-size:12px;"><?= get_semester_label($r['semester']) ?> <?= h($r['academic_year']) ?></td>
            <td style="font-size:12px;"><?= h(date('M j', strtotime($r['effective_date_from']))) ?>–<?= h(date('M j, Y', strtotime($r['effective_date_to']))) ?></td>
            <td style="font-size:12px;"><?= $r['expected_return_semester'] ? get_semester_label($r['expected_return_semester']) . ' ' . h($r['expected_return_academic_year'] ?? '') : '—' ?></td>
            <td><?php $bc = match($r['status']) { 'active' => 'badge warning', 'returned' => 'badge success', 'extended' => 'badge info', 'expired' => 'badge danger', 'cancelled' => 'badge', default => 'badge' }; ?>
                <span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst($r['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 8px;">Return Monitoring</h3>
    <form method="get" style="display:flex;gap:8px;margin-bottom:12px;">
        <input type="hidden" name="view" value="<?= $viewId ?>">
        <select name="mon_ay" style="font-size:12px;">
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 2; $y--): ?>
            <option value="<?= $y ?>-<?= $y + 1 ?>" <?= $currentAY === "$y-" . ($y + 1) ? 'selected' : '' ?>><?= $y ?>-<?= $y + 1 ?></option>
            <?php endfor; ?>
        </select>
        <select name="mon_sem" style="font-size:12px;">
            <option value="1" <?= $monitoringSem === '1' ? 'selected' : '' ?>>1st Semester</option>
            <option value="2" <?= $monitoringSem === '2' ? 'selected' : '' ?>>2nd Semester</option>
            <option value="summer" <?= $monitoringSem === 'summer' ? 'selected' : '' ?>>Summer</option>
        </select>
        <button class="btn" type="submit" style="font-size:12px;">View</button>
    </form>
    <div style="font-size:13px;margin-bottom:8px;">
        Expected Return: <strong><?= get_semester_label($monitoringSem) ?> <?= h($currentAY) ?></strong>
    </div>
    <div style="display:flex;gap:12px;margin-bottom:8px;font-size:12px;">
        <span>Total: <strong><?= $returnMonitoring['total'] ?></strong></span>
        <span style="color:#16a34a;">Returned: <strong><?= $returnMonitoring['returned'] ?></strong></span>
        <span style="color:#f59e0b;">Not Yet: <strong><?= $returnMonitoring['not_yet'] ?></strong></span>
    </div>
    <?php if ($returnMonitoring['rows'] !== []): ?>
    <div style="display:flex;flex-direction:column;gap:4px;max-height:300px;overflow-y:auto;">
        <?php foreach ($returnMonitoring['rows'] as $rm): ?>
        <a href="?view=<?= (int) $rm['id'] ?>" style="padding:8px;border:1px solid var(--line,#e5e7eb);border-radius:6px;text-decoration:none;color:inherit;font-size:12px;display:flex;justify-content:space-between;">
            <div><strong><?= h($rm['student_number']) ?></strong> <?= h($rm['full_name']) ?></div>
            <?php $bc = match($rm['status']) { 'returned' => 'badge success', 'extended' => 'badge info', default => 'badge warning' }; ?>
            <span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst($rm['status'])) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:#94a3b8;font-size:12px;text-align:center;padding:12px;">No students expected to return.</p>
    <?php endif; ?>
</div>
</div>

<div>
<?php if ($selectedLoa): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">LOA Record #<?= (int) $selectedLoa['id'] ?></h3>
    <div style="display:grid;grid-template-columns:140px 1fr;gap:6px;font-size:13px;">
        <span style="font-weight:600;">Student No.:</span><span><?= h($selectedLoa['student_no']) ?></span>
        <span style="font-weight:600;">Name:</span><span><?= h($selectedLoa['full_name']) ?></span>
        <span style="font-weight:600;">Program:</span><span><?= h($selectedLoa['program_name']) ?></span>
        <span style="font-weight:600;">Department:</span><span><?= h($selectedLoa['department_name'] ?? '—') ?></span>
        <span style="font-weight:600;">Date Filed:</span><span><?= h(date('F j, Y', strtotime($selectedLoa['date_filed']))) ?></span>
        <span style="font-weight:600;">Applied For:</span><span><?= get_semester_label($selectedLoa['semester']) ?> AY <?= h($selectedLoa['academic_year']) ?></span>
        <span style="font-weight:600;">Effective From:</span><span><?= h(date('F j, Y', strtotime($selectedLoa['effective_date_from']))) ?></span>
        <span style="font-weight:600;">Effective To:</span><span><?= h(date('F j, Y', strtotime($selectedLoa['effective_date_to']))) ?></span>
        <span style="font-weight:600;">Reason:</span><span><?= h($selectedLoa['reason'] ?: '—') ?></span>
        <span style="font-weight:600;">Expected Return:</span><span><?= $selectedLoa['expected_return_semester'] ? get_semester_label($selectedLoa['expected_return_semester']) . ' AY ' . h($selectedLoa['expected_return_academic_year'] ?? '') : '—' ?></span>
        <span style="font-weight:600;">Status:</span><span><?php $bc = match($selectedLoa['status']) { 'active' => 'badge warning', 'returned' => 'badge success', 'extended' => 'badge info', 'expired' => 'badge danger', 'cancelled' => 'badge', default => 'badge' }; ?><span class="<?= $bc ?>"><?= h(ucfirst($selectedLoa['status'])) ?></span></span>
        <span style="font-weight:600;">Encoded By:</span><span><?= h($selectedLoa['encoded_by'] ?? '—') ?></span>
        <span style="font-weight:600;">Encoded At:</span><span><?= h(date('F j, Y g:i A', strtotime($selectedLoa['encoded_at']))) ?></span>
        <?php if ($selectedLoa['returned_at']): ?>
        <span style="font-weight:600;">Returned At:</span><span><?= h(date('F j, Y g:i A', strtotime($selectedLoa['returned_at']))) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($selectedLoa['remarks']): ?>
    <div style="margin-top:8px;font-size:12px;background:var(--bg-secondary,#f8fafc);padding:8px;border-radius:6px;">
        <strong>Remarks:</strong><br><?= nl2br(h($selectedLoa['remarks'])) ?>
    </div>
    <?php endif; ?>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
    <?php if ($selectedLoa['status'] === 'active'): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_returned">
        <input type="hidden" name="loa_id" value="<?= $viewId ?>">
        <button class="btn success" type="submit" onclick="return confirm('Mark this student as returned? Status will be restored to ACTIVE.');">Mark as Returned</button>
    </form>
    <button class="btn" onclick="document.getElementById('extendModal').style.display='flex';">Extend LOA</button>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_loa">
        <input type="hidden" name="loa_id" value="<?= $viewId ?>">
        <button class="btn danger" type="submit" onclick="return confirm('Cancel this LOA? Student status will be restored to ACTIVE.');">Cancel LOA</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="margin:0 0 8px;">Remarks</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_remarks">
        <input type="hidden" name="loa_id" value="<?= $viewId ?>">
        <textarea name="remarks" rows="2" style="width:100%;margin-bottom:8px;" placeholder="Add remarks..."><?= h($selectedLoa['remarks'] ?? '') ?></textarea>
        <button class="btn" type="submit" style="font-size:12px;">Save Remarks</button>
    </form>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:48px;"><p style="color:#94a3b8;">Select an LOA record to view details.</p></div>
<?php endif; ?>
</div>
</div>

<div id="addLoaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div class="card" style="width:680px;max-height:90vh;overflow-y:auto;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;">Add LOA Record</h3>
        <button onclick="this.closest('[id$=Modal]').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;">&times;</button>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_loa">

        <div style="margin-bottom:12px;position:relative;">
            <label style="font-weight:600;font-size:13px;">Search Student *</label>
            <input type="text" id="loa_student_search" style="width:100%;box-sizing:border-box;" placeholder="Type student number, first name, or last name..." autocomplete="off">
            <div id="loa_student_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:1001;max-height:240px;overflow-y:auto;"></div>
            <input type="hidden" id="loa_student_no" name="student_no" value="">
            <input type="hidden" id="loa_student_id" name="student_id" value="0">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label style="font-size:13px;font-weight:600;">Name</label><input type="text" id="loa_student_name" readonly style="width:100%;background:#f1f5f9;"></div>
            <div><label style="font-size:13px;font-weight:600;">Program</label><input type="text" id="loa_student_program" readonly style="width:100%;background:#f1f5f9;"></div>
            <input type="hidden" id="loa_program_id" name="program_id" value="">
            <input type="hidden" id="loa_department_id" name="department_id" value="">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label style="font-size:13px;font-weight:600;">Date Filed *</label><input type="date" name="date_filed" value="<?= date('Y-m-d') ?>" style="width:100%;" required></div>
            <div></div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;">Applied For *</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <select name="semester" style="width:100%;" required>
                        <option value="">-- Semester --</option>
                        <option value="1">1st Semester</option>
                        <option value="2">2nd Semester</option>
                        <option value="summer">Summer</option>
                    </select>
                </div>
                <div>
                    <input type="text" name="academic_year" placeholder="e.g. 2026-2027" style="width:100%;" required>
                </div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;">Effective Dates *</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><label style="font-size:12px;">From</label><input type="date" name="effective_date_from" style="width:100%;" required></div>
                <div><label style="font-size:12px;">To</label><input type="date" name="effective_date_to" style="width:100%;" required></div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;">Reason</label>
            <textarea name="reason" rows="3" style="width:100%;" placeholder="Reason for leave..."></textarea>
        </div>

        <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;">Expected to Return to the University</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <select name="expected_return_semester" style="width:100%;">
                        <option value="">-- Semester --</option>
                        <option value="1">1st Semester</option>
                        <option value="2">2nd Semester</option>
                        <option value="summer">Summer</option>
                    </select>
                </div>
                <div>
                    <input type="text" name="expected_return_academic_year" placeholder="e.g. 2027-2028" style="width:100%;">
                </div>
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label style="font-weight:600;font-size:13px;">Remarks</label>
            <textarea name="remarks" rows="2" style="width:100%;" placeholder="Additional remarks..."></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn" onclick="this.closest('[id$=Modal]').style.display='none'">Cancel</button>
            <button class="btn" type="submit">Save LOA Record</button>
        </div>
    </form>
</div>
</div>

<?php if ($selectedLoa && $selectedLoa['status'] === 'active'): ?>
<div id="extendModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div class="card" style="width:480px;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;">Extend LOA</h3>
        <button onclick="this.closest('[id$=Modal]').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;">&times;</button>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="extend_loa">
        <input type="hidden" name="loa_id" value="<?= $viewId ?>">
        <div style="margin-bottom:12px;">
            <label style="font-size:13px;font-weight:600;">New Effective Date To *</label>
            <input type="date" name="new_effective_to" style="width:100%;" required>
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:13px;font-weight:600;">New Expected Return</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <select name="expected_return_semester" style="width:100%;">
                    <option value="">-- Semester --</option>
                    <option value="1">1st Semester</option>
                    <option value="2">2nd Semester</option>
                    <option value="summer">Summer</option>
                </select>
                <input type="text" name="expected_return_academic_year" placeholder="e.g. 2027-2028" style="width:100%;">
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn" onclick="this.closest('[id$=Modal]').style.display='none'">Cancel</button>
            <button class="btn" type="submit" onclick="return confirm('Extend LOA? A new LOA record will be created.');">Extend</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>

<script>
(function() {
    var input = document.getElementById('loa_student_search');
    var dropdown = document.getElementById('loa_student_dropdown');
    var timer = null;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        timer = setTimeout(function() {
            fetch('../api/student_search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || data.length === 0) {
                        dropdown.innerHTML = '<div style="padding:12px;color:#94a3b8;text-align:center;font-size:13px;">No students found</div>';
                        dropdown.style.display = 'block';
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < data.length; i++) {
                        var s = data[i];
                        html += '<div class="loa-student-option" data-id="' + s.id + '" data-sno="' + s.student_number + '" data-name="' + (s.full_name||'').replace(/"/g,'&quot;') + '" data-program="' + (s.program_name||'').replace(/"/g,'&quot;') + '" data-pid="' + (s.program_id||'') + '" data-did="' + (s.department_id||'') + '" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;">' +
                            '<strong>' + s.student_number + '</strong> &mdash; ' + (s.full_name||'') +
                            '<div style="font-size:11px;color:#64748b;">' + (s.program_name||'') + '</div></div>';
                    }
                    dropdown.innerHTML = html;
                    dropdown.style.display = 'block';
                    var opts = dropdown.querySelectorAll('.loa-student-option');
                    for (var j = 0; j < opts.length; j++) {
                        opts[j].addEventListener('click', function() {
                            document.getElementById('loa_student_id').value = this.dataset.id;
                            document.getElementById('loa_student_no').value = this.dataset.sno;
                            document.getElementById('loa_student_name').value = this.dataset.name;
                            document.getElementById('loa_student_program').value = this.dataset.program;
                            document.getElementById('loa_program_id').value = this.dataset.pid;
                            document.getElementById('loa_department_id').value = this.dataset.did;
                            input.value = this.dataset.sno + ' — ' + this.dataset.name;
                            dropdown.style.display = 'none';
                        });
                    }
                })
                .catch(function() { dropdown.style.display = 'none'; });
        }, 250);
    });

    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && e.target !== input) {
            dropdown.style.display = 'none';
        }
    });
})();
</script>
<?php
render_page('Leave of Absence', 'Leave of Absence', (string) ob_get_clean());
