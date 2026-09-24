<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$student = fetch_one(
    'SELECT s.*, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
            p.program_code, p.program_name, sec.section_name, d.department_name
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN departments d ON d.dept_id = p.department_id
     WHERE s.id = :id',
    ['id' => $studentId]
);
if ($student === null) {
    flash('error', 'Student not found.');
    redirect('admin/students.php');
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_grade') {
        $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
        $grade = trim($_POST['final_grade'] ?? '');
        $registrarStaff = current_staff();
        save_grade($studentSubjectId, $grade, (int) ($registrarStaff['staff_id'] ?? 0));
        flash('success', 'Grade updated.');
        redirect('registrar/student_detail.php?student_id=' . $studentId . '&tab=grades');
    }

    if ($action === 'save_personal') {
        execute_sql(
            'UPDATE students SET first_name = :fn, middle_name = :mn, last_name = :ln, sex = :sex,
             birth_date = :bd, place_of_birth = :pob, contact_number = :cn, email_address = :ea,
             address = :addr, landline_no = :land, religion = :rel, nationality = :nat, civil_status = :cs
             WHERE id = :id',
            [
                'fn'   => trim($_POST['first_name'] ?? ''),
                'mn'   => trim($_POST['middle_name'] ?? ''),
                'ln'   => trim($_POST['last_name'] ?? ''),
                'sex'  => trim($_POST['sex'] ?? ''),
                'bd'   => trim($_POST['birth_date'] ?? '') ?: null,
                'pob'  => trim($_POST['place_of_birth'] ?? ''),
                'cn'   => trim($_POST['contact_number'] ?? ''),
                'ea'   => trim($_POST['email_address'] ?? ''),
                'addr' => trim($_POST['address'] ?? ''),
                'land' => trim($_POST['landline_no'] ?? ''),
                'rel'  => trim($_POST['religion'] ?? ''),
                'nat'  => trim($_POST['nationality'] ?? ''),
                'cs'   => trim($_POST['civil_status'] ?? ''),
                'id'   => $studentId,
            ]
        );
        flash('success', 'Personal information updated.');
        redirect('registrar/student_detail.php?student_id=' . $studentId . '&tab=personal');
    }

    if ($action === 'save_education') {
        $existing = fetch_one('SELECT id FROM student_educational_background WHERE student_id = :sid', ['sid' => $studentId]);
        $data = [
            'elementary_school'            => trim($_POST['elementary_school'] ?? ''),
            'elementary_year_graduated'    => (int) ($_POST['elementary_year_graduated'] ?? 0) ?: null,
            'elementary_school_type'       => trim($_POST['elementary_school_type'] ?? '') ?: null,
            'high_school'                  => trim($_POST['high_school'] ?? ''),
            'high_school_year_graduated'   => (int) ($_POST['high_school_year_graduated'] ?? 0) ?: null,
            'high_school_school_type'      => trim($_POST['high_school_school_type'] ?? '') ?: null,
            'last_school_attended'         => trim($_POST['last_school_attended'] ?? ''),
            'last_school_address'          => trim($_POST['last_school_address'] ?? ''),
            'last_school_program'          => trim($_POST['last_school_program'] ?? ''),
            'last_school_year_last_attended' => (int) ($_POST['last_school_year_last_attended'] ?? 0) ?: null,
        ];
        if ($existing) {
            $sets = [];
            $params = ['id' => (int) $existing['id']];
            foreach ($data as $k => $v) {
                $sets[] = "{$k} = :{$k}";
                $params[$k] = $v;
            }
            execute_sql('UPDATE student_educational_background SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        } else {
            $data['student_id'] = $studentId;
            $cols = implode(', ', array_keys($data));
            $binds = ':' . implode(',:', array_keys($data));
            execute_sql("INSERT INTO student_educational_background ({$cols}) VALUES ({$binds})", $data);
        }
        flash('success', 'Educational background updated.');
        redirect('registrar/student_detail.php?student_id=' . $studentId . '&tab=education');
    }
}

$tab = trim($_GET['tab'] ?? 'personal');
$validTabs = ['personal', 'academic', 'education', 'enrollment', 'loa', 'shifting', 'grades'];
if (!in_array($tab, $validTabs, true)) $tab = 'personal';

$financial = financial_profile($student);
$placement = get_student_placement($studentId);
$terms = student_terms_with_enrollment($studentId);
$education = fetch_one('SELECT * FROM student_educational_background WHERE student_id = :sid', ['sid' => $studentId]);
$guardians = fetch_all('SELECT * FROM student_guardians WHERE student_id = :sid ORDER BY guardian_type ASC', ['sid' => $studentId]);

$requests = fetch_all(
    'SELECT er.*, ay.year_label, t.semester
     FROM enrollment_requests er
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE er.student_id = :sid ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid") DESC',
    ['sid' => $studentId]
);

$gradeRows = fetch_all(
    'SELECT ss.id AS student_subject_id, ss.final_grade, ss.units, sub.subject_code, sub.subject_description,
            o.sched_code, ay.year_label, t.semester
     FROM student_subjects ss
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     LEFT JOIN section_subject_offerings o ON o.id = ss.offering_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.student_id = :sid
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
    ['sid' => $studentId]
);

$loaRecords = fetch_all(
    'SELECT * FROM leave_of_absence WHERE student_id = :sid ORDER BY created_at DESC',
    ['sid' => $studentId]
);

$shiftRequests = fetch_all(
    'SELECT sr.*, p_from.program_name AS from_program_name, p_to.program_name AS to_program_name
     FROM shifting_requests sr
     LEFT JOIN programs p_from ON p_from.programs_id = sr.from_program_id
     LEFT JOIN programs p_to ON p_to.programs_id = sr.to_program_id
     WHERE sr.student_id = :sid ORDER BY sr.created_at DESC',
    ['sid' => $studentId]
);

$programHistory = get_student_program_history($studentId);

$flashes = get_flashes();
$baseUrl = 'registrar/student_detail.php?student_id=' . $studentId;

ob_start();
?>
<div class="page-header">
    <div style="display:flex;align-items:center;gap:16px;">
        <?php if (!empty($student['photo_path'])): ?>
        <img src="<?= h(app_url('uploads/' . $student['photo_path'])) ?>" alt="Photo" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
        <div style="width:64px;height:64px;border-radius:50%;background:var(--primary,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;"><?= h(mb_strtoupper(mb_substr($student['first_name'], 0, 1) . mb_substr($student['last_name'], 0, 1))) ?></div>
        <?php endif; ?>
        <div>
            <h1 style="margin:0;"><?= h($student['full_name']) ?></h1>
            <p style="margin:2px 0 0;color:#64748b;"><?= h($student['student_number']) ?> · <?= h($student['program_name'] ?? $student['program_code']) ?> · <?= h($student['department_name'] ?? '') ?></p>
        </div>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('checklist.php?student_id=' . $studentId)) ?>">Checklist</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/curriculum.php?program_id=' . $student['program_id'] . '&student_id=' . $studentId)) ?>">Curriculum</a>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div style="display:flex;gap:6px;margin-bottom:16px;border-bottom:2px solid var(--line,#e5e7eb);padding-bottom:0;overflow-x:auto;">
    <?php
    $tabs = [
        'personal'   => ['icon' => 'person', 'label' => 'Personal Information'],
        'academic'   => ['icon' => 'school', 'label' => 'Academic Information'],
        'education'  => ['icon' => 'menu_book', 'label' => 'Educational Background'],
        'enrollment' => ['icon' => 'app_registration', 'label' => 'Enrollment History'],
        'loa'        => ['icon' => 'event_busy', 'label' => 'LOA History'],
        'shifting'   => ['icon' => 'swap_horiz', 'label' => 'Shifting History'],
        'grades'     => ['icon' => 'grading', 'label' => 'Grades'],
    ];
    foreach ($tabs as $key => $info): ?>
    <a href="<?= h(app_url($baseUrl . '&tab=' . $key)) ?>"
       style="padding:8px 14px;text-decoration:none;font-size:13px;font-weight:<?= $tab === $key ? '700' : '400' ?>;color:<?= $tab === $key ? 'var(--primary,#3b82f6)' : '#64748b' ?>;border-bottom:2px solid <?= $tab === $key ? 'var(--primary,#3b82f6)' : 'transparent' ?>;margin-bottom:-2px;white-space:nowrap;display:flex;align-items:center;gap:4px;">
        <span class="material-symbols-outlined" style="font-size:16px;"><?= $info['icon'] ?></span>
        <?= $info['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'personal'): ?>
<div class="grid cols-2">
    <div class="card">
        <h3>Personal Information</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_personal">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:13px;">
                <div><label style="font-weight:600;display:block;">Last Name</label><input type="text" name="last_name" value="<?= h($student['last_name']) ?>" style="width:100%;" required></div>
                <div><label style="font-weight:600;display:block;">First Name</label><input type="text" name="first_name" value="<?= h($student['first_name']) ?>" style="width:100%;" required></div>
                <div><label style="font-weight:600;display:block;">Middle Name</label><input type="text" name="middle_name" value="<?= h($student['middle_name'] ?? '') ?>" style="width:100%;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:13px;margin-top:10px;">
                <div><label style="font-weight:600;display:block;">Sex</label><select name="sex" style="width:100%;"><option value="">—</option><option value="Male" <?= ($student['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option><option value="Female" <?= ($student['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option></select></div>
                <div><label style="font-weight:600;display:block;">Date of Birth</label><input type="date" name="birth_date" value="<?= h($student['birth_date'] ?? '') ?>" style="width:100%;"></div>
                <div><label style="font-weight:600;display:block;">Place of Birth</label><input type="text" name="place_of_birth" value="<?= h($student['place_of_birth'] ?? '') ?>" style="width:100%;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:13px;margin-top:10px;">
                <div><label style="font-weight:600;display:block;">Religion</label><input type="text" name="religion" value="<?= h($student['religion'] ?? '') ?>" style="width:100%;"></div>
                <div><label style="font-weight:600;display:block;">Nationality</label><input type="text" name="nationality" value="<?= h($student['nationality'] ?? 'Filipino') ?>" style="width:100%;"></div>
                <div><label style="font-weight:600;display:block;">Civil Status</label><select name="civil_status" style="width:100%;"><option value="">—</option><?php foreach (['Single','Married','Widowed','Separated','Divorced'] as $cs): ?><option value="<?= $cs ?>" <?= ($student['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= $cs ?></option><?php endforeach; ?></select></div>
            </div>
            <div style="font-size:13px;margin-top:10px;"><label style="font-weight:600;display:block;">Home Address</label><input type="text" name="address" value="<?= h($student['address']) ?>" style="width:100%;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;margin-top:10px;">
                <div><label style="font-weight:600;display:block;">Landline No.</label><input type="text" name="landline_no" value="<?= h($student['landline_no'] ?? '') ?>" style="width:100%;"></div>
                <div><label style="font-weight:600;display:block;">Cellphone No.</label><input type="text" name="contact_number" value="<?= h($student['contact_number'] ?? '') ?>" style="width:100%;"></div>
            </div>
            <div style="font-size:13px;margin-top:10px;"><label style="font-weight:600;display:block;">Email</label><input type="email" name="email_address" value="<?= h($student['email_address'] ?? '') ?>" style="width:100%;"></div>
            <div style="margin-top:12px;"><button class="btn" type="submit">Save Personal Information</button></div>
        </form>
    </div>
    <div>
        <div class="card" style="margin-bottom:16px;">
            <h3>Guardian / Parent</h3>
            <?php if ($guardians === []): ?>
            <p style="font-size:13px;color:#94a3b8;">No guardian records found.</p>
            <?php else: ?>
            <?php foreach ($guardians as $g): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--line,#e5e7eb);font-size:13px;">
                <div style="display:flex;justify-content:space-between;"><strong><?= h($g['name']) ?></strong><span class="badge info" style="font-size:10px;"><?= h(ucfirst($g['guardian_type'])) ?></span></div>
                <?php if ($g['address']): ?><div style="color:#64748b;"><?= h($g['address']) ?></div><?php endif; ?>
                <?php if ($g['cellphone_no']): ?><div style="color:#64748b;"> <?= h($g['cellphone_no']) ?></div><?php endif; ?>
                <?php if ($g['occupation']): ?><div style="color:#64748b;"><?= h($g['occupation']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Quick Info</h3>
            <div class="kv-list" style="font-size:13px;">
                <div class="item"><div class="k">Entry Year</div><div class="v"><?= h($student['entry_year']) ?></div></div>
                <div class="item"><div class="k">Classification</div><div class="v"><?= h($student['classification'] ?? '—') ?></div></div>
                <div class="item"><div class="k">Academic Status</div><div class="v"><?= h(ucfirst(str_replace('_', ' ', $student['academic_status']))) ?></div></div>
                <div class="item"><div class="k">Record Status</div><div class="v"><?= h(ucfirst($student['record_status'])) ?></div></div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'academic'): ?>
<div class="grid cols-2">
    <div class="card">
        <h3>Current Academic Placement</h3>
        <?php if ($placement): ?>
        <div class="kv-list" style="font-size:13px;">
            <div class="item"><div class="k">Program</div><div class="v"><?= h($student['program_name'] ?? $student['program_code']) ?></div></div>
            <div class="item"><div class="k">Department</div><div class="v"><?= h($student['department_name'] ?? '—') ?></div></div>
            <div class="item"><div class="k">Year Level</div><div class="v"><?= (int) $placement['year_level'] ?><?= match((int) $placement['year_level']) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?> Year</div></div>
            <div class="item"><div class="k">Standing</div><div class="v"><?= h(ucfirst($placement['enrollment_status'])) ?></div></div>
            <div class="item"><div class="k">Placement Type</div><div class="v"><?= h(ucfirst($placement['placement_type'])) ?></div></div>
            <?php if ($placement['section_id']): ?>
            <?php $sec = fetch_one('SELECT CONCAT(p.program_code, " ", sec.year_level, sec.section_name) AS label FROM sections sec INNER JOIN programs p ON p.programs_id = sec.program_id WHERE sec.id = :id', ['id' => (int) $placement['section_id']]); ?>
            <div class="item"><div class="k">Section</div><div class="v"><?= h($sec['label'] ?? '—') ?></div></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p style="font-size:13px;color:#94a3b8;">No academic placement record found.</p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>Financial / RA 10931</h3>
        <div class="kv-list" style="font-size:13px;">
            <div class="item"><div class="k">Tuition Status</div><div class="v"><span class="badge <?= in_array($financial['status'], ['free'], true) ? 'success' : 'warning' ?>"><?= h($financial['label']) ?></span></div></div>
            <div class="item"><div class="k">Years in College</div><div class="v"><?= h($financial['years_in_college']) ?></div></div>
            <div class="item"><div class="k">Tuition Per Unit</div><div class="v">₱<?= h(format_money($financial['tuition_per_unit'])) ?></div></div>
            <div class="item"><div class="k">Override</div><div class="v"><?= h($student['ra10931_override']) ?></div></div>
        </div>
    </div>
</div>
<?php if ($programHistory !== []): ?>
<div class="card" style="margin-top:16px;">
    <h3>Program History</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Program</th><th>From</th><th>To</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($programHistory as $ph): ?>
        <tr>
            <td><?= h($ph['program_name'] ?? $ph['program_id']) ?></td>
            <td style="font-size:12px;"><?= h($ph['start_date'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= h($ph['end_date'] ?? '—') ?></td>
            <td><span class="badge info" style="font-size:10px;"><?= h(ucfirst($ph['status'] ?? '—')) ?></span></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'education'): ?>
<div class="card">
    <h3>Educational Background</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_education">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;font-size:13px;margin-bottom:16px;">
            <div style="grid-column:span 3;font-weight:700;color:var(--primary,#3b82f6);">Elementary</div>
            <div><label style="font-weight:600;display:block;">School Name</label><input type="text" name="elementary_school" value="<?= h($education['elementary_school'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">Year Graduated</label><input type="number" name="elementary_year_graduated" value="<?= h($education['elementary_year_graduated'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">School Type</label><select name="elementary_school_type" style="width:100%;"><option value="">—</option><option value="public" <?= ($education['elementary_school_type'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option><option value="private" <?= ($education['elementary_school_type'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option></select></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;font-size:13px;margin-bottom:16px;">
            <div style="grid-column:span 3;font-weight:700;color:var(--primary,#3b82f6);">High School</div>
            <div><label style="font-weight:600;display:block;">School Name</label><input type="text" name="high_school" value="<?= h($education['high_school'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">Year Graduated</label><input type="number" name="high_school_year_graduated" value="<?= h($education['high_school_year_graduated'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">School Type</label><select name="high_school_school_type" style="width:100%;"><option value="">—</option><option value="public" <?= ($education['high_school_school_type'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option><option value="private" <?= ($education['high_school_school_type'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option></select></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;font-size:13px;">
            <div style="grid-column:span 4;font-weight:700;color:var(--primary,#3b82f6);">Previous School Information</div>
            <div><label style="font-weight:600;display:block;">School Last Attended</label><input type="text" name="last_school_attended" value="<?= h($education['last_school_attended'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">School Address</label><input type="text" name="last_school_address" value="<?= h($education['last_school_address'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">Previous Program</label><input type="text" name="last_school_program" value="<?= h($education['last_school_program'] ?? '') ?>" style="width:100%;"></div>
            <div><label style="font-weight:600;display:block;">Year Last Attended</label><input type="number" name="last_school_year_last_attended" value="<?= h($education['last_school_year_last_attended'] ?? '') ?>" style="width:100%;"></div>
        </div>
        <div style="margin-top:16px;"><button class="btn" type="submit">Save Educational Background</button></div>
    </form>
</div>

<?php elseif ($tab === 'enrollment'): ?>
<div class="card">
    <h3>Enrollment History</h3>
    <?php if ($requests === []): ?>
    <p style="font-size:13px;color:#94a3b8;">No enrollment records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>AY</th><th>Semester</th><th>Status</th><th>Units</th><th>Amount</th></tr></thead>
        <tbody><?php foreach ($requests as $r): ?>
        <tr>
            <td><?= h($r['year_label']) ?></td>
            <td><?= h(semester_label((string) $r['semester'])) ?></td>
            <td><span class="badge <?= h(workflow_badge_class((string) $r['workflow_status'])) ?>"><?= h(request_workflow_label((string) $r['workflow_status'])) ?></span></td>
            <td><?= h($r['total_units']) ?></td>
            <td>₱<?= h(format_money($r['total_amount'])) ?></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'loa'): ?>
<div class="card">
    <h3>Leave of Absence History</h3>
    <?php if ($loaRecords === []): ?>
    <p style="font-size:13px;color:#94a3b8;">No LOA records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Applied For</th><th>Effective Dates</th><th>Expected Return</th><th>Status</th><th>Reason</th></tr></thead>
        <tbody><?php foreach ($loaRecords as $loa): ?>
        <tr>
            <td style="font-size:12px;"><?= get_semester_label($loa['semester']) ?> <?= h($loa['academic_year']) ?></td>
            <td style="font-size:12px;"><?= h(date('M j', strtotime($loa['effective_date_from']))) ?> – <?= h(date('M j, Y', strtotime($loa['effective_date_to']))) ?></td>
            <td style="font-size:12px;"><?= $loa['expected_return_semester'] ? get_semester_label($loa['expected_return_semester']) . ' ' . h($loa['expected_return_academic_year'] ?? '') : '—' ?></td>
            <td><?php $bc = match($loa['status']) { 'active' => 'badge warning', 'returned' => 'badge success', 'extended' => 'badge info', default => 'badge' }; ?><span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst($loa['status'])) ?></span></td>
            <td style="font-size:12px;"><?= h(mb_strimwidth($loa['reason'] ?? '', 0, 50, '...')) ?></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'shifting'): ?>
<div class="card">
    <h3>Shifting / Transfer History</h3>
    <?php if ($shiftRequests === []): ?>
    <p style="font-size:13px;color:#94a3b8;">No shifting records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>From</th><th>To</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($shiftRequests as $sr): ?>
        <tr>
            <td style="font-size:12px;"><?= h(date('M j, Y', strtotime($sr['created_at']))) ?></td>
            <td><?= h($sr['from_program_name'] ?? $sr['from_program_id']) ?></td>
            <td><?= h($sr['to_program_name'] ?? $sr['to_program_id']) ?></td>
            <td><span class="badge info" style="font-size:10px;"><?= h(ucfirst(str_replace('_', ' ', $sr['workflow_status'] ?? '—'))) ?></span></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'grades'): ?>
<div class="card">
    <h3>Grade Records</h3>
    <p style="font-size:13px;color:#64748b;margin-bottom:8px;">Registrar can edit grades below.</p>
    <?php if ($gradeRows === []): ?>
    <p style="font-size:13px;color:#94a3b8;">No grade records found.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>AY</th><th>Sem</th><th>Sched</th><th>Code</th><th>Description</th><th>Units</th><th>Grade</th><th></th></tr></thead>
        <tbody><?php foreach ($gradeRows as $row): ?>
        <tr>
            <td style="font-size:12px;"><?= h($row['year_label']) ?></td>
            <td style="font-size:12px;"><?= h(semester_label((string) $row['semester'])) ?></td>
            <td><span class="badge" style="font-family:monospace;font-size:11px;"><?= h($row['sched_code'] ?? '—') ?></span></td>
            <td style="font-size:12px;"><?= h($row['subject_code']) ?></td>
            <td style="font-size:12px;"><?= h($row['subject_description']) ?></td>
            <td><?= h($row['units']) ?></td>
            <td>
                <form class="inline-form" method="post" style="display:flex;gap:4px;align-items:center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_grade">
                    <input type="hidden" name="student_id" value="<?= $studentId ?>">
                    <input type="hidden" name="student_subject_id" value="<?= h($row['student_subject_id']) ?>">
                    <input type="text" name="final_grade" value="<?= h($row['final_grade'] ?? '') ?>" style="width:70px;font-size:12px;">
                    <button class="btn small" type="submit" style="font-size:10px;padding:2px 8px;">Save</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
render_page('Student Profile', 'Students', (string) ob_get_clean());
