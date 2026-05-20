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
            execute_sql(
                'UPDATE departments SET department_code = :code, department_name = :name WHERE dept_id = :id',
                ['code' => $code, 'name' => $name, 'id' => $deptId]
            );

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

            flash('success', 'Department updated.');
        }
    }

    if ($action === 'create_section') {
        $programId  = (int) ($_POST['program_id']   ?? 0);
        $yearLevel  = (int) ($_POST['year_level']   ?? 1);
        $sectionName = trim($_POST['section_name']  ?? '');
        $maxSlots   = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($programId <= 0 || $sectionName === '') {
            flash('error', 'Program and section name are required.');
        } else {
            execute_sql(
                'INSERT INTO sections (program_id, year_level, section_name, max_slots, created_at)
                 VALUES (:program_id, :year_level, :section_name, :max_slots, NOW())',
                [
                    'program_id'   => $programId,
                    'year_level'   => $yearLevel,
                    'section_name' => $sectionName,
                    'max_slots'    => $maxSlots,
                ]
            );
            flash('success', 'Section created.');
        }
    }

    if ($action === 'delete_department') {
        $deptId = (int) ($_POST['dept_id'] ?? 0);
        if ($deptId > 0) {
            if (soft_delete('departments', 'dept_id', $deptId)) {
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
            flash('success', count($ids) . ' department(s) deleted.');
        }
    }

    if ($action === 'delete_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($sectionId > 0) {
            if (soft_delete('sections', 'id', $sectionId)) {
                flash('success', 'Section marked inactive.');
            } else {
                flash('error', 'Failed to delete section.');
            }
        }
    }

    if ($action === 'update_section') {
        $sectionId   = (int) ($_POST['section_id'] ?? 0);
        $programId   = (int) ($_POST['program_id'] ?? 0);
        $yearLevel   = (int) ($_POST['year_level'] ?? 1);
        $sectionName = trim($_POST['section_name'] ?? '');
        $maxSlots    = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($sectionId <= 0 || $programId <= 0 || $sectionName === '') {
            flash('error', 'Program, section name, and section ID are required.');
        } else {
            execute_sql(
                'UPDATE sections SET program_id = :program_id, year_level = :year_level,
                 section_name = :section_name, max_slots = :max_slots WHERE id = :id',
                [
                    'program_id'   => $programId,
                    'year_level'   => $yearLevel,
                    'section_name' => $sectionName,
                    'max_slots'    => $maxSlots,
                    'id'           => $sectionId,
                ]
            );
            flash('success', 'Section updated.');
        }
    }

    if ($action === 'bulk_delete_sections') {
        $ids = $_POST['section_id'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            execute_sql("UPDATE sections SET status = 'inactive' WHERE id IN ({$ph})", $ids);
            flash('success', count($ids) . ' section(s) deleted.');
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
            s.staff_id  AS chair_id,
            (SELECT COUNT(*) FROM programs p WHERE p.department_id = d.dept_id) AS program_count
     FROM departments d
     LEFT JOIN staff s ON s.dept_id = d.dept_id
     LEFT JOIN user_roles ur ON ur.user_id = s.users_id
     LEFT JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "department_chair"
     WHERE d.status = "active"
     GROUP BY d.dept_id
     ORDER BY d.department_code'
);

// Sections with program and adviser info
$sections = fetch_all(
    'SELECT sec.*,
            p.program_code, p.program_name,
            d.department_code,
            s.full_name AS adviser_name
     FROM sections sec
     INNER JOIN programs p   ON p.programs_id  = sec.program_id
     INNER JOIN departments d ON d.dept_id      = p.department_id
     LEFT JOIN staff s        ON s.staff_id     = sec.adviser_id
     WHERE COALESCE(sec.status, "active") = "active"
     ORDER BY d.department_code, p.program_code, sec.year_level, sec.section_name'
);

$programs = fetch_all('SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code');

$staffOptions = '<option value="">— Select Chair —</option>';
$staffList = fetch_all(
    'SELECT staff_id, full_name, dept_id 
     FROM staff 
     ORDER BY full_name'
);
foreach ($staffList as $s) {
    $staffOptions .= '<option value="' . h($s['staff_id']) . '">' 
                   . h($s['full_name']) . 
                   '</option>';
}
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
                ' . $staffOptions . '
            </select>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Save Changes</button>
    </div>
</form>';

$sectionOptions = '';
foreach ($programs as $p) {
    $sectionOptions .= '<option value="' . h($p['programs_id']) . '">' . h($p['program_code'] . ' — ' . $p['program_name']) . '</option>';
}

$sectionModalBody = '
<form method="post">
    <input type="hidden" name="action" value="create_section">
    <div class="form-grid cols-3">
        <div>
            <label>Program</label>
            <select name="program_id" required>
                <option value="">— select —</option>
                ' . $sectionOptions . '
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level">
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
            </select>
        </div>
        <div>
            <label>Section Name</label>
            <input type="text" name="section_name" placeholder="e.g. A" required maxlength="10">
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" placeholder="e.g. 40" min="1">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Create Section</button>
    </div>
</form>';

$editSectionModalBody = '
<form method="post">
    <input type="hidden" name="action" value="update_section">
    <input type="hidden" name="section_id" id="edit_section_id">

    <div class="form-grid cols-3">
        <div>
            <label>Program</label>
            <select name="program_id" id="edit_section_program_id" required>
                <option value="">— select —</option>
                ' . $sectionOptions . '
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level" id="edit_section_year_level">
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
            </select>
        </div>
        <div>
            <label>Section Name</label>
            <input type="text" name="section_name" id="edit_section_name" required maxlength="10">
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" id="edit_section_max_slots" placeholder="e.g. 40" min="1">
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
        <h1>Departments &amp; Sections</h1>
        <p>Manage all departments and class sections.</p>
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
                                            data-name="<?= h($dept['department_name']) ?>">
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

    <!-- ── SECTIONS ── -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0;">Sections</h3>
            <button class="btn small" data-open="modal-new-section">+ New Section</button>
        </div>
        <div class="dt modern-table" data-dt-page-size="10" data-dt-bulk-delete-url="<?= h(app_url('registrar/departments.php')) ?>" data-dt-bulk-id-field="section_id" data-dt-bulk-action="bulk_delete_sections" data-dt-bulk-confirm="Delete selected sections?">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                            <th data-dt-key="section">Section</th>
                            <th data-dt-key="program" data-dt-filter="select">Program</th>
                            <th data-dt-key="year" data-dt-filter="select">Year</th>
                            <th data-dt-key="adviser">Adviser</th>
                            <th data-dt-key="slots">Slots</th>
                            <th data-dt-no-sort>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($sections)): ?>
                        <tr><td colspan="7" class="empty">No sections yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sections as $sec): ?>
                        <tr data-dt-row-id="<?= h((string)$sec['id']) ?>" class="table-row"
                            data-href="<?= h(app_url('registrar/section_detail.php?id=' . $sec['id'])) ?>">
                            <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$sec['id']) ?>" aria-label="Select row"></td>
                            <td data-label="Section">
                                <div class="table-cell-primary">
                                    <div class="cell-icon section-icon"><span class="material-symbols-outlined">groups</span></div>
                                    <div>
                                        <strong><?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Program" data-dt-value="<?= h($sec['program_code']) ?>">
                                <span class="table-badge program-badge"><?= h($sec['program_code']) ?></span>
                            </td>
                            <td data-label="Year" data-dt-value="<?= h($sec['year_level']) ?>">Year <?= h($sec['year_level']) ?></td>
                            <td data-label="Adviser">
                                <?php if ($sec['adviser_name']): ?>
                                    <span class="table-badge adviser-badge"><?= h($sec['adviser_name']) ?></span>
                                <?php else: ?>
                                    <span class="helper">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Slots">
                                <span class="table-badge count-badge"><?= $sec['max_slots'] ? h($sec['max_slots']) : '—' ?></span>
                            </td>
                            <td data-label="Actions">
                                <div class="row-actions">
                                    <a class="action-btn" title="View Students" aria-label="View Students"
                                       href="<?= h(app_url('registrar/section_detail.php?id=' . $sec['id'])) ?>">
                                        <span class="material-symbols-outlined">visibility</span>
                                    </a>
                                    <button class="action-btn" type="button" title="Edit" aria-label="Edit"
                                            data-open="modal-edit-section"
                                            data-id="<?= h($sec['id']) ?>"
                                            data-program-id="<?= h($sec['program_id']) ?>"
                                            data-year-level="<?= h($sec['year_level']) ?>"
                                            data-section-name="<?= h($sec['section_name']) ?>"
                                            data-max-slots="<?= h($sec['max_slots'] ?? '') ?>">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <form class="inline-form" method="post"
                                          onsubmit="return confirm('Mark this section as inactive?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_section">
                                        <input type="hidden" name="section_id" value="<?= h($sec['id']) ?>">
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
document.addEventListener('click', function(e) {
    const deptBtn = e.target.closest('[data-open="modal-edit-dept"]');
    if (deptBtn) {
        document.getElementById('edit_dept_id').value = deptBtn.dataset.id;
        document.getElementById('edit_department_code').value = deptBtn.dataset.code;
        document.getElementById('edit_department_name').value = deptBtn.dataset.name;
    }

    const secBtn = e.target.closest('[data-open="modal-edit-section"]');
    if (secBtn) {
        document.getElementById('edit_section_id').value = secBtn.dataset.id;
        document.getElementById('edit_section_program_id').value = secBtn.dataset.programId;
        document.getElementById('edit_section_year_level').value = secBtn.dataset.yearLevel;
        document.getElementById('edit_section_name').value = secBtn.dataset.sectionName;
        document.getElementById('edit_section_max_slots').value = secBtn.dataset.maxSlots || '';
    }
});
</script>
<?= render_modal('modal-new-dept',      'New Department',    $deptModalBody) ?>
<?= render_modal('modal-new-section',   'New Section',       $sectionModalBody) ?>
<?= render_modal('modal-edit-dept',     'Edit Department',   $editDeptModalBody) ?>
<?= render_modal('modal-edit-section',  'Edit Section',      $editSectionModalBody) ?>

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
.cell-icon.section-icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.cell-icon .material-symbols-outlined { font-size: 20px; }
.cell-sub { display: block; font-size: 12px; color: #94a3b8; font-weight: 400; margin-top: 2px; }
.table-cell-text { color: #475569; }
.table-badge {
    display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}
.chair-badge { background: #fef3c7; color: #d97706; }
.program-badge { background: #eff6ff; color: #3b82f6; }
.adviser-badge { background: #f0fdf4; color: #16a34a; }
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
render_page('Departments & Sections', 'Departments & Sections', $deptStyles . (string) ob_get_clean());
