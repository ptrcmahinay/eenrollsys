<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/forms.php';
require_role('admin');

if (is_post() && ($_POST['action'] ?? '') === 'delete_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        if (soft_delete('users', 'users_id', $userId)) {
            flash('success', 'User account marked inactive.');
        } else {
            flash('error', 'Unable to deactivate user.');
        }
    }
    redirect('admin/users.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'bulk_delete_users') {
    $ids = $_POST['user_id'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        execute_sql("UPDATE users SET status = 'inactive' WHERE users_id IN ({$ph})", $ids);
        flash('success', count($ids) . ' user(s) deleted.');
    }
    redirect('admin/users.php');
}

$users = fetch_all(
    'SELECT u.users_id, u.username, u.email,
            COALESCE(u.status, "active") AS status,
            COALESCE(s.full_name, st.full_name, u.username, u.email) AS display_name,
            GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ", ") AS roles
     FROM users u
     LEFT JOIN students s ON s.id = u.student_id
     LEFT JOIN staff st ON st.users_id = u.users_id
     LEFT JOIN user_roles ur ON ur.user_id = u.users_id
     LEFT JOIN roles r ON r.roles_id = ur.role_id
     GROUP BY u.users_id
     ORDER BY display_name'
);
$roles = fetch_all('SELECT roles_id, role_name FROM roles ORDER BY role_name');

ob_start();
?>
<div class="page-header">
    <div>
        <h1>User Management</h1>
        <p>Create system accounts or reset passwords. Staff-specific accounts can also be created from the Staff page.</p>
    </div>
    <div class="actions-row">
        <button class="btn" data-open="userModal">Create User</button>
    </div>
</div>

<div class="card">
    <h3>Existing accounts</h3>
    <div class="dt" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('admin/users.php')) ?>" data-dt-bulk-id-field="user_id" data-dt-bulk-action="bulk_delete_users" data-dt-bulk-confirm="Delete selected users?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-key="name">Name</th>
                        <th data-dt-key="username">Username</th>
                        <th data-dt-key="email">Email</th>
                        <th data-dt-key="roles" data-dt-filter="select">Roles</th>
                        <th data-dt-key="status" data-dt-filter="select">Status</th>
                        <th data-dt-no-sort>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr data-dt-row-id="<?= h((string)$user['users_id']) ?>"
                        data-href="<?= h(app_url('includes/detail.php?type=user&id=' . $user['users_id'])) ?>">
                        <td><?= h($user['display_name']) ?></td>
                        <td><?= h($user['username']) ?></td>
                        <td><?= h($user['email']) ?></td>
                        <td><?= h($user['roles'] ?: '-') ?></td>
                        <td>
                            <span class="badge <?= $user['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= h(ucfirst((string) $user['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" title="View" aria-label="View"
                                   href="<?= h(app_url('includes/detail.php?type=user&id=' . $user['users_id'])) ?>">
                                    <span class="material-symbols-outlined">visibility</span>
                                </a>
                                <form class="inline-form" method="post"
                                      action="<?= h(app_url('admin/user_reset_password.php')) ?>"
                                      onsubmit="return confirm('Reset this user\'s password to the default?');"
                                      style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= h($user['users_id']) ?>">
                                    <input type="hidden" name="new_password" value="Password123!">
                                    <button class="icon-btn" type="submit" title="Reset password" aria-label="Reset password">
                                        <span class="material-symbols-outlined">lock_reset</span>
                                    </button>
                                </form>
                                <form class="inline-form" method="post"
                                      onsubmit="return confirm('Deactivate this user account?');"
                                      style="display:inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= h($user['users_id']) ?>">
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

render_page('User Management', 'Users', $content, [
    'modals' => [
        render_modal('userModal', 'Create User', render_user_form($roles))
    ]
]);
