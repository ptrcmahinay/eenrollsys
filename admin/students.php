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
        execute_sql("UPDATE students SET status = 'inactive' WHERE id IN ({$ph})", $ids);
        flash('success', count($ids) . ' student(s) deleted.');
    }
    redirect('admin/students.php');
}

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');
$sections = fetch_all(
    'SELECT sec.id, CONCAT(p.program_code, " ", sec.year_level, sec.section_name) AS label
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     ORDER BY p.program_code, sec.year_level, sec.section_name'
);
$students = fetch_all(
    'SELECT s.*, p.program_code, sec.section_name,
            CASE WHEN u.users_id IS NULL THEN "No account" ELSE "Has account" END AS account_status
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN users u ON u.student_id = s.id
     ORDER BY s.student_number'
);
$term = current_term();

foreach ($students as &$student) {
    if ($term !== null) {
        $er = fetch_one(
            'SELECT workflow_status FROM enrollment_requests WHERE student_id = :sid AND term_id = :tid ORDER BY id DESC LIMIT 1',
            ['sid' => (int) $student['id'], 'tid' => (int) $term['id']]
        );
        $student['enrollment_status'] = $er !== null ? (string) $er['workflow_status'] : 'no_request';
    } else {
        $student['enrollment_status'] = 'no_active_term';
    }
}
unset($student);
$generatedNumber = generate_student_number();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Management</h1>
        <p>Create the student number and profile so the student can register or the admin can issue the portal account directly.</p>
    </div>
    <div class="actions-row">
        <button class="btn" data-open="studentModal">Add Student</button>
    </div>
</div>

<div class="card">
    <h3>Student records</h3>
    <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('admin/students.php')) ?>" data-dt-bulk-id-field="student_id" data-dt-bulk-action="bulk_delete_students" data-dt-bulk-confirm="Delete selected students?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                        <th data-dt-key="student_number">Student Number</th>
                        <th data-dt-key="name">Name</th>
                        <th data-dt-key="program" data-dt-filter="select">Program</th>
                        <th data-dt-key="year_section">Year/Section</th>
                        <th data-dt-key="address">Address</th>
                        <th data-dt-key="tuition">Tuition</th>
                        <?php if ($isAdmin): ?>
                            <th data-dt-key="account" data-dt-filter="select">Account</th>
                        <?php endif; ?>
                        <th data-dt-key="status" data-dt-filter="select">Status</th>
                        <th data-dt-key="enrollment" data-dt-filter="select">Enrollment</th>
                        <th data-dt-no-sort>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <?php $financial = financial_profile($student); ?>
                    <tr data-dt-row-id="<?= h((string)$student['id']) ?>">
                        <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$student['id']) ?>" aria-label="Select row"></td>
                        <td><strong><?= h($student['student_number']) ?></strong></td>
                        <td><?= h($student['full_name']) ?></td>
                        <td><?= h($student['program_code']) ?></td>
                        <td><?= h($student['year_level'] . ($student['section_name'] ?: '')) ?></td>
                        <td><?= h($student['address']) ?></td>
                        <!-- <td>
                            <?= h($financial['label']) ?>
                        </td> -->
                        <td data-dt-value="<?= h($financial['label']) ?>">
                            <span class="badge <?= in_array($financial['status'], ['free'], true) ? 'success' : 'warning' ?>">
                                <?= h($financial['label']) ?>
                            </span>
                        </td>
                        <?php if ($isAdmin): ?>
                            <td><?= h($student['account_status']) ?></td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= $student['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= h(ucfirst((string) ($student['status'] ?? 'active'))) ?>
                            </span>
                        </td>
                        <td data-dt-value="<?= h($student['enrollment_status']) ?>">
                            <?php
                            $es = $student['enrollment_status'];
                            $enrBadge = match ($es) {
                                'no_request' => ['label' => 'No Request', 'class' => 'info'],
                                'no_active_term' => ['label' => 'No Active Term', 'class' => 'info'],
                                'submitted' => ['label' => 'Submitted', 'class' => 'info'],
                                'adviser_approved' => ['label' => 'Adviser OK', 'class' => 'warning'],
                                'chair_approved' => ['label' => 'Chair OK', 'class' => 'warning'],
                                'registrar_approved' => ['label' => 'Enrolled', 'class' => 'success'],
                                'rejected' => ['label' => 'Rejected', 'class' => 'danger'],
                                'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
                                default => ['label' => ucfirst(str_replace('_', ' ', $es)), 'class' => 'info'],
                            };
                            ?>
                            <span class="badge <?= h($enrBadge['class']) ?>"><?= h($enrBadge['label']) ?></span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" title="View" aria-label="View"
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
                                    <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

render_page('Student Management', 'Students', (string) $content, [
    'modals' => [
        render_modal('studentModal', 'Add Student', render_student_form($programs, $sections, $generatedNumber), true)
    ]
]);
