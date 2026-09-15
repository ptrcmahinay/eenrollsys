<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_role(['admin', 'registrar']);

/* -----------------------------------------------------------------------
 * POST handlers
 * --------------------------------------------------------------------- */
if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_department') {
        $code = trim($_POST['department_code'] ?? '');
        $name = trim($_POST['department_name'] ?? '');
        if ($code === '' || $name === '') {
            flash('error', 'Department code and name are required.');
        } else {
            execute_sql(
                'INSERT INTO departments (department_code, department_name, status, created_at)
                 VALUES (:code, :name, "active", NOW())',
                ['code' => $code, 'name' => $name]
            );
            flash('success', 'Department created.');
        }
    }

    if ($action === 'update_department') {
        $deptId = (int) ($_POST['dept_id'] ?? 0);
        $code   = trim($_POST['department_code'] ?? '');
        $name   = trim($_POST['department_name'] ?? '');
        $chairId = !empty($_POST['chair_id']) ? (int) $_POST['chair_id'] : null;

        if ($deptId <= 0 || $code === '' || $name === '') {
            flash('error', 'Invalid department data.');
        } else {
            if ($chairId !== null) {
                $otherDept = fetch_one(
                    'SELECT dept_id FROM departments WHERE chair_id = :chair_id AND dept_id != :dept_id AND status = "active" LIMIT 1',
                    ['chair_id' => $chairId, 'dept_id' => $deptId]
                );
                if ($otherDept !== null) {
                    flash('error', 'This staff member is already assigned as chair of another department.');
                    redirect('registrar/departments.php');
                }
            }

            execute_sql(
                'UPDATE departments SET department_code = :code, department_name = :name WHERE dept_id = :id',
                ['code' => $code, 'name' => $name, 'id' => $deptId]
            );

            $currentDept = fetch_one('SELECT chair_id FROM departments WHERE dept_id = :dept_id', ['dept_id' => $deptId]);
            $oldChairId = $currentDept ? (int) $currentDept['chair_id'] : null;

            $chairRole = fetch_one("SELECT roles_id FROM roles WHERE role_name = 'department_chair' LIMIT 1");
            $chairRoleId = $chairRole ? (int) $chairRole['roles_id'] : 0;

            if ($chairRoleId > 0) {
                if ($oldChairId !== null && $oldChairId !== $chairId) {
                    $oldUser = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => $oldChairId]);
                    if ($oldUser) {
                        execute_sql(
                            'DELETE FROM user_roles WHERE user_id = :uid AND role_id = :rid',
                            ['uid' => (int) $oldUser['users_id'], 'rid' => $chairRoleId]
                        );
                    }
                }

                if ($chairId !== null) {
                    $newUser = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => $chairId]);
                    if ($newUser) {
                        $hasRole = fetch_one(
                            'SELECT 1 FROM user_roles WHERE user_id = :uid AND role_id = :rid LIMIT 1',
                            ['uid' => (int) $newUser['users_id'], 'rid' => $chairRoleId]
                        );
                        if (!$hasRole) {
                            execute_sql(
                                'INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)',
                                ['uid' => (int) $newUser['users_id'], 'rid' => $chairRoleId]
                            );
                        }
                    }
                }
            }

            if ($chairId !== null) {
                execute_sql(
                    'UPDATE staff SET dept_id = NULL WHERE dept_id = :dept_id AND staff_id != :chair_id',
                    ['dept_id' => $deptId, 'chair_id' => $chairId]
                );
                execute_sql(
                    'UPDATE staff SET dept_id = :dept_id WHERE staff_id = :chair_id',
                    ['dept_id' => $deptId, 'chair_id' => $chairId]
                );
            }
            execute_sql(
                'UPDATE departments SET chair_id = :chair_id WHERE dept_id = :dept_id',
                ['chair_id' => $chairId, 'dept_id' => $deptId]
            );

            flash('success', 'Department updated.');
        }
    }

    if ($action === 'delete_department') {
        $deptId = (int) ($_POST['dept_id'] ?? 0);
        if ($deptId > 0) {
            if (soft_delete('departments', 'dept_id', $deptId)) {
                execute_sql('UPDATE departments SET chair_id = NULL WHERE dept_id = :did', ['did' => $deptId]);
                execute_sql('UPDATE staff SET dept_id = NULL WHERE dept_id = :did', ['did' => $deptId]);
                flash('success', 'Department marked inactive.');
            } else {
                flash('error', 'Failed to delete department.');
            }
        }
    }

    if ($action === 'bulk_delete_departments') {
        $ids = $_POST['dept_id'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            execute_sql("UPDATE departments SET status = 'inactive' WHERE dept_id IN ({$ph})", $ids);
            execute_sql("UPDATE departments SET chair_id = NULL WHERE dept_id IN ({$ph})", $ids);
            execute_sql("UPDATE staff SET dept_id = NULL WHERE dept_id IN ({$ph})", $ids);
            flash('success', count($ids) . ' department(s) deleted.');
        }
    }

    redirect('registrar/departments.php');
}

/* -----------------------------------------------------------------------
 * Data
 * --------------------------------------------------------------------- */
// Departments with their chair (staff who has role 'chair' and dept_id matches)
$departments = fetch_all(
    'SELECT d.*,
            s.full_name AS chair_name,
            d.chair_id,
            (SELECT COUNT(*) FROM programs p WHERE p.department_id = d.dept_id) AS program_count
     FROM departments d
     LEFT JOIN staff s ON s.staff_id = d.chair_id
     WHERE d.status = "active"
     ORDER BY d.department_code'
);

$allStaffList = fetch_all(
    'SELECT staff_id, full_name, dept_id 
     FROM staff 
     WHERE COALESCE(status, \'active\') = \'active\'
     ORDER BY full_name'
);
$existingChairMap = [];
$chairRows = fetch_all('SELECT dept_id, chair_id FROM departments WHERE chair_id IS NOT NULL AND status = "active"');
foreach ($chairRows as $cr) {
    $existingChairMap[(int) $cr['chair_id']] = (int) $cr['dept_id'];
}
$allStaffJson = json_encode($allStaffList);
$chairMapJson = json_encode($existingChairMap);
/* -----------------------------------------------------------------------
 * Modals
 * --------------------------------------------------------------------- */
$deptModalBody = '
<form method="post">
    <input type="hidden" name="action" value="create_department">
    <div class="form-grid">
        <div>
            <label>Department Code</label>
            <input type="text" name="department_code" placeholder="e.g. ITD" required maxlength="20">
        </div>
        <div>
            <label>Department Name</label>
            <input type="text" name="department_name" placeholder="e.g. Information Technology Dept." required maxlength="100">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Create Department</button>
    </div>
</form>';

$editDeptModalBody = '
<form method="post">
    <input type="hidden" name="action" value="update_department">
    <input type="hidden" name="dept_id" id="edit_dept_id">

    <div class="form-grid">
        <div>
            <label>Department Code</label>
            <input type="text" name="department_code" id="edit_department_code" required>
        </div>
        <div>
            <label>Department Name</label>
            <input type="text" name="department_name" id="edit_department_name" required>
        </div>

        <div>
            <label>Department Chair</label>
            <select name="chair_id" id="edit_chair_id">
                <option value="">— Select Chair —</option>
            </select>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Save Changes</button>
    </div>
</form>';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Departments</h1>
        <p>Manage all departments.</p>
    </div>
</div>

<div class="grid cols-1" style="align-items: start;">

    <!-- ── DEPARTMENTS ── -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0;">Departments</h3>
            <button class="btn small" data-open="modal-new-dept">+ New Department</button>
        </div>
        <div class="dt modern-table" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('registrar/departments.php')) ?>" data-dt-bulk-id-field="dept_id" data-dt-bulk-action="bulk_delete_departments" data-dt-bulk-confirm="Delete selected departments?">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th data-dt-key="code">Code</th>
                            <th data-dt-key="name">Department</th>
                            <th data-dt-key="chair">Chair</th>
                            <th data-dt-key="programs">Programs</th>
                            <th data-dt-no-sort>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($departments)): ?>
                        <tr><td colspan="6" class="empty">No departments yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($departments as $dept): ?>
                        <tr data-dt-row-id="<?= h((string)$dept['dept_id']) ?>" class="table-row"
                            data-href="<?= h(app_url('registrar/department_detail.php?id=' . $dept['dept_id'])) ?>">
                            <td data-label="Code">
                                <div class="table-cell-primary">
                                    <div class="cell-icon dept-icon"><span class="material-symbols-outlined">domain</span></div>
                                    <div>
                                        <strong><?= h($dept['department_code']) ?></strong>
                                        <span class="cell-sub"><?= h($dept['department_name']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Department" class="table-cell-text"><?= h($dept['department_name']) ?></td>
                            <td data-label="Chair">
                                <?php if ($dept['chair_name']): ?>
                                    <span class="table-badge chair-badge"><?= h($dept['chair_name']) ?></span>
                                <?php else: ?>
                                    <span class="helper">— no chair</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Programs">
                                <span class="table-badge count-badge"><?= (int) $dept['program_count'] ?></span>
                            </td>
                            <td data-label="Actions">
                                <div class="row-actions">
                                    <a class="action-btn" title="View" aria-label="View"
                                       href="<?= h(app_url('registrar/department_detail.php?id=' . $dept['dept_id'])) ?>">
                                        <span class="material-symbols-outlined">visibility</span>
                                    </a>
                                    <button class="action-btn" type="button" title="Edit" aria-label="Edit"
                                            data-open="modal-edit-dept"
                                            data-id="<?= h($dept['dept_id']) ?>"
                                            data-code="<?= h($dept['department_code']) ?>"
                                            data-name="<?= h($dept['department_name']) ?>"
                                            data-chair="<?= h((string)($dept['chair_id'] ?? '')) ?>">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <form class="inline-form" method="post"
                                          onsubmit="return confirm('Mark this department as inactive?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_department">
                                        <input type="hidden" name="dept_id" value="<?= h($dept['dept_id']) ?>">
                                        <button class="action-btn danger" type="submit" title="Delete" aria-label="Delete">
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

</div>
<script>
const allStaff = <?= $allStaffJson ?>;
const chairMap = <?= $chairMapJson ?>;

document.addEventListener('click', function(e) {
    const deptBtn = e.target.closest('[data-open="modal-edit-dept"]');
    if (deptBtn) {
        const deptId = parseInt(deptBtn.dataset.id);
        const currentChairId = deptBtn.dataset.chair || '';

        document.getElementById('edit_dept_id').value = deptBtn.dataset.id;
        document.getElementById('edit_department_code').value = deptBtn.dataset.code;
        document.getElementById('edit_department_name').value = deptBtn.dataset.name;

        const select = document.getElementById('edit_chair_id');
        select.innerHTML = '<option value="">— Select Chair —</option>';
        allStaff.forEach(function(s) {
            const opt = document.createElement('option');
            opt.value = s.staff_id;
            let label = s.full_name;
            let disabled = false;
            if (chairMap[s.staff_id] !== undefined && chairMap[s.staff_id] !== deptId) {
                label += ' (already chair of another dept)';
                disabled = true;
            }
            opt.textContent = label;
            opt.disabled = disabled;
            if (String(s.staff_id) === currentChairId) {
                opt.selected = true;
                opt.disabled = false;
            }
            select.appendChild(opt);
        });
    }
});
</script>
<?= render_modal('modal-new-dept',      'New Department',    $deptModalBody) ?>
<?= render_modal('modal-edit-dept',     'Edit Department',   $editDeptModalBody) ?>

<?php
$deptStyles = '<style>
.modern-table table { border-collapse: separate; border-spacing: 0; width: 100%; }
.modern-table thead th {
    background: #f8fafc; border-bottom: 2px solid #e2e8f0;
    padding: 12px 16px; font-size: 12px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.05em; color: #64748b; position: sticky; top: 0; z-index: 1;
}
.modern-table tbody tr { transition: all 0.15s ease; }
.modern-table tbody tr:hover { background: #f1f5f9; }
.modern-table tbody td {
    padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
    font-size: 14px; color: #334155; vertical-align: middle;
}
.table-cell-primary { display: flex; align-items: center; gap: 12px; }
.cell-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; flex-shrink: 0;
}
.cell-icon.dept-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.cell-icon .material-symbols-outlined { font-size: 20px; }
.cell-sub { display: block; font-size: 12px; color: #94a3b8; font-weight: 400; margin-top: 2px; }
.table-cell-text { color: #475569; }
.table-badge {
    display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}
.chair-badge { background: #fef3c7; color: #d97706; }
.count-badge { background: #f1f5f9; color: #475569; }
.row-actions { display: flex; gap: 4px; }
.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border: none; border-radius: 8px;
    background: transparent; cursor: pointer; transition: all 0.15s ease;
    color: #64748b; text-decoration: none;
}
.action-btn:hover { background: #f1f5f9; color: #334155; }
.action-btn.danger:hover { background: #fef2f2; color: #ef4444; }
.action-btn .material-symbols-outlined { font-size: 18px; }
.modern-table tbody tr:last-child td { border-bottom: none; }
</style>';
render_page('Departments', 'Departments', $deptStyles . (string) ob_get_clean());
