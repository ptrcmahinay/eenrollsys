<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'submit_loa') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        $category = trim($_POST['reason_category'] ?? '');
        $detail = trim($_POST['reason_detail'] ?? '');
        $returnTermId = (int) ($_POST['expected_return_term_id'] ?? 0) ?: null;

        $validCategories = ['medical','personal','family','financial','academic','work','other'];
        if (!in_array($category, $validCategories, true) || $termId <= 0) {
            flash('error', 'Please fill in all required fields.');
            redirect('student/loa.php');
        }

        $tid = create_loa_request((int) $student['id'], $termId, $category, $detail ?: null, $returnTermId);
        flash('success', 'LOA request submitted. Request #' . $tid);
        redirect('student/loa.php');
    }

    if ($action === 'cancel_loa') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        execute_sql(
            'UPDATE loa_requests SET workflow_status = "cancelled", updated_at = NOW() WHERE id = :id AND student_id = :sid AND workflow_status = "submitted"',
            ['id' => $requestId, 'sid' => (int) $student['id']]
        );
        flash('success', 'LOA request cancelled.');
        redirect('student/loa.php');
    }
}

$currentTerm = current_term();
$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid") DESC
     LIMIT 10'
);

$myRequests = fetch_all(
    'SELECT loa.*, t.semester, ay.year_label,
            rt.semester AS return_semester, ray.year_label AS return_year_label
     FROM loa_requests loa
     INNER JOIN academic_terms t ON t.id = loa.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     LEFT JOIN academic_terms rt ON rt.id = loa.expected_return_term_id
     LEFT JOIN academic_years ray ON ray.id = rt.academic_year_id
     WHERE loa.student_id = :sid
     ORDER BY loa.created_at DESC',
    ['sid' => (int) $student['id']]
);

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Leave of Absence</h1><p>Request a temporary leave from your studies.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (is_student_on_leave((int) $student['id'])): ?>
<div class="card" style="border-left:4px solid #f59e0b;margin-bottom:16px;">
    <p style="font-weight:600;color:#f59e0b;">You are currently on approved Leave of Absence.</p>
    <p style="font-size:13px;color:#64748b;">Enrollment is suspended until you process your return.</p>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">New LOA Request</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_loa">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label>Leave for Term *</label>
                <select name="term_id" required style="width:100%;">
                    <option value="">-- Select Term --</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= h($t['year_label'] . ' — ' . semester_label((string) $t['semester'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Reason Category *</label>
                <select name="reason_category" required style="width:100%;">
                    <option value="">-- Select --</option>
                    <option value="medical">Medical</option>
                    <option value="personal">Personal</option>
                    <option value="family">Family</option>
                    <option value="financial">Financial</option>
                    <option value="academic">Academic</option>
                    <option value="work">Work</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div style="grid-column:span 2;"><label>Reason / Details</label><textarea name="reason_detail" rows="3" style="width:100%;" placeholder="Please provide details about your reason for leave..."></textarea></div>
            <div><label>Expected Return Term</label>
                <select name="expected_return_term_id" style="width:100%;">
                    <option value="">-- Unknown --</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= h($t['year_label'] . ' — ' . semester_label((string) $t['semester'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn" type="submit" onclick="return confirm('Submit Leave of Absence request?');">Submit Request</button>
    </form>
</div>

<?php if ($myRequests !== []): ?>
<div class="card">
    <h3 style="margin:0 0 12px;">My LOA Requests</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Term</th><th>Reason</th><th>Expected Return</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($myRequests as $req): ?>
            <tr>
                <td><?= h($req['year_label'] . ' — ' . semester_label((string) $req['semester'])) ?></td>
                <td><?= h(ucfirst($req['reason_category'])) ?></td>
                <td><?= $req['return_year_label'] ? h($req['return_year_label'] . ' — ' . semester_label((string) $req['return_semester'])) : '<span style="color:#94a3b8;">—</span>' ?></td>
                <td><?php $bc = match($req['workflow_status']) { 'approved' => 'badge success', 'returned' => 'badge success', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge info' }; ?>
                    <span class="<?= $bc ?>"><?= h(ucfirst(str_replace('_', ' ', $req['workflow_status']))) ?></span></td>
                <td>
                    <?php if ($req['workflow_status'] === 'submitted'): ?>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_loa">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <button type="submit" class="btn danger" style="font-size:10px;padding:2px 6px;" onclick="return confirm('Cancel this LOA request?');">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
<?php
render_page('Leave of Absence', 'Leave of Absence', (string) ob_get_clean());
