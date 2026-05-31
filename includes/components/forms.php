<?php
function render_user_form(array $roles): string
{
    $action = app_url('admin/users_create.php');
    ob_start(); ?>
    <form method="post" action="<?= h($action) ?>">
        <div class="form-grid">
            <div>
                <label>Display Name</label>
                <input type="text" name="display_name" required>
            </div>
            <div>
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div>
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= h($role['roles_id']) ?>"><?= h($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn">Create User</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_staff_edit_form(array $departments, array $roles, array $staff = [], array $staffRoleIds = []): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="update_staff">
        <input type="hidden" name="staff_id" value="<?= h($staff['staff_id'] ?? '') ?>">

        <div class="form-grid">
            <div>
                <label>Full Name</label>
                <input type="text" name="full_name"
                       value="<?= h($staff['full_name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= h($staff['email'] ?? '') ?>" required>
            </div>

            <div>
                <label>Employee Number</label>
                <input type="text" name="employee_number"
                       value="<?= h($staff['employee_number'] ?? '') ?>">
            </div>

            <div>
                <label>Department</label>
                <select name="dept_id">
                    <option value="">No department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h($d['dept_id']) ?>"
                            <?= (($staff['dept_id'] ?? '') == $d['dept_id']) ? 'selected' : '' ?>>
                            <?= h(($d['department_code'] ?? '') . ' - ' . ($d['department_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="grid-column: span 2;">
                <label>Roles</label>
                <div class="checkbox-group">
                    <?php foreach ($roles as $role): ?>
                        <label>
                            <input type="checkbox"
                                   name="roles[]"
                                   value="<?= h($role['roles_id']) ?>"
                                   <?= in_array($role['roles_id'], $staffRoleIds) ? 'checked' : '' ?>>
                            <?= h($role['role_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn">Save Changes</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_student_form(array $programs, array $sections = [], string $generatedNumber = ''): string
{
    ob_start(); ?>
    <form method="post" action="<?= h(app_url('admin/student_create.php')) ?>">
        <div class="form-grid">
            <div>
                <label>Student Number</label>
                <input type="text" name="student_number" value="<?= h($generatedNumber) ?>" required>
            </div>
            <div>
                <label>Full Name</label>
                <input type="text" name="full_name" required>
            </div>
            <div>
                <label>Address</label>
                <input type="text" name="address" required>
            </div>
            <div>
                <label>Program</label>
                <select name="program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h($p['programs_id']) ?>"><?= h($p['program_code'] . ' - ' . ($p['program_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Year Level</label>
                <select name="year_level" required>
                    <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
                </select>
            </div>
            <div>
                <label>Section</label>
                <select name="section_id">
                    <option value="">None</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= h($sec['id']) ?>"><?= h($sec['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Entry Year</label>
                <input type="number" name="entry_year" value="<?= h((string) date('Y')) ?>" required>
            </div>
            <div>
                <label>RA 10931 Override</label>
                <select name="ra10931_override">
                    <option value="auto">Auto</option>
                    <option value="free">Force Free</option>
                    <option value="extension_tuition">Force Extension Tuition</option>
                    <option value="tuition">Force Tuition</option>
                </select>
            </div>
        </div>
        <hr class="soft">
        <p style="font-weight:600;margin-bottom:8px;">Optional portal account</p>
        <div class="form-grid">
            <div>
                <label>Username</label>
                <input type="text" name="username" placeholder="optional">
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" placeholder="optional">
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="password" placeholder="optional">
            </div>
        </div>
        <p class="helper">Leave portal account fields blank if the student will self-register later.</p>
        <div class="form-actions">
            <button class="btn">Create Student</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_student_edit_form(array $programs, array $sections, array $student = []): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="update_student">
        <input type="hidden" name="student_id" value="<?= h($student['id'] ?? '') ?>">

        <div class="form-grid">
            <div>
                <label>Student Number</label>
                <input type="text" name="student_number"
                       value="<?= h($student['student_number'] ?? '') ?>" required>
            </div>

            <div>
                <label>Full Name</label>
                <input type="text" name="full_name"
                       value="<?= h($student['full_name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Address</label>
                <input type="text" name="address"
                       value="<?= h($student['address'] ?? '') ?>" required>
            </div>

            <div>
                <label>Program</label>
                <select name="program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h($p['programs_id']) ?>"
                            <?= (($student['program_id'] ?? '') == $p['programs_id']) ? 'selected' : '' ?>>
                            <?= h($p['program_code'] . ' - ' . ($p['program_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Year Level</label>
                <select name="year_level">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <option value="<?= $i ?>"
                            <?= (($student['year_level'] ?? '') == $i) ? 'selected' : '' ?>>
                            <?= $i ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div>
                <label>Section</label>
                <select name="section_id">
                    <option value="">None</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= h($sec['id']) ?>"
                            <?= (($student['section_id'] ?? '') == $sec['id']) ? 'selected' : '' ?>>
                            <?= h($sec['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn">Save Changes</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_staff_form(array $departments, array $roles = []): string
{
    ob_start(); ?>
    <form method="post" action="<?= h(app_url('admin/staff_create.php')) ?>">
        <div class="form-grid">
            <div>
                <label>Full Name</label>
                <input type="text" name="full_name" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
            <div>
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="password" value="Password123!" required>
            </div>
            <div>
                <label>Employee Number</label>
                <input type="text" name="employee_number" placeholder="EMP-0001">
            </div>
            <div>
                <label>Department</label>
                <select name="dept_id">
                    <option value="">No department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h($d['dept_id']) ?>"><?= h(($d['department_code'] ?? '') . ' - ' . ($d['department_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($roles)): ?>
            <div>
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= h($role['roles_id']) ?>"><?= h($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-actions">
            <button class="btn">Create Staff Account</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_curriculum_subject_form(): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="add_subject">
        <div class="form-grid cols-2">
            <div>
                <label>Subject Code</label>
                <input type="text" name="subject_code" required>
            </div>
            <div>
                <label>Description</label>
                <input type="text" name="subject_description" required>
            </div>
            <div>
                <label>Lecture Credit</label>
                <input type="number" step="0.5" name="lec_credit" value="0" min="0">
            </div>
            <div>
                <label>Lab Credit</label>
                <input type="number" step="0.5" name="lab_credit" value="0" min="0">
            </div>
            <div>
                <label>Lecture Hours</label>
                <input type="number" step="0.5" name="lec_hours" value="0" min="0">
            </div>
            <div>
                <label>Lab Hours</label>
                <input type="number" step="0.5" name="lab_hours" value="0" min="0">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn">Add Subject</button>
        </div>
    </form>
    <?php return ob_get_clean();
}


function render_curriculum_line_form(array $programs, array $subjects): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="add_curriculum">
        <div class="form-grid">
            <div>
                <label>Program</label>
                <select name="program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h($p['programs_id']) ?>"><?= h($p['program_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Subject</label>
                <input type="text" class="subject-search" placeholder="Type to search subjects..." oninput="filterSubjectSelect(this, 'subject_id')" style="margin-bottom:4px;font-size:12px;padding:4px 8px;width:100%;box-sizing:border-box;">
                <select name="subject_id" id="subject_id" required style="max-height:200px;">
                    <option value="">— Select —</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>" data-code="<?= h($s['subject_code']) ?>" data-desc="<?= h($s['subject_description']) ?>"><?= h($s['subject_code'] . ' - ' . $s['subject_description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Curriculum Label</label>
                <input type="text" name="curriculum_label" value="2024">
            </div>
            <div>
                <label>Year Level</label>
                <select name="year_level">
                    <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
                </select>
            </div>
            <div>
                <label>Semester</label>
                <select name="semester">
                    <option value="1st">1st</option>
                    <option value="2nd">2nd</option>
                    <option value="mid">Midyear</option>
                </select>
            </div>
            <div>
                <label>Standing (optional)</label>
                <select name="standing">
                    <option value="">None</option>
                    <option value="2nd Year Standing">2nd Year Standing</option>
                    <option value="3rd Year Standing">3rd Year Standing</option>
                    <option value="4th Year Standing">4th Year Standing</option>
                </select>
            </div>
            <div>
                <label>Prerequisite 1 (optional)</label>
                <input type="text" class="subject-search" placeholder="Search..." oninput="filterSubjectSelect(this, 'prereq1_id')" style="margin-bottom:4px;font-size:12px;padding:4px 8px;width:100%;box-sizing:border-box;">
                <select name="prerequisite_subject_id" id="prereq1_id">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>" data-code="<?= h($s['subject_code']) ?>" data-desc="<?= h($s['subject_description']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Prerequisite 2 (optional)</label>
                <input type="text" class="subject-search" placeholder="Search..." oninput="filterSubjectSelect(this, 'prereq2_id')" style="margin-bottom:4px;font-size:12px;padding:4px 8px;width:100%;box-sizing:border-box;">
                <select name="prerequisite_subject_2_id" id="prereq2_id">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>" data-code="<?= h($s['subject_code']) ?>" data-desc="<?= h($s['subject_description']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Prerequisite 3 (optional)</label>
                <input type="text" class="subject-search" placeholder="Search..." oninput="filterSubjectSelect(this, 'prereq3_id')" style="margin-bottom:4px;font-size:12px;padding:4px 8px;width:100%;box-sizing:border-box;">
                <select name="prerequisite_subject_3_id" id="prereq3_id">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>" data-code="<?= h($s['subject_code']) ?>" data-desc="<?= h($s['subject_description']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn">Add Curriculum Line</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_curriculum_line_form_multi_prereq(array $programs, array $subjects): string
{
    return render_curriculum_line_form($programs, $subjects);
}

function render_bulk_curriculum_form(array $programs, array $subjects): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="bulk_add_curriculum">
        <div class="form-grid cols-2" style="margin-bottom:10px;">
            <div>
                <label>Program</label>
                <select name="bulk_program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h($p['programs_id']) ?>"><?= h($p['program_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Curriculum Label</label>
                <input type="text" name="bulk_curriculum_label" value="2024">
            </div>
        </div>
        <div class="table-wrap" style="max-height:300px;overflow-y:auto;">
        <table id="bulkCurrTable" style="width:100%;font-size:12px;">
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th style="width:70px;">Year</th>
                    <th style="width:80px;">Semester</th>
                    <th style="width:120px;">Standing</th>
                    <th style="width:36px;"></th>
                </tr>
            </thead>
            <tbody id="bulkCurrRows">
                <tr>
                    <td><input type="text" name="bulk_curr_code[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>
                    <td>
                        <select name="bulk_curr_year[]" style="width:100%;box-sizing:border-box;font-size:12px;">
                            <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
                        </select>
                    </td>
                    <td>
                        <select name="bulk_curr_sem[]" style="width:100%;box-sizing:border-box;font-size:12px;">
                            <option value="1st">1st</option><option value="2nd">2nd</option><option value="mid">Midyear</option>
                        </select>
                    </td>
                    <td>
                        <select name="bulk_curr_standing[]" style="width:100%;box-sizing:border-box;font-size:12px;">
                            <option value="">—</option>
                            <option value="2nd Year Standing">2nd Year</option>
                            <option value="3rd Year Standing">3rd Year</option>
                            <option value="4th Year Standing">4th Year</option>
                        </select>
                    </td>
                    <td><button type="button" class="icon-btn danger" onclick="this.closest('tr').remove()" style="font-size:14px;padding:2px 6px;">✕</button></td>
                </tr>
            </tbody>
        </table>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;">
            <button type="button" class="btn secondary" style="font-size:12px;" onclick="addBulkCurrRow()">+ Add Row</button>
            <button class="btn" type="submit" style="font-size:12px;">Add All Lines</button>
        </div>
    </form>
    <script>
    function addBulkCurrRow() {
        var tbody = document.getElementById('bulkCurrRows');
        var row = document.createElement('tr');
        row.innerHTML = '<td><input type="text" name="bulk_curr_code[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>' +
            '<td><select name="bulk_curr_year[]" style="width:100%;box-sizing:border-box;font-size:12px;"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></td>' +
            '<td><select name="bulk_curr_sem[]" style="width:100%;box-sizing:border-box;font-size:12px;"><option value="1st">1st</option><option value="2nd">2nd</option><option value="mid">Midyear</option></select></td>' +
            '<td><select name="bulk_curr_standing[]" style="width:100%;box-sizing:border-box;font-size:12px;"><option value="">—</option><option value="2nd Year Standing">2nd Year</option><option value="3rd Year Standing">3rd Year</option><option value="4th Year Standing">4th Year</option></select></td>' +
            '<td><button type="button" class="icon-btn danger" onclick="this.closest(\'tr\').remove()" style="font-size:14px;padding:2px 6px;">✕</button></td>';
        tbody.appendChild(row);
    }
    </script>
    <?php return ob_get_clean();
}

function render_edit_curriculum_form(array $programs, array $subjects): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="update_curriculum">
        <input type="hidden" name="curriculum_id" id="ec_id">
        <div class="form-grid">
            <div>
                <label>Program</label>
                <select name="program_id" id="ec_program_id" required>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h($p['programs_id']) ?>"><?= h($p['program_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Subject</label>
                <input type="text" class="subject-search" placeholder="Type to search subjects..." oninput="filterSubjectSelect(this, 'ec_subject_id')" style="margin-bottom:4px;font-size:12px;padding:4px 8px;width:100%;box-sizing:border-box;">
                <select name="subject_id" id="ec_subject_id" required style="max-height:200px;">
                    <option value="">— Select —</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>" data-code="<?= h($s['subject_code']) ?>" data-desc="<?= h($s['subject_description']) ?>"><?= h($s['subject_code'] . ' - ' . $s['subject_description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Curriculum Label</label>
                <input type="text" name="curriculum_label" id="ec_label" value="2024">
            </div>
            <div>
                <label>Year Level</label>
                <select name="year_level" id="ec_year_level">
                    <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
                </select>
            </div>
            <div>
                <label>Semester</label>
                <select name="semester" id="ec_semester">
                    <option value="1st">1st</option>
                    <option value="2nd">2nd</option>
                    <option value="mid">Midyear</option>
                </select>
            </div>
            <div>
                <label>Standing (optional)</label>
                <select name="standing" id="ec_standing">
                    <option value="">None</option>
                    <option value="2nd Year Standing">2nd Year Standing</option>
                    <option value="3rd Year Standing">3rd Year Standing</option>
                    <option value="4th Year Standing">4th Year Standing</option>
                </select>
            </div>
            <div>
                <label>Prerequisite 1 (optional)</label>
                <select name="prerequisite_subject_id" id="ec_prereq1">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Prerequisite 2 (optional)</label>
                <select name="prerequisite_subject_2_id" id="ec_prereq2">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Prerequisite 3 (optional)</label>
                <select name="prerequisite_subject_3_id" id="ec_prereq3">
                    <option value="">None</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= h($s['subject_id']) ?>"><?= h($s['subject_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn secondary" data-close>Cancel</button>
            <button class="btn" type="submit">Save Changes</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

/* ──────────────────────────────────────────────────────────────────────
   Curriculum: combined Add + Manage modals (for programs and subjects)
   These render the existing form on top, and a list with edit/delete
   below. Permissions are enforced server-side; the $canManage flag
   simply hides edit/delete UI for read-only roles.
   ────────────────────────────────────────────────────────────────────── */
function render_program_add_modal_body(array $departments): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="add_program">
        <div class="form-grid cols-1">
            <div>
                <label>Department</label>
                <select name="department_id" required>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h($d['dept_id']) ?>"><?= h($d['department_code'] . ' — ' . $d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Program Code</label>
                <input type="text" name="program_code" required>
            </div>
            <div style="grid-column:span 2;">
                <label>Program Name</label>
                <input type="text" name="program_name" required>
            </div>
            <div>
                <label>Major (optional)</label>
                <input type="text" name="program_major" placeholder="e.g. Major in Web Development">
            </div>
            <div>
                <label>Lab Fee per Unit (₱)</label>
                <input type="number" step="0.01" name="lab_fee_per_unit" value="0" min="0" placeholder="e.g. 200">
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn secondary" data-close>Cancel</button>
            <button class="btn" type="submit">Add Program</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_program_edit_modal_body(array $departments): string
{
    ob_start(); ?>
    <form method="post" id="editProgramForm">
        <input type="hidden" name="action" value="update_program">
        <input type="hidden" name="programs_id" id="ep_id">
        <div class="form-grid cols-2">
            <div>
                <label>Department</label>
                <select name="department_id" id="ep_dept" required>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h($d['dept_id']) ?>"><?= h($d['department_code'] . ' — ' . $d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Program Code</label><input type="text" name="program_code" id="ep_code" required></div>
            <div style="grid-column:span 2;"><label>Program Name</label><input type="text" name="program_name" id="ep_name" required></div>
            <div><label>Major (optional)</label><input type="text" name="program_major" id="ep_major" placeholder="e.g. Major in Web Development"></div>
            <div><label>Lab Fee per Unit (₱)</label><input type="number" step="0.01" name="lab_fee_per_unit" id="ep_lab_fee" value="0" min="0" placeholder="e.g. 200"></div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn secondary" data-close>Cancel</button>
            <button class="btn" type="submit">Save Changes</button>
        </div>
    </form>
    <script>
    function openEditProgram(p){
        document.getElementById('ep_id').value = p.id;
        document.getElementById('ep_code').value = p.code;
        document.getElementById('ep_name').value = p.name;
        document.getElementById('ep_major').value = p.major || '';
        document.getElementById('ep_lab_fee').value = p.lab_fee || 0;
        var dept = document.getElementById('ep_dept');
        if (p.dept) dept.value = p.dept;
        if (typeof openModal === 'function') openModal('editProgramModal');
        else document.querySelector('[data-open=editProgramModal]')?.click();
    }
    </script>
    <?php return ob_get_clean();
}

function render_program_manage_modal_body(array $programs, array $departments, bool $canManage = true): string
{
    ob_start(); ?>
    <?php if ($canManage): ?>
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
        <button type="button" class="btn" data-open="addProgramModal">
            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">add</span> Add Program
        </button>
    </div>
    <?php endif; ?>

    <h4 style="margin:8px 0;">Existing Programs</h4>
    <div class="dt" data-dt-page-size="8">
      <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th data-dt-key="code">Code</th>
                    <th data-dt-key="name">Name</th>
                    <th data-dt-key="major">Major</th>
                    <th data-dt-key="dept" data-dt-filter="select">Department</th>
                    <?php if ($canManage): ?><th data-dt-no-sort data-dt-no-export></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($programs as $p): ?>
                <tr>
                    <td><strong><?= h($p['program_code']) ?></strong></td>
                    <td><?= h($p['program_name']) ?></td>
                    <td><?= h(!empty($p['program_major']) ? $p['program_major'] : '—') ?></td>
                    <td><?= h(($p['department_code'] ?? '') . ($p['department_name'] ? ' — ' . $p['department_name'] : '')) ?></td>
                    <?php if ($canManage): ?>
                    <td>
                        <button type="button" class="icon-btn" title="Edit"
                            onclick='openEditProgram(<?= json_encode([
                                "id"=>$p["programs_id"],
                                "code"=>$p["program_code"],
                                "name"=>$p["program_name"],
                                "major"=>$p["program_major"] ?? "",
                                "dept"=>(string)($p["department_id"] ?? "")
                            ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <form method="post" class="inline-form" onsubmit="return confirm('Mark this program inactive?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete_program">
                            <input type="hidden" name="programs_id" value="<?= h((string)$p['programs_id']) ?>">
                            <button class="icon-btn danger" type="submit" title="Delete"><span class="material-symbols-outlined">delete</span></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </div>
    <?php return ob_get_clean();
}

/* ============ SUBJECTS ============ */

function render_subject_add_modal_body(): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="add_subject">
        <div class="form-grid cols-2">
            <div><label>Subject Code</label><input type="text" name="subject_code" required></div>
            <div><label>Description</label><input type="text" name="subject_description" required></div>
            <div><label>Lecture Credit</label><input type="number" step="0.5" name="lec_credit" value="0" min="0"></div>
            <div><label>Lab Credit</label><input type="number" step="0.5" name="lab_credit" value="0" min="0"></div>
            <div><label>Lecture Hours</label><input type="number" step="0.5" name="lec_hours" value="0" min="0"></div>
            <div><label>Lab Hours</label><input type="number" step="0.5" name="lab_hours" value="0" min="0"></div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn secondary" data-close>Cancel</button>
            <button class="btn" type="submit">Add Subject</button>
        </div>
    </form>
    <?php return ob_get_clean();
}

function render_subject_edit_modal_body(): string
{
    ob_start(); ?>
    <form method="post">
        <input type="hidden" name="action" value="update_subject">
        <input type="hidden" name="subject_id" id="es_id">
        <div class="form-grid cols-2">
            <div><label>Subject Code</label><input type="text" name="subject_code" id="es_code" required></div>
            <div><label>Description</label><input type="text" name="subject_description" id="es_desc" required></div>
            <div><label>Lecture Credit</label><input type="number" step="0.5" name="lec_credit" id="es_lec_credit" min="0"></div>
            <div><label>Lab Credit</label><input type="number" step="0.5" name="lab_credit" id="es_lab_credit" min="0"></div>
            <div><label>Lecture Hours</label><input type="number" step="0.5" name="lec_hours" id="es_lec_hours" min="0"></div>
            <div><label>Lab Hours</label><input type="number" step="0.5" name="lab_hours" id="es_lab_hours" min="0"></div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn secondary" data-close>Cancel</button>
            <button class="btn" type="submit">Save Changes</button>
        </div>
    </form>
    <script>
    function openEditSubject(s){
        document.getElementById('es_id').value = s.id;
        document.getElementById('es_code').value = s.code;
        document.getElementById('es_desc').value = s.desc;
        document.getElementById('es_lec_credit').value = s.lec_credit || 0;
        document.getElementById('es_lab_credit').value = s.lab_credit || 0;
        document.getElementById('es_lec_hours').value = s.lec_hours || 0;
        document.getElementById('es_lab_hours').value = s.lab_hours || 0;
        if (typeof openModal === 'function') openModal('editSubjectModal');
        else document.querySelector('[data-open=editSubjectModal]')?.click();
    }
    </script>
    <?php return ob_get_clean();
}

function render_subject_manage_modal_body(array $subjects, bool $canManage = true): string
{
    ob_start(); ?>
    <?php if ($canManage): ?>
    <div style="margin-bottom:12px;border:1px solid var(--border);border-radius:6px;padding:8px 12px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <strong style="font-size:13px;color:var(--primary);">➕ Bulk Add Subjects</strong>
            <span class="badge" style="font-size:10px;">Add multiple at once</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="bulk_add_subjects">
            <div class="table-wrap" style="max-height:320px;overflow-y:auto;">
            <table id="bulkAddTable" style="width:100%;font-size:12px;">
                <thead>
                    <tr>
                        <th style="width:100px;">Code</th>
                        <th>Description</th>
                        <th style="width:60px;">Lec Cr</th>
                        <th style="width:60px;">Lab Cr</th>
                        <th style="width:60px;">Lec Hrs</th>
                        <th style="width:60px;">Lab Hrs</th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody id="bulkAddRows">
                    <tr>
                        <td><input type="text" name="bulk_code[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>
                        <td><input type="text" name="bulk_desc[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>
                        <td><input type="number" name="bulk_lec_credit[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>
                        <td><input type="number" name="bulk_lab_credit[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>
                        <td><input type="number" name="bulk_lec_hours[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>
                        <td><input type="number" name="bulk_lab_hours[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>
                        <td><button type="button" class="icon-btn danger" onclick="this.closest('tr').remove()" style="font-size:14px;padding:2px 6px;">✕</button></td>
                    </tr>
                </tbody>
            </table>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button type="button" class="btn secondary" style="font-size:12px;" onclick="addBulkSubjectRow()">+ Add Row</button>
                <button class="btn" type="submit" style="font-size:12px;">Add All Subjects</button>
            </div>
        </form>
    </div>
    <script>
    function addBulkSubjectRow() {
        var tbody = document.getElementById('bulkAddRows');
        var row = document.createElement('tr');
        row.innerHTML = '<td><input type="text" name="bulk_code[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>' +
            '<td><input type="text" name="bulk_desc[]" style="width:100%;box-sizing:border-box;font-size:12px;" required></td>' +
            '<td><input type="number" name="bulk_lec_credit[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>' +
            '<td><input type="number" name="bulk_lab_credit[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>' +
            '<td><input type="number" name="bulk_lec_hours[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>' +
            '<td><input type="number" name="bulk_lab_hours[]" step="0.5" value="0" min="0" style="width:100%;box-sizing:border-box;font-size:12px;"></td>' +
            '<td><button type="button" class="icon-btn danger" onclick="this.closest(\'tr\').remove()" style="font-size:14px;padding:2px 6px;">✕</button></td>';
        tbody.appendChild(row);
    }
    </script>
    <?php endif; ?>

    <h4 style="margin:8px 0;">Existing Subjects</h4>
    <form method="post" id="bulkSubjectForm">
    <input type="hidden" name="action" value="bulk_update_subjects">
    <div class="dt" data-dt-page-size="8">
      <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th data-dt-key="code">Code</th>
                    <th data-dt-key="desc">Description</th>
                    <th data-dt-key="leccr">Lec Cr</th>
                    <th data-dt-key="labcr">Lab Cr</th>
                    <th data-dt-key="lech">Lec Hrs</th>
                    <th data-dt-key="labh">Lab Hrs</th>
                    <?php if ($canManage): ?><th data-dt-no-sort data-dt-no-export>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subjects as $s): ?>
                <tr>
                    <td><strong><?= h($s['subject_code']) ?></strong></td>
                    <td><?= h($s['subject_description']) ?></td>
                    <td>
                        <input type="hidden" name="subject_ids[]" value="<?= h((string)$s['subject_id']) ?>">
                        <input type="number" step="0.5" name="lec_credit[<?= h((string)$s['subject_id']) ?>]" value="<?= h((string)($s['lec_credit'] ?? 0)) ?>" min="0" style="width:64px;">
                    </td>
                    <td><input type="number" step="0.5" name="lab_credit[<?= h((string)$s['subject_id']) ?>]" value="<?= h((string)($s['lab_credit'] ?? 0)) ?>" min="0" style="width:64px;"></td>
                    <td><input type="number" step="0.5" name="lec_hours[<?= h((string)$s['subject_id']) ?>]" value="<?= h((string)($s['lec_hours'] ?? 0)) ?>" min="0" style="width:64px;"></td>
                    <td><input type="number" step="0.5" name="lab_hours[<?= h((string)$s['subject_id']) ?>]" value="<?= h((string)($s['lab_hours'] ?? 0)) ?>" min="0" style="width:64px;"></td>
                    <?php if ($canManage): ?>
                    <td>
                        <button class="icon-btn danger" type="button" title="Delete" onclick="deleteSubject(<?= (int)$s['subject_id'] ?>)">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </div>
    <div class="form-actions" style="margin-top:10px;">
        <button class="btn" type="submit">Save Changes</button>
    </div>
    </form>

    <form method="post" id="deleteSubjectForm" style="display:none;">
        <input type="hidden" name="action" value="delete_subject">
        <input type="hidden" name="subject_id" id="deleteSubjectId" value="">
    </form>
    <script>
    function deleteSubject(id){
        if (confirm('Mark this subject inactive?')) {
            document.getElementById('deleteSubjectId').value = id;
            document.getElementById('deleteSubjectForm').submit();
        }
    }
    </script>
    <?php return ob_get_clean();
}
