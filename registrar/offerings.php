<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
$currentUser = require_role(['admin', 'registrar', 'chair', 'instructor']);
$role = $currentUser['role'] ?? '';
$canManage = in_array($role, ['admin', 'registrar'], true);

$deptScopeId = 0;
if (!$canManage) {
    $staff = current_staff();
    $deptScopeId = (int) ($staff['dept_id'] ?? 0);
}

/* ══════════════════════════════════════════════════════════════════════════
   POST handlers
   ══════════════════════════════════════════════════════════════════════════ */
if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_offering') {
        $termId   = (int) ($_POST['term_id'] ?? 0);
        $secId    = (int) ($_POST['section_id'] ?? 0);
        $currId   = (int) ($_POST['curriculum_id'] ?? 0);
        $subjId   = (int) ($_POST['subject_id'] ?? 0);
        $maxSlots = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($termId > 0 && $secId > 0 && $currId > 0 && $subjId > 0) {
            $dup = fetch_one(
                'SELECT id FROM section_subject_offerings WHERE term_id = :tid AND section_id = :sid AND subject_id = :subid',
                ['tid' => $termId, 'sid' => $secId, 'subid' => $subjId]
            );
            if ($dup !== null) {
                flash('error', 'This subject is already offered for this section in the selected term.');
            } else {
                $progRow = fetch_one(
                    'SELECT sec.program_id FROM sections sec WHERE sec.id = :sid',
                    ['sid' => $secId]
                );
                $schedCode = $progRow ? generate_sched_code_for_new_offering($termId, (int) $progRow['program_id']) : '';
                execute_sql(
                    'INSERT INTO section_subject_offerings
                        (term_id, section_id, curriculum_id, subject_id, max_slots, syllabus_path, sched_code, created_at)
                     VALUES (:tid, :sid, :cid, :subid, :ms, NULL, :sched_code, NOW())',
                    [
                        'tid' => $termId, 'sid' => $secId, 'cid' => $currId, 'subid' => $subjId, 'ms' => $maxSlots,
                        'sched_code' => $schedCode,
                    ]
                );
                flash('success', 'Subject offering created.');
            }
        }
    }

    if ($action === 'update_offering') {
        $offId    = (int) ($_POST['offering_id'] ?? 0);
        $maxSlots = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($offId > 0) {
            execute_sql(
                'UPDATE section_subject_offerings SET max_slots = :ms WHERE id = :oid',
                ['ms' => $maxSlots, 'oid' => $offId]
            );
            flash('success', 'Offering updated.');
        }
    }

    if ($action === 'bulk_create_offerings') {
        $termId  = (int) ($_POST['bulk_term_id'] ?? 0);
        $programId = (int) ($_POST['bulk_program_id'] ?? 0);
        $yearLevel = (int) ($_POST['bulk_year_level'] ?? 0);
        $curriculumIds = $_POST['bulk_curriculum_ids'] ?? [];

        if ($termId <= 0 || $programId <= 0 || $yearLevel <= 0 || $curriculumIds === []) {
            flash('error', 'Please select a term, program, year level, and at least one curriculum line.');
        } else {
            $sections = fetch_all(
                'SELECT id FROM sections WHERE program_id = :pid AND year_level = :yr AND COALESCE(status, "active") = "active"',
                ['pid' => $programId, 'yr' => $yearLevel]
            );
            $created = 0; $skipped = 0;
            foreach ($sections as $sec) {
                $secId = (int) $sec['id'];
                foreach ((array) $curriculumIds as $cid) {
                    $cid = (int) $cid;
                    $cur = fetch_one('SELECT subject_id FROM program_curriculum WHERE curriculum_id = :id', ['id' => $cid]);
                    if ($cur === null) { $skipped++; continue; }

                    $exists = fetch_one(
                        'SELECT id FROM section_subject_offerings WHERE term_id = :tid AND section_id = :sid AND subject_id = :subid',
                        ['tid' => $termId, 'sid' => $secId, 'subid' => (int) $cur['subject_id']]
                    );
                    if ($exists !== null) { $skipped++; continue; }

                    $schedCode = generate_sched_code_for_new_offering($termId, $programId);

                    execute_sql(
                        'INSERT INTO section_subject_offerings
                            (term_id, section_id, curriculum_id, subject_id, max_slots, syllabus_path, sched_code, created_at)
                         VALUES (:tid, :sid, :cid, :subid, NULL, NULL, :sched_code, NOW())',
                        [
                            'tid' => $termId, 'sid' => $secId, 'cid' => $cid,
                            'subid' => (int) $cur['subject_id'], 'sched_code' => $schedCode,
                        ]
                    );
                    $created++;
                }
            }
            $msg = "{$created} offering(s) created across " . count($sections) . " section(s)";
            if ($skipped > 0) $msg .= ", {$skipped} already exist";
            flash('success', $msg . '.');
        }
    }

    if ($action === 'delete_offering') {
        $oid = (int) ($_POST['offering_id'] ?? 0);
        if ($oid > 0) {
            execute_sql('UPDATE section_subject_offerings SET status = \'inactive\' WHERE id = :id', ['id' => $oid]);
            flash('success', 'Offering removed.');
        }
    }

    if ($action === 'bulk_delete_offerings' && $canManage) {
        $ids = $_POST['offering_id'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            execute_sql("UPDATE section_subject_offerings SET status = 'inactive' WHERE id IN ({$ph})", $ids);
            flash('success', count($ids) . ' offering(s) deleted.');
        }
    }

    redirect('registrar/offerings.php');
}

/* ══════════════════════════════════════════════════════════════════════════
   Data
   ══════════════════════════════════════════════════════════════════════════ */
$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label,
            t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$allSections = fetch_all(
    'SELECT sec.id, p.programs_id AS program_id, p.program_code, sec.year_level, sec.section_name,
            d.department_code,
            CONCAT(p.program_code, " ", sec.year_level, sec.section_name) AS label
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN departments d ON d.dept_id = p.department_id
     WHERE COALESCE(sec.status, "active") = "active"
     ORDER BY d.department_code, p.program_code, sec.year_level, sec.section_name'
);

$programs = fetch_all(
    'SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code'
);

$filterTerm = (int) ($_GET['term_id'] ?? 0);
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterSection = (int) ($_GET['section_id'] ?? 0);

$sql = 'SELECT o.id, ay.year_label, t.semester, t.is_active AS term_active,
               p.programs_id, p.program_code, p.program_name,
               d.department_code,
               sec.id AS section_id, sec.year_level, sec.section_name,
               sub.subject_id, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
               td.department_code AS teaching_dept_code,
               o.max_slots, o.sched_code,
               o.curriculum_id, o.term_id, o.section_id AS off_section_id
        FROM section_subject_offerings o
        INNER JOIN academic_terms t ON t.id = o.term_id
        INNER JOIN academic_years ay ON ay.id = t.academic_year_id
        INNER JOIN sections sec ON sec.id = o.section_id
        INNER JOIN programs p ON p.programs_id = sec.program_id
        INNER JOIN departments d ON d.dept_id = p.department_id
        INNER JOIN subjects sub ON sub.subject_id = o.subject_id
        LEFT JOIN departments td ON td.dept_id = sub.teaching_department_id
        WHERE COALESCE(o.status, "active") = "active"';
$params = [];

if ($filterTerm > 0) {
    $sql .= ' AND o.term_id = :term_id';
    $params['term_id'] = $filterTerm;
}
if ($filterProgram > 0) {
    $sql .= ' AND p.programs_id = :program_id';
    $params['program_id'] = $filterProgram;
}
if ($filterSection > 0) {
    $sql .= ' AND o.section_id = :section_id';
    $params['section_id'] = $filterSection;
}

$sql .= ' ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), p.program_code, sec.year_level, sec.section_name, sub.subject_code';
$offerings = fetch_all($sql, $params);

$instructors = fetch_all(
    'SELECT st.staff_id, st.full_name, d.department_code
     FROM staff st
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     WHERE st.status = "active"
     ORDER BY st.full_name'
);

$grouped = [];
foreach ($offerings as $off) {
    $key = $off['year_label'] . ' / ' . semester_label((string) $off['semester']);
    if (!isset($grouped[$key])) $grouped[$key] = [];
    $grouped[$key][] = $off;
}

$activeTerm = null;
foreach ($terms as $t) {
    if ((int) $t['is_active'] === 1) { $activeTerm = $t; break; }
}

$termSemesterMap = [];
foreach ($terms as $t) {
    $termSemesterMap[(int) $t['id']] = $t['semester'];
}

$curriculumByProgramYearSem = [];
foreach ($programs as $prog) {
    $lines = fetch_all(
        'SELECT pc.curriculum_id, pc.subject_id, pc.year_level, pc.semester,
                sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code',
        ['pid' => (int) $prog['programs_id']]
    );
    foreach ($lines as $line) {
        $k = $prog['programs_id'] . '|' . $line['year_level'] . '|' . $line['semester'];
        if (!isset($curriculumByProgramYearSem[$k])) $curriculumByProgramYearSem[$k] = [];
        $curriculumByProgramYearSem[$k][] = $line;
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Subject Offerings</h1>
        <p>Manage subjects offered per section and term. Day/time/room/instructor are assigned via the Class Schedules module.</p>
    </div>
    <?php if ($canManage): ?>
    <div class="actions-row">
        <button class="btn" data-open="addOfferingModal">Add Offering</button>
        <button class="btn secondary" data-open="bulkOfferingModal">Bulk Create</button>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <form method="get">
        <div class="filter-bar">
            <div>
                <label>Academic Year / Semester</label>
                <select name="term_id">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?><?= (int)$t['is_active'] === 1 ? ' (Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Program</label>
                <select name="program_id">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h((string)$p['programs_id']) ?>" <?= $filterProgram === (int)$p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Section</label>
                <select name="section_id">
                    <option value="">All Sections</option>
                    <?php foreach ($allSections as $s): ?>
                        <option value="<?= h((string)$s['id']) ?>" <?= $filterSection === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn secondary" type="submit">Apply Filters</button>
            <?php if ($filterTerm > 0 || $filterProgram > 0 || $filterSection > 0): ?>
                <a class="btn small secondary" href="<?= h(app_url('registrar/offerings.php')) ?>">Clear Filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($grouped)): ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--muted);">
        <span class="material-symbols-outlined" style="font-size:40px;">calendar_month</span>
        <p>No offerings found. Click <strong>Add Offering</strong> or <strong>Bulk Create</strong> to set up subject offerings.</p>
    </div>
<?php else: ?>

<!-- Offerings grouped by term -->
<?php foreach ($grouped as $termLabel => $termOfferings): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--primary);">event</span>
        <?= h($termLabel) ?>
        <span class="badge info" style="font-size:11px;margin-left:8px;"><?= h((string)count($termOfferings)) ?> offerings</span>
    </h3>

    <div class="dt" data-dt-page-size="15"
         data-dt-bulk-delete-url="<?= h(app_url('registrar/offerings.php')) ?>"
         data-dt-bulk-id-field="offering_id"
         data-dt-bulk-action="bulk_delete_offerings"
         data-dt-bulk-confirm="Delete selected offerings?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                        <th data-dt-key="section" data-dt-filter="select">Section</th>
                        <th data-dt-key="sched_code">Sched Code</th>
                        <th data-dt-key="code" data-dt-filter="select">Code</th>
                        <th data-dt-key="desc">Description</th>
                        <th data-dt-key="dept" data-dt-filter="select">Teaching Dept</th>
                        <th data-dt-key="units">Units</th>
                        <th data-dt-key="slots">Slots</th>
                        <?php if ($canManage): ?><th data-dt-no-sort data-dt-no-export>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($termOfferings as $off): ?>
                    <tr data-dt-row-id="<?= h((string)$off['id']) ?>">
                        <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$off['id']) ?>" aria-label="Select row"></td>
                        <td data-label="Section"><strong><?= h($off['program_code'] . ' ' . $off['year_level'] . $off['section_name']) ?></strong></td>
                        <td data-label="Sched Code"><span style="font-family:monospace;font-size:12px;"><?= h($off['sched_code'] ?? '—') ?></span></td>
                        <td data-label="Code"><span class="badge"><?= h($off['subject_code']) ?></span></td>
                        <td data-label="Description"><?= h($off['subject_description']) ?></td>
                        <td data-label="Teaching Dept"><span class="badge"><?= h($off['teaching_dept_code'] ?: '—') ?></span></td>
                        <td data-label="Units"><?= h((string)$off['units']) ?></td>
                        <td data-label="Slots"><?= h((string)($off['max_slots'] ?? '—')) ?></td>
                        <?php if ($canManage): ?>
                        <td>
                            <div class="row-actions">
                                <button class="icon-btn" type="button" title="Edit"
                                    onclick='openEditOffering(<?= json_encode([
                                        "id"=>$off["id"],
                                        "max_slots"=>(string)($off["max_slots"] ?? "")
                                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                    <span class="material-symbols-outlined">edit</span>
                                </button>
                                <form method="post" onsubmit="return confirm('Remove this offering?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_offering">
                                    <input type="hidden" name="offering_id" value="<?= h($off['id']) ?>">
                                    <button class="icon-btn danger" type="submit" title="Remove"><span class="material-symbols-outlined">delete</span></button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Add Offering Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$termOptions = '<option value="">— Select Term —</option>';
foreach ($terms as $t) {
    $termOptions .= '<option value="' . h((string)$t['id']) . '">' . h($t['label']) . '</option>';
}

$sectionOptions = '<option value="">— Select Section —</option>';
foreach ($allSections as $s) {
    $sectionOptions .= '<option value="' . h((string)$s['id']) . '" data-year="' . h((string)$s['year_level']) . '" data-program="' . h((string)$s['program_id']) . '">' . h($s['label']) . '</option>';
}
?>
<?= render_modal('addOfferingModal', 'Add Section Offering', '
<form method="post">
    <input type="hidden" name="action" value="add_offering">
    <div class="form-grid">
        <div>
            <label>Academic Year / Semester</label>
            <select name="term_id" required>
                ' . $termOptions . '
            </select>
        </div>
        <div>
            <label>Section</label>
            <select name="section_id" id="offering_section" required>
                ' . $sectionOptions . '
            </select>
        </div>
        <div>
            <label>Curriculum Line</label>
            <select name="curriculum_id" id="offering_curriculum" required>
                <option value="">Select curriculum line...</option>
            </select>
        </div>
        <div>
            <label>Subject</label>
            <input type="text" id="offering_subject_display" readonly placeholder="Auto-filled from curriculum">
            <input type="hidden" name="subject_id" id="offering_subject_id">
        </div>
        <div>
            <label>Instructor</label>
            <select name="instructor_id">
                <option value="">— TBA —</option>
                ' . implode('', array_map(fn($i) => '<option value="' . h((string)$i['staff_id']) . '">' . h($i['full_name'] . ' [' . ($i['department_code'] ?: 'No Dept') . ']') . '</option>', $instructors)) . '
            </select>
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" placeholder="Optional" min="1">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Create Offering</button>
    </div>
</form>
<script>
(function(){
    var secSel = document.getElementById(\'offering_section\');
    var currSel = document.getElementById(\'offering_curriculum\');
    var subjDisp = document.getElementById(\'offering_subject_display\');
    var subjId = document.getElementById(\'offering_subject_id\');
    var termSel = document.querySelector(\'[name="term_id"]\');
    if (!secSel || !currSel) return;

    var allCurriculum = ' . json_encode($curriculumByProgramYearSem, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';
    var termSemester = ' . json_encode($termSemesterMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';
    var semesterMap = {"1":"1st","2":"2nd","mid":"mid"};

    function filterCurriculum() {
        var secOpt = secSel.options[secSel.selectedIndex];
        var termOpt = termSel ? termSel.options[termSel.selectedIndex] : null;
        if (!secOpt || !secOpt.value) {
            currSel.innerHTML = \'<option value="">Select curriculum line...</option>\';
            return;
        }
        var progId = secOpt.dataset.program || \'\';
        var yr = secOpt.dataset.year || \'\';
        var sem = \'\';
        if (termOpt && termOpt.value && termSemester[termOpt.value]) {
            sem = semesterMap[termSemester[termOpt.value]] || \'\';
        }
        var key = progId + \'|\' + yr + (sem ? \'|\' + sem : \'\');
        var lines = allCurriculum[key] || [];
        currSel.innerHTML = \'<option value="">Select curriculum line...</option>\';
        lines.forEach(function(l) {
            var opt = document.createElement(\'option\');
            opt.value = l.curriculum_id;
            opt.textContent = \'Y\' + l.year_level + \' \' + l.semester + \' — \' + l.subject_code + \' \' + l.subject_description;
            opt.dataset.subject_id = l.subject_id;
            opt.dataset.subject_code = l.subject_code;
            opt.dataset.subject_desc = l.subject_description;
            currSel.appendChild(opt);
        });
    }
    secSel.addEventListener(\'change\', function() {
        filterCurriculum();
        if (subjDisp) subjDisp.value = \'\';
        if (subjId) subjId.value = \'\';
    });
    if (termSel) termSel.addEventListener(\'change\', function() {
        filterCurriculum();
        if (subjDisp) subjDisp.value = \'\';
        if (subjId) subjId.value = \'\';
    });
    currSel.addEventListener(\'change\', function() {
        var opt = currSel.options[currSel.selectedIndex];
        if (subjDisp && opt && opt.value) {
            subjDisp.value = opt.dataset.subject_code + \' — \' + opt.dataset.subject_desc;
        }
        if (subjId && opt && opt.value) {
            subjId.value = opt.dataset.subject_id;
        }
    });
})();
</script>
') ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Edit Offering Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?= render_modal('editOfferingModal', 'Edit Offering', '
<form method="post">
    <input type="hidden" name="action" value="update_offering">
    <input type="hidden" name="offering_id" id="eo_id">
    <div class="form-grid">
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" id="eo_max_slots" placeholder="Optional" min="1">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Update Offering</button>
    </div>
</form>
') ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Bulk Create Offerings Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$curriculumListHtml = '';
foreach ($programs as $prog) {
    foreach ($curriculumByProgramYearSem as $key => $lines) {
        $parts = explode('|', $key);
        $progId = (int) ($parts[0] ?? 0);
        $year = $parts[1] ?? '';
        $sem = $parts[2] ?? '';
        if ($progId !== (int) $prog['programs_id']) continue;
        foreach ($lines as $line) {
            $curriculumListHtml .= '<option value="' . h((string)$line['curriculum_id']) . '" data-year="' . h($year) . '" data-program="' . h((string)$progId) . '" data-semester="' . h($sem) . '">'
                . h($prog['program_code'] . ' Y' . $year . ' ' . $line['semester'] . ' — ' . $line['subject_code'] . ' ' . $line['subject_description'])
                . '</option>';
        }
    }
}
$bulkProgramOptions = '<option value="">— Select Program —</option>';
foreach ($programs as $bp) {
    $bulkProgramOptions .= '<option value="' . h((string)$bp['programs_id']) . '">' . h($bp['program_code'] . ' — ' . $bp['program_name']) . '</option>';
}
?>
<?= render_modal('bulkOfferingModal', 'Bulk Create Offerings', '
<form method="post">
    <input type="hidden" name="action" value="bulk_create_offerings">
    <div class="form-grid cols-3">
        <div>
            <label>Academic Year / Semester</label>
            <select name="bulk_term_id" id="bulk_term" required>
                ' . $termOptions . '
            </select>
        </div>
        <div>
            <label>Program</label>
            <select name="bulk_program_id" id="bulk_program" required>
                ' . $bulkProgramOptions . '
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="bulk_year_level" id="bulk_year" required>
                <option value="">— Select Year —</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
            </select>
        </div>
    </div>
    <div style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <label style="margin:0;">Select Curriculum Lines</label>
            <div>
                <button type="button" class="btn small secondary" onclick="selectAllBulk()">Select All</button>
                <button type="button" class="btn small secondary" onclick="deselectAllBulk()">Deselect All</button>
                <span id="bulk_selected_count" class="helper" style="margin-left:8px;">0 selected</span>
            </div>
        </div>
        <div id="bulk_curriculum_list" style="max-height:300px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:8px;">
            <p class="helper">Select a term, program, and year level to load curriculum lines.</p>
        </div>
    </div>
    <div class="form-actions" style="margin-top:14px;">
        <button class="btn" type="submit">Create All Selected Offerings</button>
    </div>
</form>
<script>
var bulkCurriculumData = ' . json_encode($curriculumByProgramYearSem, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';
var bulkTermSemester = ' . json_encode($termSemesterMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';
var bulkSemesterMap = {"1":"1st","2":"2nd","mid":"mid"};

function loadBulkCurriculum() {
    var progSel = document.getElementById(\'bulk_program\');
    var yrSel = document.getElementById(\'bulk_year\');
    var termSel = document.getElementById(\'bulk_term\');
    var listDiv = document.getElementById(\'bulk_curriculum_list\');

    var progId = progSel ? progSel.value : \'\';
    var yr = yrSel ? yrSel.value : \'\';
    var termVal = termSel ? termSel.value : \'\';

    if (!progId || !yr || !termVal) {
        listDiv.innerHTML = \'<p class="helper">Select a term, program, and year level to load curriculum lines.</p>\';
        return;
    }

    var sem = \'\';
    if (bulkTermSemester[termVal]) {
        sem = bulkSemesterMap[bulkTermSemester[termVal]] || \'\';
    }
    var key = progId + \'|\' + yr + (sem ? \'|\' + sem : \'\');
    var lines = bulkCurriculumData[key] || [];

    listDiv.innerHTML = \'\';
    if (lines.length === 0) {
        listDiv.innerHTML = \'<p class="helper">No curriculum lines found for this program and semester.</p>\';
        return;
    }
    lines.forEach(function(l) {
        var div = document.createElement(\'div\');
        div.style.cssText = \'display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);\';
        div.innerHTML = \'<input type="checkbox" class="bulk-curr-check" name="bulk_curriculum_ids[]" value="\' + l.curriculum_id + \'" onchange="updateCount()"><span style="font-size:13px;"><strong>\' + l.subject_code + \'</strong> \' + l.subject_description + \' (\' + l.units + \'u)</span>\';
        listDiv.appendChild(div);
    });
    updateCount();
}

document.getElementById(\'bulk_program\').addEventListener(\'change\', loadBulkCurriculum);
document.getElementById(\'bulk_year\').addEventListener(\'change\', loadBulkCurriculum);
document.getElementById(\'bulk_term\').addEventListener(\'change\', loadBulkCurriculum);

function selectAllBulk() {
    document.querySelectorAll(\'.bulk-curr-check\').forEach(function(c) { c.checked = true; });
    updateCount();
}
function deselectAllBulk() {
    document.querySelectorAll(\'.bulk-curr-check\').forEach(function(c) { c.checked = false; });
    updateCount();
}
function updateCount() {
    var n = document.querySelectorAll(\'.bulk-curr-check:checked\').length;
    document.getElementById(\'bulk_selected_count\').textContent = n + \' selected\';
}
</script>
') ?>

<?php
$content = ob_get_clean();
render_page('Subject Offerings', 'Subject Offerings', (string) $content, [
    'modals' => []
]);
?>
<script>
function openEditOffering(o) {
    document.getElementById('eo_id').value = o.id;
    document.getElementById('eo_max_slots').value = o.max_slots || '';
    document.getElementById('editOfferingModal').classList.add('active');
}
</script>
