<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$user = current_user();

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');
    $transfereeId = (int) ($_POST['transferee_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    if ($action === 'add_transferee') {
        $studentNumber = trim($_POST['student_number'] ?? '');
        $previousSchool = trim($_POST['previous_school'] ?? '');
        $previousProgram = trim($_POST['previous_program'] ?? '');
        $torReceived = trim($_POST['tor_received'] ?? 'pending');
        $hd = trim($_POST['honorable_dismissal'] ?? 'pending');

        $student = fetch_one('SELECT id FROM students WHERE student_number = :sn', ['sn' => $studentNumber]);
        if (!$student) {
            flash('error', 'Student not found.');
            redirect('registrar/transferee.php');
        }

        $tid = create_transferee_record((int) $student['id'], $previousSchool, $previousProgram, $torReceived, $hd, $remark);
        flash('success', 'Transferee record created.');
        redirect('registrar/transferee.php?view=' . $tid);
    }

    if ($action === 'add_subject' && $transfereeId > 0) {
        $code = trim($_POST['original_code'] ?? '');
        $name = trim($_POST['original_name'] ?? '');
        $units = (float) ($_POST['original_units'] ?? 0);
        $grade = trim($_POST['grade'] ?? '');
        $term = trim($_POST['term_taken'] ?? '');
        $equivId = (int) ($_POST['equivalent_subject_id'] ?? 0);

        if ($code !== '' && $name !== '') {
            add_transferee_subject($transfereeId, $code, $name, $units, $grade ?: null, $term ?: null, $equivId ?: null, null);
            flash('success', 'Subject added.');
        }
        redirect('registrar/transferee.php?view=' . $transfereeId);
    }

    if ($action === 'remove_subject' && $transfereeId > 0) {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        if ($subjectId > 0) {
            execute_sql('DELETE FROM transferee_subjects WHERE id = :id AND transferee_id = :tid', ['id' => $subjectId, 'tid' => $transfereeId]);
            flash('success', 'Subject removed.');
        }
        redirect('registrar/transferee.php?view=' . $transfereeId);
    }

    if ($action === 'process_transferee' && $transfereeId > 0) {
        $programId = (int) ($_POST['program_id'] ?? 0);
        $yearLevel = (int) ($_POST['year_level'] ?? 1);
        $standing = trim($_POST['standing'] ?? '');
        $enrollmentStatus = trim($_POST['enrollment_status'] ?? 'irregular');

        if ($programId > 0) {
            $currentTerm = current_term();
            $termId = $currentTerm ? (int) $currentTerm['id'] : 0;
            process_transferee($transfereeId, $programId, $termId, (int) $user['users_id'], $yearLevel, $standing ?: null, $enrollmentStatus, $remark);
            flash('success', 'Transferee processed. Student program and academic placement updated.');
            redirect('registrar/transferee.php');
        }
        flash('error', 'Please select a target program.');
        redirect('registrar/transferee.php?view=' . $transfereeId);
    }

    if ($action === 'reject' && $transfereeId > 0) {
        execute_sql(
            'UPDATE transferee_records SET evaluation_status = "rejected", evaluated_by = :uid, evaluated_at = NOW(), remarks = :remarks WHERE id = :id',
            ['uid' => (int) $user['users_id'], 'remarks' => $remark, 'id' => $transfereeId]
        );
        flash('success', 'Transferee record rejected.');
        redirect('registrar/transferee.php');
    }
}

$viewId = (int) ($_GET['view'] ?? 0);
$transferees = fetch_all(
    'SELECT tr.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name
     FROM transferee_records tr
     INNER JOIN students s ON s.id = tr.student_id
     ORDER BY FIELD(tr.evaluation_status, "pending", "approved", "rejected"), tr.created_at DESC'
);

$selectedTransferee = null;
$evaluation = [];
if ($viewId > 0) {
    $selectedTransferee = fetch_one(
        'SELECT tr.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
                s.program_id AS current_program_id
         FROM transferee_records tr
         INNER JOIN students s ON s.id = tr.student_id
         WHERE tr.id = :id',
        ['id' => $viewId]
    );
    if ($selectedTransferee) {
        $tsSubjects = get_transferee_subjects($viewId);
    }
}

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');
$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Transferee Processing</h1><p>Evaluate and process student transferee applications.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$selectedTransferee): ?>
<div style="margin-bottom:16px;">
    <form method="post" class="card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_transferee">
        <div><label>Student Number</label><input type="text" name="student_number" required placeholder="e.g. 2024-00123"></div>
        <div><label>Previous School</label><input type="text" name="previous_school" required placeholder="e.g. EVSU"></div>
        <div><label>Previous Program</label><input type="text" name="previous_program" placeholder="e.g. BSIT"></div>
        <div><label>TOR</label><select name="tor_received"><option value="pending">Pending</option><option value="received">Received</option></select></div>
        <div><label>Honorable Dismissal</label><select name="honorable_dismissal"><option value="pending">Pending</option><option value="received">Received</option></select></div>
        <button class="btn" type="submit">Add Transferee</button>
    </form>
</div>

<div class="card">
    <h3 style="margin:0 0 12px;">Transferee Records (<?= count($transferees) ?>)</h3>
    <?php if ($transferees === []): ?>
    <p style="text-align:center;color:#94a3b8;padding:24px;">No transferee records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Student</th><th>Previous School</th><th>Credentials</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($transferees as $tr): ?>
            <tr>
                <td><strong><?= h($tr['student_number']) ?></strong><br><span style="font-size:12px;color:#64748b;"><?= h($tr['full_name']) ?></span></td>
                <td><?= h($tr['previous_school']) ?></td>
                <td><span class="badge <?= $tr['transfer_credentials_status'] === 'complete' ? 'success' : 'warning' ?>"><?= h(ucfirst($tr['transfer_credentials_status'])) ?></span></td>
                <td><span class="badge <?= match($tr['evaluation_status']) { 'approved' => 'success', 'rejected' => 'danger', default => 'info' } ?>"><?= h(ucfirst($tr['evaluation_status'])) ?></span></td>
                <td><a href="?view=<?= (int) $tr['id'] ?>" class="btn" style="font-size:11px;padding:4px 10px;">View</a></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php else: ?>
<div style="margin-bottom:8px;"><a href="?" style="font-size:13px;color:var(--primary);">&larr; Back to list</a></div>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h3 style="margin:0 0 4px;"><?= h($selectedTransferee['student_number']) ?> &mdash; <?= h($selectedTransferee['full_name']) ?></h3>
            <div style="font-size:13px;color:#64748b;">Previous School: <?= h($selectedTransferee['previous_school']) ?><?= $selectedTransferee['previous_program'] ? ' — ' . h($selectedTransferee['previous_program']) : '' ?></div>
        </div>
        <span class="badge <?= match($selectedTransferee['evaluation_status']) { 'approved' => 'success', 'rejected' => 'danger', default => 'info' } ?>"><?= h(ucfirst($selectedTransferee['evaluation_status'])) ?></span>
    </div>
    <div style="margin-top:8px;display:flex;gap:12px;font-size:12px;color:#64748b;">
        <span>TOR: <span class="badge <?= $selectedTransferee['tor_received'] === 'received' ? 'success' : 'warning' ?>"><?= h(ucfirst($selectedTransferee['tor_received'])) ?></span></span>
        <span>Honorable Dismissal: <span class="badge <?= $selectedTransferee['honorable_dismissal'] === 'received' ? 'success' : 'warning' ?>"><?= h(ucfirst($selectedTransferee['honorable_dismissal'])) ?></span></span>
    </div>
</div>

<?php if (isset($tsSubjects)): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">Previous Subjects (<?= count($tsSubjects) ?>)</h3>

    <?php if ($selectedTransferee['evaluation_status'] === 'pending'): ?>
    <form method="post" style="margin-bottom:12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_subject">
        <input type="hidden" name="transferee_id" value="<?= $viewId ?>">
        <div><label>Code</label><input type="text" name="original_code" required style="width:100px;" placeholder="ENG101"></div>
        <div><label>Name</label><input type="text" name="original_name" required style="width:200px;" placeholder="English 1"></div>
        <div><label>Units</label><input type="number" name="original_units" step="0.5" min="0" value="3" style="width:60px;"></div>
        <div><label>Grade</label><input type="text" name="grade" style="width:60px;" placeholder="1.75"></div>
        <div><label>Term Taken</label><input type="text" name="term_taken" style="width:100px;" placeholder="2023-2024 1st"></div>
        <div><label>Equivalent CvSU Subject</label>
            <select name="equivalent_subject_id" style="width:200px;">
                <option value="">-- None --</option>
                <?php foreach ($programs as $pg): ?>
                <?php $subjs = fetch_all('SELECT subject_id, subject_code, subject_description FROM subjects WHERE subject_code LIKE :q OR subject_description LIKE :q2 ORDER BY subject_code LIMIT 20', ['q' => '%' . ($_POST['original_code'] ?? '') . '%', 'q2' => '%' . ($_POST['original_code'] ?? '') . '%']); ?>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit" style="font-size:12px;">Add Subject</button>
    </form>
    <?php endif; ?>

    <?php if ($tsSubjects === []): ?>
    <p style="text-align:center;color:#94a3b8;">No subjects added yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Code</th><th>Subject Name</th><th>Units</th><th>Grade</th><th>Term</th><th>Equivalent</th><th></th></tr></thead>
        <tbody><?php foreach ($tsSubjects as $ts): ?>
            <tr>
                <td><strong><?= h($ts['original_subject_code']) ?></strong></td>
                <td><?= h($ts['original_subject_name']) ?></td>
                <td><?= h(format_money($ts['original_units'])) ?></td>
                <td><?= $ts['grade'] ? h($ts['grade']) : '<span style="color:#94a3b8;">-</span>' ?></td>
                <td><?= $ts['term_taken'] ? h($ts['term_taken']) : '<span style="color:#94a3b8;">-</span>' ?></td>
                <td><?= $ts['equiv_code'] ? h($ts['equiv_code'] . ' - ' . $ts['equiv_desc']) : '<span style="color:#94a3b8;">Not mapped</span>' ?></td>
                <td>
                    <?php if ($selectedTransferee['evaluation_status'] === 'pending'): ?>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_subject">
                        <input type="hidden" name="transferee_id" value="<?= $viewId ?>">
                        <input type="hidden" name="subject_id" value="<?= (int) $ts['id'] ?>">
                        <button type="submit" class="btn danger" style="font-size:10px;padding:2px 6px;" onclick="return confirm('Remove?');">X</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($selectedTransferee['evaluation_status'] === 'pending'): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">Academic Placement</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="process_transferee">
        <input type="hidden" name="transferee_id" value="<?= $viewId ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label>Target Program</label>
                <select name="program_id" required style="width:100%;">
                    <option value="">-- Select --</option>
                    <?php foreach ($programs as $pg): ?>
                    <option value="<?= (int) $pg['programs_id'] ?>"><?= h($pg['program_code'] . ' - ' . $pg['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Year Level</label><select name="year_level"><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= $i ?><?= match($i) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?> Year</option><?php endfor; ?></select></div>
            <div><label>Standing</label><select name="standing"><option value="">-- Auto-detect --</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?><?= match($i) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?> Year Standing</option><?php endfor; ?></select></div>
            <div><label>Enrollment Status</label><select name="enrollment_status"><option value="regular">Regular</option><option value="irregular" selected>Irregular</option></select></div>
        </div>
        <div style="margin-bottom:12px;"><label>Remarks</label><textarea name="remark" rows="2" style="width:100%;" placeholder="Placement reason / notes..."></textarea></div>
        <div style="display:flex;gap:8px;">
            <button class="btn" type="submit" onclick="return confirm('Process this transferee?');">Process &amp; Place Student</button>
            <button class="btn danger" type="submit" name="action" value="reject" onclick="return confirm('Reject this transferee application?');">Reject Application</button>
        </div>
    </form>
</div>
<?php elseif ($selectedTransferee['evaluation_status'] === 'approved'): ?>
<div class="card" style="border-left:4px solid #16a34a;margin-bottom:16px;">
    <p style="color:#16a34a;font-weight:600;">This transferee has been processed and placed.</p>
</div>
<?php elseif ($selectedTransferee['evaluation_status'] === 'rejected'): ?>
<div class="card" style="border-left:4px solid #dc2626;margin-bottom:16px;">
    <p style="color:#dc2626;font-weight:600;">This transferee application was rejected.</p>
    <?php if ($selectedTransferee['remarks']): ?><p style="font-size:13px;"><strong>Reason:</strong> <?= h($selectedTransferee['remarks']) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php
render_page('Transferee Processing', 'Transferee Processing', (string) ob_get_clean());
