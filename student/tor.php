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

    if ($action === 'submit_tor') {
        $purpose = trim($_POST['purpose'] ?? '');
        $purposeDetail = trim($_POST['purpose_detail'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $copies = max(1, (int) ($_POST['copies'] ?? 1));

        $validPurposes = ['employment','transfer','further_studies','scholarship','board_exam','personal','immigration','other'];
        if (!in_array($purpose, $validPurposes, true)) {
            flash('error', 'Invalid purpose selected.');
            redirect('student/tor.php');
        }

        $tid = create_tor_request((int) $student['id'], $purpose, $purposeDetail ?: null, $destination ?: null, $copies);
        flash('success', 'TOR request submitted. Request #' . $tid);
        redirect('student/tor.php');
    }

    if ($action === 'cancel_tor') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        execute_sql(
            'UPDATE tor_requests SET workflow_status = "cancelled", updated_at = NOW() WHERE id = :id AND student_id = :sid AND workflow_status IN ("pending")',
            ['id' => $requestId, 'sid' => (int) $student['id']]
        );
        flash('success', 'TOR request cancelled.');
        redirect('student/tor.php');
    }
}

$myRequests = fetch_all(
    'SELECT * FROM tor_requests WHERE student_id = :sid ORDER BY created_at DESC',
    ['sid' => (int) $student['id']]
);

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Transcript of Records</h1><p>Request your official academic transcript.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">New TOR Request</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_tor">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label>Purpose *</label>
                <select name="purpose" required style="width:100%;">
                    <option value="">-- Select Purpose --</option>
                    <option value="employment">Employment</option>
                    <option value="transfer">Transfer to another school</option>
                    <option value="further_studies">Further studies</option>
                    <option value="scholarship">Scholarship</option>
                    <option value="board_exam">Board examination</option>
                    <option value="personal">Personal use</option>
                    <option value="immigration">Immigration / Visa</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div id="purposeDetailGroup" style="display:none;"><label>Please specify</label><input type="text" name="purpose_detail" style="width:100%;" placeholder="Specify purpose..."></div>
            <div><label>Destination / Recipient</label><input type="text" name="destination" style="width:100%;" placeholder="e.g. Company ABC, EVSU"></div>
            <div><label>Number of Copies</label><input type="number" name="copies" value="1" min="1" max="10" style="width:80px;"></div>
        </div>
        <button class="btn" type="submit">Submit Request</button>
    </form>
</div>

<script>
document.querySelector('select[name="purpose"]').addEventListener('change', function() {
    document.getElementById('purposeDetailGroup').style.display = this.value === 'other' ? 'block' : 'none';
});
</script>

<?php if ($myRequests !== []): ?>
<div class="card">
    <h3 style="margin:0 0 12px;">My TOR Requests</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Purpose</th><th>Date</th><th>Document #</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($myRequests as $req): ?>
            <tr>
                <td><?= (int) $req['id'] ?></td>
                <td><?= h(ucfirst(str_replace('_', ' ', $req['purpose']))) ?><?= $req['purpose_detail'] ? ' — ' . h($req['purpose_detail']) : '' ?></td>
                <td><?= h(date('M j, Y', strtotime($req['created_at']))) ?></td>
                <td><?= $req['document_number'] ? h($req['document_number']) : '<span style="color:#94a3b8;">—</span>' ?></td>
                <td><?php $bc = match($req['workflow_status']) { 'released' => 'badge success', 'ready_for_release' => 'badge info', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge warning' }; ?>
                    <span class="<?= $bc ?>"><?= h(ucfirst(str_replace('_', ' ', $req['workflow_status']))) ?></span></td>
                <td>
                    <?php if ($req['workflow_status'] === 'pending'): ?>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_tor">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <button type="submit" class="btn danger" style="font-size:10px;padding:2px 6px;" onclick="return confirm('Cancel this TOR request?');">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
<?php
render_page('TOR Request', 'TOR Request', (string) ob_get_clean());
