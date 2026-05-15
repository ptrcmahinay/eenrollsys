<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('adviser');

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
                approve_request_as_adviser($requestId, $remark);
                flash('success', 'Enrollment request approved and forwarded to the department chair.');
            }
            if ($action === 'reject') {
                reject_request($requestId, 'adviser', $remark);
                flash('warning', 'Enrollment request rejected with adviser remark.');
            }
        } else {
            if ($action === 'approve') {
                approve_add_drop_as_adviser($requestId, $remark);
                flash('success', 'Add/drop request approved and forwarded to the department chair.');
            }
            if ($action === 'reject') {
                reject_add_drop_request($requestId, 'adviser', $remark);
                flash('warning', 'Add/drop request rejected with adviser remark.');
            }
        }
    }
    redirect('adviser/requests.php?type=' . $requestType);
}

$filterTab = trim($_GET['tab'] ?? 'pending');
if (!in_array($filterTab, ['pending', 'processed', 'all'], true)) $filterTab = 'pending';

if ($requestType === 'enrollment') {
    $statusFilter = match ($filterTab) {
        'pending' => ' AND er.workflow_status IN ("submitted")',
        'processed' => ' AND er.workflow_status IN ("adviser_approved", "rejected")',
        default => ' AND er.workflow_status IN ("submitted", "adviser_approved", "rejected")',
    };

    $requests = fetch_all(
        'SELECT er.*, s.student_number, s.full_name, s.id AS student_id, p.program_code, sec.section_name, ay.year_label, t.semester
         FROM enrollment_requests er
         INNER JOIN students s ON s.id = er.student_id
         INNER JOIN programs p ON p.programs_id = s.program_id
         LEFT JOIN sections sec ON sec.id = er.requested_section_id
         INNER JOIN academic_terms t ON t.id = er.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE (
                 er.requested_section_id IN (SELECT id FROM sections WHERE adviser_id = :adviser_id)
                 OR er.registrar_section_id IN (SELECT id FROM sections WHERE adviser_id = :adviser_id2)
               )' . $statusFilter . '
         ORDER BY er.updated_at DESC',
        ['adviser_id' => (int) $staff['staff_id'], 'adviser_id2' => (int) $staff['staff_id']]
    );

    $pendingCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_requests er
         INNER JOIN students s ON s.id = er.student_id
         WHERE er.workflow_status = "submitted" AND er.requested_section_id IN (SELECT id FROM sections WHERE adviser_id = :aid)',
        ['aid' => (int) $staff['staff_id']]
    )['cnt'] ?? 0);

    $processedCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_requests er
         INNER JOIN students s ON s.id = er.student_id
         WHERE er.workflow_status IN ("adviser_approved", "rejected") AND er.requested_section_id IN (SELECT id FROM sections WHERE adviser_id = :aid)',
        ['aid' => (int) $staff['staff_id']]
    )['cnt'] ?? 0);
} else {
    $statusFilter = match ($filterTab) {
        'pending' => ' AND adr.workflow_status IN ("submitted")',
        'processed' => ' AND adr.workflow_status IN ("adviser_approved", "rejected")',
        default => ' AND adr.workflow_status IN ("submitted", "adviser_approved", "rejected")',
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
         WHERE sec.adviser_id = :adviser_id' . $statusFilter . '
         ORDER BY adr.updated_at DESC',
        ['adviser_id' => (int) $staff['staff_id']]
    );

    $pendingCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM add_drop_requests adr
         INNER JOIN sections sec ON sec.id = adr.section_id
         WHERE adr.workflow_status = "submitted" AND sec.adviser_id = :aid',
        ['aid' => (int) $staff['staff_id']]
    )['cnt'] ?? 0);

    $processedCount = (int) (fetch_one(
        'SELECT COUNT(*) AS cnt FROM add_drop_requests adr
         INNER JOIN sections sec ON sec.id = adr.section_id
         WHERE adr.workflow_status IN ("adviser_approved", "rejected") AND sec.adviser_id = :aid',
        ['aid' => (int) $staff['staff_id']]
    )['cnt'] ?? 0);
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Adviser Enrollment Requests</h1>
        <p>Review student grades, detect failed prerequisites, and approve or reject enrollment requests.</p>
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
            <div class="page-header" style="margin-bottom:12px;">
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
                <div class="grid cols-2">
                    <div class="card slim">
                        <h4>Requested subjects</h4>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Eligibility</th></tr></thead>
                                <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php $eligibility = prerequisite_status_for_curriculum((int) $request['student_id'], $item); ?>
                                    <tr>
                                        <td><?= h($item['subject_code']) ?></td>
                                        <td><?= h($item['subject_description']) ?></td>
                                        <td><?= h($item['units']) ?></td>
                                        <td>
                                            <span class="badge <?= $eligibility['eligible'] ? 'success' : 'danger' ?>"><?= $eligibility['eligible'] ? 'Eligible' : h($eligibility['reason']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card slim">
                        <h4>Student grades</h4>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Term</th><th>Code</th><th>Description</th><th>Grade</th></tr></thead>
                                <tbody>
                                <?php
                                $grades = fetch_all(
                                    'SELECT sub.subject_code, sub.subject_description, ss.final_grade, ay.year_label, t.semester
                                     FROM student_subjects ss
                                     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
                                     INNER JOIN academic_terms t ON t.id = ss.term_id
                                     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
                                     WHERE ss.student_id = :student_id
                                     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
                                    ['student_id' => (int) $request['student_id']]
                                );
                                foreach ($grades as $grade): ?>
                                    <tr>
                                        <td><?= h($grade['year_label'] . ' / ' . semester_label((string) $grade['semester'])) ?></td>
                                        <td><?= h($grade['subject_code']) ?></td>
                                        <td><?= h($grade['subject_description']) ?></td>
                                        <td><span class="badge <?= grade_is_passing((string) $grade['final_grade']) ? 'success' : 'danger' ?>"><?= h($grade['final_grade'] ?: '-') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                <form method="post">
                    <input type="hidden" name="request_id" value="<?= h($request['id']) ?>">
                    <input type="hidden" name="type" value="<?= h($requestType) ?>">
                    <label><?= $requestType === 'enrollment' ? 'Enrollment' : 'Add/Drop' ?> remark</label>
                    <textarea name="remark" placeholder="Explain why the request is rejected or note adviser comments."><?= h($request['adviser_remark'] ?? '') ?></textarea>
                    <div class="form-actions">
                        <?php if ($request['workflow_status'] === 'submitted'): ?>
                            <button class="btn" type="submit" name="action" value="approve">Approve for Chair</button>
                            <button class="btn danger" type="submit" name="action" value="reject">Reject Request</button>
                        <?php else: ?>
                            <span class="badge info">Already processed by adviser</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
render_page('Adviser Requests', 'Enrollment Requests', (string) ob_get_clean());
