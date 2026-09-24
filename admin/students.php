<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/forms.php';
require_role(['admin', 'registrar']);

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if (is_post() && ($_POST['action'] ?? '') === 'delete_student') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    if ($studentId > 0) {
        if (soft_delete('students', 'id', $studentId)) {
            flash('success', 'Student marked inactive.');
        } else {
            flash('error', 'Unable to deactivate student.');
        }
    }
    redirect('admin/students.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_delete_students') {
    $ids = $_POST['student_id'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        execute_sql("UPDATE students SET record_status = 'inactive' WHERE id IN ({$ph})", $ids);
        flash('success', count($ids) . ' student(s) marked inactive.');
    }
    redirect('admin/students.php');
}

$search = trim($_GET['q'] ?? '');
$filterProgram = trim($_GET['program'] ?? '');
$filterClassification = trim($_GET['classification'] ?? '');
$filterAcademicStatus = trim($_GET['academic_status'] ?? '');
$filterRecordStatus = trim($_GET['record_status'] ?? 'active');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(s.student_number LIKE :q OR CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) LIKE :q2)';
    $params['q'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
}
if ($filterProgram !== '') {
    $where[] = 's.program_id = :pid';
    $params['pid'] = $filterProgram;
}
if ($filterClassification !== '') {
    $where[] = 's.classification = :cls';
    $params['cls'] = $filterClassification;
}
if ($filterAcademicStatus !== '') {
    $where[] = 's.academic_status = :astatus';
    $params['astatus'] = $filterAcademicStatus;
}
if ($filterRecordStatus !== '') {
    $where[] = 's.record_status = :rstatus';
    $params['rstatus'] = $filterRecordStatus;
}

$whereSql = implode(' AND ', $where);

$countRow = fetch_one(
    "SELECT COUNT(*) AS cnt FROM students s WHERE {$whereSql}",
    $params
);
$totalRows = (int) ($countRow['cnt'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$term = current_term();

$students = fetch_all(
    "SELECT s.*, CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
            p.program_code, p.program_name, sec.section_name,
            CASE WHEN u.users_id IS NULL THEN 'No account' ELSE 'Has account' END AS account_status,
            sap.year_level AS placement_year, sap.standing AS placement_standing,
            sap.enrollment_status AS placement_enrollment,
            CASE WHEN er.id IS NOT NULL THEN er.workflow_status ELSE 'no_request' END AS enrollment_status
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN users u ON u.student_id = s.id
     LEFT JOIN student_academic_placements sap ON sap.id = (
         SELECT id FROM student_academic_placements WHERE student_id = s.id ORDER BY id DESC LIMIT 1
     )
     LEFT JOIN enrollment_requests er ON er.student_id = s.id AND er.term_id = :term_id
     LEFT JOIN (
         SELECT student_id, MAX(id) AS max_id FROM enrollment_requests WHERE term_id = :term_id2 GROUP BY student_id
     ) erlatest ON erlatest.student_id = s.id AND erlatest.max_id = er.id
     WHERE {$whereSql}
     ORDER BY s.student_number ASC
     LIMIT :limit OFFSET :offset",
    array_merge($params, ['term_id' => $term ? (int) $term['id'] : 0, 'term_id2' => $term ? (int) $term['id'] : 0, 'limit' => $perPage, 'offset' => $offset])
);

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');
$generatedNumber = generate_student_number();

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Directory</h1>
        <p>Central student records. Click a row to view the full student profile.</p>
    </div>
    <div class="actions-row">
        <button class="btn" data-open="studentModal">+ Add Student</button>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:200px;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:2px;">Search</label>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Student number or name..." style="width:100%;box-sizing:border-box;">
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:2px;">Program</label>
            <select name="program" style="font-size:12px;">
                <option value="">All Programs</option>
                <?php foreach ($programs as $p): ?>
                <option value="<?= h($p['programs_id']) ?>" <?= $filterProgram === $p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:2px;">Classification</label>
            <select name="classification" style="font-size:12px;">
                <option value="">All</option>
                <?php foreach (['New','Continuing','Transferee','Cross Enrollee','Shiftee','Returnee'] as $c): ?>
                <option value="<?= $c ?>" <?= $filterClassification === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:2px;">Academic Status</label>
            <select name="academic_status" style="font-size:12px;">
                <option value="">All</option>
                <?php foreach (['active','on_leave','graduated','transferred','withdrawn'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterAcademicStatus === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:2px;">Record Status</label>
            <select name="record_status" style="font-size:12px;">
                <option value="">All</option>
                <option value="active" <?= $filterRecordStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filterRecordStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button class="btn" type="submit" style="font-size:12px;">Search</button>
        <a href="students.php" class="btn secondary" style="font-size:12px;text-decoration:none;">Reset</a>
    </form>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:13px;color:#64748b;">Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?> students</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Year/Section</th>
                    <th>Classification</th>
                    <th>Standing</th>
                    <th>Academic Status</th>
                    <th>Record Status</th>
                    <th>Enrollment</th>
                    <th>Account</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($students === []): ?>
                <tr><td colspan="11" style="text-align:center;padding:24px;color:#94a3b8;">No students found.</td></tr>
            <?php else: ?>
                <?php foreach ($students as $student): ?>
                <tr data-href="<?= h(app_url('registrar/student_detail.php?student_id=' . $student['id'])) ?>">
                    <td><strong><?= h($student['student_number']) ?></strong></td>
                    <td><?= h($student['full_name']) ?></td>
                    <td style="font-size:12px;"><?= h($student['program_code']) ?></td>
                    <td style="font-size:12px;">
                        <?= (int) ($student['placement_year'] ?? $student['year_level']) ?>
                        <?= match((int) ($student['placement_year'] ?? $student['year_level'])) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?>
                        <?= $student['section_name'] ? ' / ' . h($student['section_name']) : '' ?>
                    </td>
                    <td style="font-size:12px;"><?= h($student['classification'] ?? '—') ?></td>
                    <td style="font-size:12px;"><?= h(ucfirst($student['placement_enrollment'] ?? $student['status'] ?? '—')) ?></td>
                    <td>
                        <?php $as = $student['academic_status'] ?? 'active';
                        $asBadge = match($as) {
                            'active' => 'badge success',
                            'on_leave' => 'badge warning',
                            'graduated' => 'badge info',
                            'transferred' => 'badge',
                            'withdrawn' => 'badge danger',
                            default => 'badge',
                        }; ?>
                        <span class="<?= $asBadge ?>" style="font-size:10px;"><?= h(ucfirst(str_replace('_', ' ', $as))) ?></span>
                    </td>
                    <td>
                        <?php $rs = $student['record_status'] ?? 'active';
                        $rsBadge = $rs === 'active' ? 'badge success' : 'badge danger'; ?>
                        <span class="<?= $rsBadge ?>" style="font-size:10px;"><?= h(ucfirst($rs)) ?></span>
                    </td>
                    <td>
                        <?php
                        $es = $student['enrollment_status'];
                        $enrBadge = match ($es) {
                            'no_request' => ['label' => 'No Request', 'class' => 'info'],
                            'no_active_term' => ['label' => '—', 'class' => 'info'],
                            'submitted' => ['label' => 'Submitted', 'class' => 'info'],
                            'adviser_approved' => ['label' => 'Adviser OK', 'class' => 'warning'],
                            'chair_approved' => ['label' => 'Chair OK', 'class' => 'warning'],
                            'registrar_approved' => ['label' => 'Enrolled', 'class' => 'success'],
                            'rejected' => ['label' => 'Rejected', 'class' => 'danger'],
                            'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
                            default => ['label' => ucfirst(str_replace('_', ' ', $es)), 'class' => 'info'],
                        };
                        ?>
                        <span class="badge <?= h($enrBadge['class']) ?>" style="font-size:10px;"><?= h($enrBadge['label']) ?></span>
                    </td>
                    <td style="font-size:11px;"><?= h($student['account_status']) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="icon-btn" title="View Profile" aria-label="View"
                               href="<?= h(app_url('registrar/student_detail.php?student_id=' . $student['id'])) ?>">
                                <span class="material-symbols-outlined">visibility</span>
                            </a>
                            <a class="icon-btn" title="Edit" aria-label="Edit"
                               href="<?= h(app_url('registrar/student_detail.php?student_id=' . $student['id'])) ?>">
                                <span class="material-symbols-outlined">edit</span>
                            </a>
                            <form class="inline-form" method="post"
                                  onsubmit="return confirm('Mark this student as inactive?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_student">
                                <input type="hidden" name="student_id" value="<?= h($student['id']) ?>">
                                <button class="icon-btn danger" type="submit" title="Deactivate" aria-label="Deactivate">
                                    <span class="material-symbols-outlined">person_off</span>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:4px;margin-top:12px;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>" class="btn secondary" style="font-size:12px;text-decoration:none;padding:4px 10px;">&laquo; Prev</a>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        if ($start > 1): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => 1]))) ?>" class="btn secondary" style="font-size:12px;text-decoration:none;padding:4px 8px;">1</a>
            <?php if ($start > 2): ?><span style="padding:4px 6px;color:#94a3b8;">...</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"
           class="btn <?= $i === $page ? '' : 'secondary' ?>" style="font-size:12px;text-decoration:none;padding:4px 8px;<?= $i === $page ? 'font-weight:700;' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span style="padding:4px 6px;color:#94a3b8;">...</span><?php endif; ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $totalPages]))) ?>" class="btn secondary" style="font-size:12px;text-decoration:none;padding:4px 8px;"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>" class="btn secondary" style="font-size:12px;text-decoration:none;padding:4px 10px;">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();

render_page('Student Directory', 'Students', (string) $content, [
    'modals' => [
        render_modal('studentModal', 'Add Student', render_student_form($programs, [], $generatedNumber), true)
    ]
]);
