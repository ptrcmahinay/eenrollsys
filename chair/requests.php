<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$requestType = trim($_GET['type'] ?? 'enrollment');
if (!in_array($requestType, ['enrollment', 'add_drop'], true)) $requestType = 'enrollment';

if (is_post()) {
    verify_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $remark = trim($_POST['remark'] ?? '');

    if ($requestId > 0) {
        if ($requestType === 'enrollment') {
            if ($action === 'approve') {
                $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
                if ($req !== null && request_deadline_passed($req, 'chair')) {
                    flash('error', 'The chair approval deadline for this request has passed.');
                    redirect('chair/requests.php?type=' . $requestType);
                }
                approve_request_as_chair($requestId, $remark);
                flash('success', 'Enrollment request approved by department chair and forwarded to registrar.');
            }
            if ($action === 'reject') {
                reject_request($requestId, 'chair', $remark);
                flash('warning', 'Enrollment request rejected by department chair.');
            }
        } else {
            if ($action === 'approve') {
                approve_add_drop_as_chair($requestId, $remark);
                flash('success', 'Add/drop request approved by department chair and forwarded to registrar.');
            }
            if ($action === 'reject') {
                reject_add_drop_request($requestId, 'chair', $remark);
                flash('warning', 'Add/drop request rejected by department chair.');
            }
        }
    }
    redirect('chair/requests.php?type=' . $requestType);
}

$filterTab = trim($_GET['tab'] ?? 'pending');
if (!in_array($filterTab, ['pending', 'processed', 'all'], true)) $filterTab = 'pending';

if ($requestType === 'enrollment') {
    $statusFilter = match ($filterTab) {
        'pending' => ' AND er.workflow_status IN ("adviser_approved")',
        'processed' => ' AND er.workflow_status IN ("chair_approved", "rejected")',
        default => ' AND er.workflow_status IN ("adviser_approved", "chair_approved", "rejected")',
    };

    $requests = fetch_all(
        'SELECT er.*, s.student_number, s.full_name, s.id AS student_id, p.program_code,
                ay.year_label, t.semester
         FROM enrollment_requests er
         INNER JOIN students s ON s.id = er.student_id
         INNER JOIN programs p ON p.programs_id = s.program_id
         INNER JOIN academic_terms t ON t.id = er.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE p.department_id = :department_id' . $statusFilter . '
         ORDER BY er.updated_at DESC',
        ['department_id' => (int) $staff['dept_id']]
    );

    $pendingCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_requests er INNER JOIN students s ON s.id = er.student_id INNER JOIN programs p ON p.programs_id = s.program_id WHERE p.department_id = :did AND er.workflow_status = "adviser_approved"',
        ['did' => (int) $staff['dept_id']]
    )['cnt'] ?? 0);

    $processedCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_requests er INNER JOIN students s ON s.id = er.student_id INNER JOIN programs p ON p.programs_id = s.program_id WHERE p.department_id = :did AND er.workflow_status IN ("chair_approved", "rejected")',
        ['did' => (int) $staff['dept_id']]
    )['cnt'] ?? 0);
} else {
    $statusFilter = match ($filterTab) {
        'pending' => ' AND adr.workflow_status IN ("adviser_approved")',
        'processed' => ' AND adr.workflow_status IN ("chair_approved", "rejected")',
        default => ' AND adr.workflow_status IN ("adviser_approved", "chair_approved", "rejected")',
    };

    $requests = fetch_all(
        'SELECT adr.*, sub.subject_code, sub.subject_description, sub.units AS subject_units,
                s.student_number, s.full_name, s.id AS student_id,
                p.program_code, sec.section_name,
                ay.year_label, t.semester
         FROM add_drop_requests adr
         INNER JOIN students s ON s.id = adr.student_id
         INNER JOIN programs p ON p.programs_id = s.program_id
         LEFT JOIN subjects sub ON sub.subject_id = adr.subject_id
         LEFT JOIN sections sec ON sec.id = adr.section_id
         INNER JOIN academic_terms t ON t.id = adr.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE p.department_id = :department_id' . $statusFilter . '
         ORDER BY adr.updated_at DESC',
        ['department_id' => (int) $staff['dept_id']]
    );

    $pendingCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM add_drop_requests adr INNER JOIN students s ON s.id = adr.student_id INNER JOIN programs p ON p.programs_id = s.program_id WHERE p.department_id = :did AND adr.workflow_status = "adviser_approved"',
        ['did' => (int) $staff['dept_id']]
    )['cnt'] ?? 0);

    $processedCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM add_drop_requests adr INNER JOIN students s ON s.id = adr.student_id INNER JOIN programs p ON p.programs_id = s.program_id WHERE p.department_id = :did AND adr.workflow_status IN ("chair_approved", "rejected")',
        ['did' => (int) $staff['dept_id']]
    )['cnt'] ?? 0);
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Department Chair Requests</h1>
        <p>Review requests after adviser approval. Once approved here, the final destination is the registrar.</p>
    </div>
</div>

<!-- Request Type Tabs -->
<div style="display:flex;gap:6px;margin-bottom:12px;border-bottom:1px solid var(--line);padding-bottom:10px;">
    <?php foreach ([['key' => 'enrollment', 'label' => '🎓 Enrollment'], ['key' => 'add_drop', 'label' => '✏️ Add/Drop']] as $rt): ?>
        <a href="?type=<?= h($rt['key']) ?>&tab=pending" style="display:inline-flex;align-items:center;gap:4px;padding:6px 16px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;
           background:<?= $requestType === $rt['key'] ? '#6366f1' : 'transparent' ?>;
           color:<?= $requestType === $rt['key'] ? '#fff' : 'var(--muted)' ?>;"><?= h($rt['label']) ?></a>
    <?php endforeach; ?>
</div>

<div style="display:flex;gap:6px;margin-bottom:16px;">
    <?php
    $tabs = [
        'pending' => ['label' => 'Pending', 'count' => $pendingCount],
        'processed' => ['label' => 'Processed', 'count' => $processedCount],
        'all' => ['label' => 'All', 'count' => $pendingCount + $processedCount],
    ];
    foreach ($tabs as $key => $tab):
        $active = $filterTab === $key;
    ?>
        <a href="?type=<?= h($requestType) ?>&tab=<?= h($key) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
           background:<?= $active ? '#6366f1' : 'var(--panel)' ?>;
           color:<?= $active ? '#fff' : 'var(--ink)' ?>;
           border:1px solid <?= $active ? '#6366f1' : 'var(--line)' ?>;">
            <?= h($tab['label']) ?>
            <?php if ($tab['count'] > 0): ?>
                <span style="background:<?= $active ? 'rgba(255,255,255,.25)' : 'var(--line)' ?>;border-radius:999px;padding:1px 7px;font-size:11px;"><?= $tab['count'] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($requests)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">inbox</span>
        <p class="helper">No requests found in this tab.</p>
    </div>
<?php endif; ?>

<div class="grid" style="gap:16px;">
    <?php foreach ($requests as $request): ?>
        <div class="card">
            <div class="page-header" style="margin-bottom: 10px;">
                <div>
                    <h3 style="margin:0;"><?= h($request['student_number'] . ' - ' . $request['full_name']) ?></h3>
                    <p><?= h($request['program_code']) ?> · <?= h($request['year_label']) ?> / <?= h(semester_label((string) $request['semester'])) ?>
                    <?php if ($requestType === 'add_drop'): ?>
                        · <?= $request['action_type'] === 'add' ? '➕ Add' : '➖ Drop' ?>: <?= h($request['subject_code'] ?: 'Subject #' . $request['subject_id']) ?>
                    <?php else: ?>
                        · Requested <?= h($request['requested_status']) ?> status
                    <?php endif; ?>
                    </p>
                </div>
                <div><span class="badge <?= h(workflow_badge_class((string) $request['workflow_status'])) ?>"><?= h(request_workflow_label((string) $request['workflow_status'])) ?></span></div>
            </div>

            <?php if ($requestType === 'enrollment'): ?>
                <?php $items = enrollment_request_items((int) $request['id']); ?>
                <?php $gradeSummary = fetch_all(
                    'SELECT sub.subject_code, ss.final_grade
                     FROM student_subjects ss
                     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
                     WHERE ss.student_id = :student_id AND ss.final_grade IS NOT NULL
                     ORDER BY ss.updated_at DESC LIMIT 8',
                    ['student_id' => (int) $request['student_id']]
                ); ?>
                <div class="grid cols-2">
                    <div class="card slim">
                        <h4>Requested subjects</h4>
                        <ul class="list-clean">
                            <?php foreach ($items as $item): ?>
                                <li><?= h($item['subject_code'] . ' - ' . $item['subject_description'] . ' (' . $item['units'] . ' units)') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="card slim">
                        <h4>Recent grade snapshot</h4>
                        <ul class="list-clean">
                            <?php foreach ($gradeSummary as $grade): ?>
                                <li><?= h($grade['subject_code']) ?> · <strong><?= h($grade['final_grade']) ?></strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <div class="card slim">
                    <h4>Add/Drop Details</h4>
                    <p><strong>Action:</strong> <?= $request['action_type'] === 'add' ? '➕ Add' : '➖ Drop' ?></p>
                    <p><strong>Subject:</strong> <?= h($request['subject_code'] . ' - ' . ($request['subject_description'] ?: 'N/A')) ?></p>
                    <p><strong>Units:</strong> <?= h($request['subject_units']) ?></p>
                    <p><strong>Section:</strong> <?= h($request['section_name'] ?: '-') ?></p>
                </div>
            <?php endif; ?>

            <div class="card slim" style="margin-top: 12px;">
                <div style="margin-bottom:8px;"><?= request_deadline_badge($request, 'chair') ?></div>
                <p><strong>Adviser remark:</strong> <?= h($request['adviser_remark'] ?: '-') ?></p>
                <form method="post">
                    <input type="hidden" name="request_id" value="<?= h($request['id']) ?>">
                    <input type="hidden" name="type" value="<?= h($requestType) ?>">
                    <label>Chair remark</label>
                    <textarea name="remark" placeholder="Optional chair remark."><?= h($request['chair_remark'] ?? '') ?></textarea>
                    <div class="form-actions">
                        <?php if ($request['workflow_status'] === 'adviser_approved'): ?>
                            <button class="btn" type="submit" name="action" value="approve" <?= request_deadline_passed($request, 'chair') ? 'disabled' : '' ?>>Approve for Registrar</button>
                            <button class="btn danger" type="submit" name="action" value="reject">Reject Request</button>
                        <?php else: ?>
                            <span class="badge info">Already processed by chair</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
render_page('Chair Requests', 'Enrollment Requests', (string) ob_get_clean());
