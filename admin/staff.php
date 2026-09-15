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
            execute_sql('UPDATE departments SET chair_id = NULL WHERE chair_id = :sid', ['sid' => $staffId]);
            execute_sql('UPDATE sections SET adviser_id = NULL WHERE adviser_id = :sid', ['sid' => $staffId]);
            execute_sql('UPDATE class_schedules SET instructor_id = NULL WHERE instructor_id = :sid', ['sid' => $staffId]);
            $staffUser = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => $staffId]);
            if ($staffUser) {
                execute_sql('DELETE FROM user_roles WHERE user_id = :uid', ['uid' => (int) $staffUser['users_id']]);
            }
            flash('success', 'Staff record marked inactive.');
        } else {
            flash('error', 'Unable to deactivate staff.');
        }
    }
    redirect('admin/staff.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'update_staff') {
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $employeeNumber = trim($_POST['employee_number'] ?? '');
    $deptId = !empty($_POST['dept_id']) ? (int) $_POST['dept_id'] : null;
    $roleIds = array_map('intval', $_POST['roles'] ?? []);

    if ($staffId <= 0 || $fullName === '' || $email === '') {
        flash('error', 'Name and email are required.');
    } else {
        $chairRole = fetch_one("SELECT roles_id FROM roles WHERE role_name = 'department_chair' LIMIT 1");
        $chairRoleId = $chairRole ? (int) $chairRole['roles_id'] : 0;

        if ($chairRoleId > 0 && in_array($chairRoleId, $roleIds) && $deptId !== null) {
            $existingChair = fetch_one(
                'SELECT s.staff_id
                 FROM staff s
                 INNER JOIN user_roles ur ON ur.user_id = s.users_id AND ur.role_id = :role_id
                 WHERE s.dept_id = :dept_id AND s.staff_id != :exclude_id AND COALESCE(s.status, \'active\') = \'active\'
                 LIMIT 1',
                ['role_id' => $chairRoleId, 'dept_id' => $deptId, 'exclude_id' => $staffId]
            );
            if ($existingChair !== null) {
                flash('error', 'This department already has a department chair assigned.');
                redirect('admin/staff.php');
            }
        }

        execute_sql(
            'UPDATE staff SET full_name = :full_name, email = :email, employee_number = :employee_number, dept_id = :dept_id WHERE staff_id = :staff_id',
            ['full_name' => $fullName, 'email' => $email, 'employee_number' => $employeeNumber, 'dept_id' => $deptId, 'staff_id' => $staffId]
        );

        $staffUser = fetch_one('SELECT users_id FROM staff WHERE staff_id = :staff_id', ['staff_id' => $staffId]);
        if ($staffUser) {
            execute_sql('UPDATE users SET email = :email WHERE users_id = :users_id', ['email' => $email, 'users_id' => (int) $staffUser['users_id']]);

            execute_sql('DELETE FROM user_roles WHERE user_id = :user_id', ['user_id' => (int) $staffUser['users_id']]);
            foreach ($roleIds as $roleId) {
                execute_sql(
                    'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
                    ['user_id' => (int) $staffUser['users_id'], 'role_id' => $roleId]
                );
            }

            if ($chairRoleId > 0) {
                if (in_array($chairRoleId, $roleIds) && $deptId !== null) {
                    execute_sql(
                        'UPDATE departments SET chair_id = :chair_id WHERE dept_id = :dept_id',
                        ['chair_id' => $staffId, 'dept_id' => $deptId]
                    );
                } elseif (in_array($chairRoleId, $roleIds) && $deptId === null) {
                    execute_sql(
                        'UPDATE departments SET chair_id = NULL WHERE chair_id = :chair_id',
                        ['chair_id' => $staffId]
                    );
                } else {
                    execute_sql(
                        'UPDATE departments SET chair_id = NULL WHERE chair_id = :chair_id',
                        ['chair_id' => $staffId]
                    );
                }
            }
        }

        flash('success', 'Staff updated successfully.');
    }
    redirect('admin/staff.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_delete_staff') {
    $ids = $_POST['staff_id'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        execute_sql("UPDATE staff SET status = 'inactive' WHERE staff_id IN ({$ph})", $ids);
        execute_sql("UPDATE departments SET chair_id = NULL WHERE chair_id IN ({$ph})", $ids);
        execute_sql("UPDATE sections SET adviser_id = NULL WHERE adviser_id IN ({$ph})", $ids);
        execute_sql("UPDATE class_schedules SET instructor_id = NULL WHERE instructor_id IN ({$ph})", $ids);
        foreach ($ids as $sid) {
            $su = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => (int) $sid]);
            if ($su) {
                execute_sql('DELETE FROM user_roles WHERE user_id = :uid', ['uid' => (int) $su['users_id']]);
            }
        }
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
        <a class="btn secondary" href="<?= h(app_url('instructor_list.php')) ?>">Instructors</a>
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
                    <tr data-dt-row-id="<?= h((string)$row['staff_id']) ?>"
                        data-href="<?= h(app_url('includes/detail.php?type=staff&id=' . $row['staff_id'])) ?>">
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
