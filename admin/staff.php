<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/forms.php';
require_role('admin');

if (is_post() && ($_POST['action'] ?? '') === 'delete_staff') {
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    if ($staffId > 0) {
        if (soft_delete('staff', 'staff_id', $staffId)) {
            flash('success', 'Staff record marked inactive.');
        } else {
            flash('error', 'Unable to deactivate staff.');
        }
    }
    redirect('admin/staff.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_delete_staff') {
    $ids = $_POST['staff_id'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        execute_sql("UPDATE staff SET status = 'inactive' WHERE staff_id IN ({$ph})", $ids);
        flash('success', count($ids) . ' staff record(s) deleted.');
    }
    redirect('admin/staff.php');
}

$staffRows = fetch_all(
    'SELECT st.*, COALESCE(st.status, "active") AS status, d.department_code,
            GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ", ") AS roles
     FROM staff st
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     LEFT JOIN user_roles ur ON ur.user_id = st.users_id
     LEFT JOIN roles r ON r.roles_id = ur.role_id
     GROUP BY st.staff_id
     ORDER BY st.full_name'
);

$staffRolesMap = [];
$rows = fetch_all(
    'SELECT ur.user_id, ur.role_id, st.staff_id
     FROM user_roles ur
     INNER JOIN staff st ON st.users_id = ur.user_id'
);
foreach ($rows as $r) {
    $staffRolesMap[$r['staff_id']][] = $r['role_id'];
}
$departments = fetch_all('SELECT dept_id, department_code, department_name FROM departments ORDER BY department_code');
$roles = fetch_all('SELECT roles_id, role_name FROM roles WHERE role_name IN ("admin", "registrar", "cashier", "department_chair", "adviser", "instructor") ORDER BY role_name');

$editModals = [];
foreach ($staffRows as $row) {
    $editModals[] = render_modal(
        'modal-edit-staff-' . $row['staff_id'],
        'Edit Staff',
        render_staff_edit_form(
            $departments,
            $roles,
            $row,
            $staffRolesMap[$row['staff_id']] ?? []
        )
    );
}
ob_start();
?>
<div class="page-header">
    <div>
        <h1>Staff Management</h1>
        <p>Create staff members with department assignment and role access.</p>
    </div>
    <div class="actions-row">
        <button class="btn" data-open="staffModal">Add Staff</button>
    </div>
</div>

<div class="card">
    <h3>Current staff list</h3>
    <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('admin/staff.php')) ?>" data-dt-bulk-id-field="staff_id" data-dt-bulk-action="bulk_delete_staff" data-dt-bulk-confirm="Delete selected staff records?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                        <th data-dt-key="employee">Employee No.</th>
                        <th data-dt-key="name">Name</th>
                        <th data-dt-key="email">Email</th>
                        <th data-dt-key="department" data-dt-filter="select">Department</th>
                        <th data-dt-key="roles" data-dt-filter="select">Roles</th>
                        <th data-dt-key="status" data-dt-filter="select">Status</th>
                        <th data-dt-no-sort>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staffRows as $row): ?>
                    <tr data-dt-row-id="<?= h((string)$row['staff_id']) ?>">
                        <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$row['staff_id']) ?>" aria-label="Select row"></td>
                        <td><?= h($row['employee_number']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['email']) ?></td>
                        <td><?= h($row['department_code'] ?: '-') ?></td>
                        <td><?= h($row['roles'] ?: '-') ?></td>
                        <td>
                            <span class="badge <?= $row['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= h(ucfirst((string) $row['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" title="View" aria-label="View"
                                   href="<?= h(app_url('includes/detail.php?type=staff&id=' . $row['staff_id'])) ?>">
                                    <span class="material-symbols-outlined">visibility</span>
                                </a>
                                <button class="icon-btn" type="button" title="Edit" aria-label="Edit"
                                        data-open="modal-edit-staff-<?= h($row['staff_id']) ?>">
                                    <span class="material-symbols-outlined">edit</span>
                                </button>
                                <form class="inline-form" method="post"
                                      onsubmit="return confirm('Mark this staff as inactive?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="staff_id" value="<?= h($row['staff_id']) ?>">
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

render_page('Staff Management', 'Staff', (string) $content, [
    'modals' => array_merge(
        [
            render_modal('staffModal', 'Add Staff Account', render_staff_form($departments, $roles))
        ],
        $editModals
    )
]);
