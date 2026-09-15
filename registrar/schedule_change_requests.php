<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
$user = require_role(['admin', 'registrar']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('index.php');
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'approve_change_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $req = fetch_one(
                'SELECT scr.*, cs.status AS schedule_status
                 FROM schedule_change_requests scr
                 INNER JOIN class_schedules cs ON cs.id = scr.schedule_id
                 WHERE scr.id = :id AND scr.status = "pending"',
                ['id' => $requestId]
            );

            if ($req !== null && $req['schedule_status'] === 'approved') {
                $fieldMap = [
                    'room'          => 'room',
                    'instructor_id' => 'instructor_id',
                    'day'           => 'day',
                    'start_time'    => 'start_time',
                    'end_time'      => 'end_time',
                ];

                $dbField = $fieldMap[$req['field_changed']] ?? null;
                if ($dbField !== null) {
                    $newVal = in_array($req['field_changed'], ['instructor_id'], true) ? (int) $req['new_value'] : $req['new_value'];

                    if ($req['field_changed'] === 'start_time' || $req['field_changed'] === 'end_time') {
                        if ($req['field_changed'] === 'start_time') {
                            execute_sql(
                                'UPDATE class_schedules SET start_time = :val, time_range = CONCAT(:val, " – ", end_time) WHERE id = :id',
                                ['val' => $newVal, 'id' => (int) $req['schedule_id']]
                            );
                        } else {
                            execute_sql(
                                'UPDATE class_schedules SET end_time = :val, time_range = CONCAT(start_time, " – ", :val) WHERE id = :id',
                                ['val' => $newVal, 'id' => (int) $req['schedule_id']]
                            );
                        }
                    } else {
                        execute_sql(
                            'UPDATE class_schedules SET ' . $dbField . ' = :val WHERE id = :id',
                            ['val' => $newVal, 'id' => (int) $req['schedule_id']]
                        );
                    }
                }

                execute_sql(
                    'UPDATE schedule_change_requests SET status = "approved", reviewed_by = :staff_id, reviewed_at = NOW() WHERE id = :id',
                    ['staff_id' => (int) $staff['staff_id'], 'id' => $requestId]
                );
                flash('success', 'Change request approved and applied.');
            } else {
                flash('error', 'Request cannot be approved (schedule not found or not approved).');
            }
        }
        redirect('registrar/schedule_change_requests.php');
    }

    if ($action === 'reject_change_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            execute_sql(
                'UPDATE schedule_change_requests SET status = "rejected", reviewed_by = :staff_id, reviewed_at = NOW() WHERE id = :id AND status = "pending"',
                ['staff_id' => (int) $staff['staff_id'], 'id' => $requestId]
            );
            flash('success', 'Change request rejected.');
        }
        redirect('registrar/schedule_change_requests.php');
    }
}

$filterStatus = trim($_GET['status'] ?? '');
$validStatuses = ['pending', 'approved', 'rejected'];
if ($filterStatus !== '' && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

$sql = 'SELECT scr.*,
               cs.day AS sched_day, cs.start_time AS sched_time_start, cs.end_time AS sched_time_end, cs.room AS sched_room,
               cs.instructor_id AS sched_instructor_id, cs.schedule_code, cs.status AS schedule_status,
               sub.subject_code, sub.subject_description,
               st_inst.full_name AS current_instructor_name,
               st_req.full_name AS requested_by_name,
               st_rev.full_name AS reviewed_by_name,
               sec.section_name, sec.year_level,
               p.program_code
        FROM schedule_change_requests scr
        INNER JOIN class_schedules cs ON cs.id = scr.schedule_id
        INNER JOIN subjects sub ON sub.subject_id = cs.subject_id
        INNER JOIN sections sec ON sec.id = cs.section_id
        INNER JOIN programs p ON p.programs_id = sec.program_id
        LEFT JOIN staff st_inst ON st_inst.staff_id = cs.instructor_id
        LEFT JOIN staff st_req ON st_req.staff_id = scr.requested_by
        LEFT JOIN staff st_rev ON st_rev.staff_id = scr.reviewed_by
        WHERE 1=1';
$params = [];

if ($filterStatus !== '') {
    $sql .= ' AND scr.status = :status';
    $params['status'] = $filterStatus;
}

$sql .= ' ORDER BY FIELD(scr.status, "pending", "approved", "rejected"), scr.created_at DESC';
$requests = fetch_all($sql, $params);

$countRow = fetch_one(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_count
     FROM schedule_change_requests'
);
$totalCount = (int) ($countRow['total'] ?? 0);
$pendingCount = (int) ($countRow['pending_count'] ?? 0);
$approvedCount = (int) ($countRow['approved_count'] ?? 0);
$rejectedCount = (int) ($countRow['rejected_count'] ?? 0);

$fieldLabels = [
    'room' => 'Room',
    'instructor_id' => 'Instructor',
    'day' => 'Day',
    'start_time' => 'Time Start',
    'end_time' => 'Time End',
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Schedule Change Requests</h1>
        <p>Review and process schedule modification requests from department chairs.</p>
    </div>
</div>

<div class="grid" style="margin-bottom:16px;grid-template-columns:repeat(4,minmax(0,1fr));">
    <a class="card slim" href="<?= h(app_url('registrar/schedule_change_requests.php')) ?>" style="text-decoration:none;">
        <div class="metric-label">Total</div>
        <div class="metric"><?= h((string) $totalCount) ?></div>
    </a>
    <a class="card slim" href="<?= h(app_url('registrar/schedule_change_requests.php?status=pending')) ?>" style="text-decoration:none;<?= $filterStatus === 'pending' ? 'border:2px solid var(--warning);' : '' ?>">
        <div class="metric-label">Pending</div>
        <div class="metric" style="color:var(--warning);"><?= h((string) $pendingCount) ?></div>
    </a>
    <a class="card slim" href="<?= h(app_url('registrar/schedule_change_requests.php?status=approved')) ?>" style="text-decoration:none;<?= $filterStatus === 'approved' ? 'border:2px solid var(--success);' : '' ?>">
        <div class="metric-label">Approved</div>
        <div class="metric" style="color:var(--success);"><?= h((string) $approvedCount) ?></div>
    </a>
    <a class="card slim" href="<?= h(app_url('registrar/schedule_change_requests.php?status=rejected')) ?>" style="text-decoration:none;<?= $filterStatus === 'rejected' ? 'border:2px solid var(--danger);' : '' ?>">
        <div class="metric-label">Rejected</div>
        <div class="metric" style="color:var(--danger);"><?= h((string) $rejectedCount) ?></div>
    </a>
</div>

<div class="card">
    <?php if ($requests === []): ?>
        <p class="helper" style="text-align:center;padding:32px;">No schedule change requests found.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Section</th>
                    <th>Field</th>
                    <th>Current Value</th>
                    <th>Requested Value</th>
                    <th>Reason</th>
                    <th>Requested By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <?php
                    $currentVal = '';
                    $requestedVal = h($r['new_value']);

                    switch ($r['field_changed']) {
                        case 'room':
                            $currentVal = h($r['sched_room'] ?? '—');
                            break;
                        case 'instructor_id':
                            $currentVal = h($r['current_instructor_name'] ?? '—');
                            $requestedVal = h($r['new_value']);
                            break;
                        case 'day':
                            $currentVal = h($r['sched_day'] ?? '—');
                            break;
                        case 'start_time':
                            $currentVal = h($r['sched_time_start'] ? format_time_12h($r['sched_time_start']) : '—');
                            $requestedVal = h($r['new_value'] ? format_time_12h($r['new_value']) : $r['new_value']);
                            break;
                        case 'end_time':
                            $currentVal = h($r['sched_time_end'] ? format_time_12h($r['sched_time_end']) : '—');
                            $requestedVal = h($r['new_value'] ? format_time_12h($r['new_value']) : $r['new_value']);
                            break;
                    }
                ?>
                <tr>
                    <td><span class="badge" style="font-family:monospace;"><?= h($r['subject_code']) ?></span></td>
                    <td><?= h($r['program_code'] . ' ' . $r['year_level'] . $r['section_name']) ?></td>
                    <td><strong><?= h($fieldLabels[$r['field_changed']] ?? $r['field_changed']) ?></strong></td>
                    <td><?= $currentVal ?></td>
                    <td><strong><?= $requestedVal ?></strong></td>
                    <td><span class="helper" style="max-width:200px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($r['reason']) ?>"><?= h($r['reason']) ?></span></td>
                    <td><?= h($r['requested_by_name'] ?? '—') ?></td>
                    <td>
                        <span class="badge <?= h(schedule_status_badge_class($r['status'] === 'pending' ? 'submitted' : $r['status'])) ?>">
                            <?= h(ucfirst($r['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <div class="row-actions">
                            <form method="post" style="display:inline;" onsubmit="return confirm('Approve this change? It will be applied immediately.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_change_request">
                                <input type="hidden" name="request_id" value="<?= h((string) $r['id']) ?>">
                                <button class="btn small success" type="submit">Approve</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Reject this change request?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject_change_request">
                                <input type="hidden" name="request_id" value="<?= h((string) $r['id']) ?>">
                                <button class="btn small danger" type="submit">Reject</button>
                            </form>
                        </div>
                        <?php else: ?>
                            <span class="helper">Reviewed <?= $r['reviewed_at'] ? h(date('M j, Y', strtotime($r['reviewed_at']))) : '—' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
render_page('Schedule Change Requests', 'Schedule Change Requests', (string) ob_get_clean());
