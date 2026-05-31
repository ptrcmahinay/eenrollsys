<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$currentTerm = current_term();
if ($currentTerm === null) {
    flash('error', 'No active academic term.');
    redirect('registrar/dashboard.php');
}

$lookupStudent = null;
$lookupError = '';
$offerings = [];
$selectedSectionId = 0;

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'lookup') {
        $studentNumber = trim($_POST['student_number'] ?? '');
        if ($studentNumber !== '') {
            $lookupStudent = fetch_one(
                'SELECT s.*, p.program_code, p.programs_id, sec.section_name
                 FROM students s
                 INNER JOIN programs p ON p.programs_id = s.program_id
                 LEFT JOIN sections sec ON sec.id = s.section_id
                 WHERE s.student_number = :sn',
                ['sn' => $studentNumber]
            );
            if ($lookupStudent === null) {
                $lookupError = 'Student not found.';
            }
        }
    }

    if ($action === 'direct_enroll' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $termId = (int) ($_POST['term_id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $offeringIds = $_POST['offering_ids'] ?? [];

        if ($studentId > 0 && $termId > 0 && $sectionId > 0 && !empty($offeringIds)) {
            db()->beginTransaction();
            try {
                execute_sql(
                    'INSERT INTO enrollment_requests (
                        student_id, term_id, requested_section_id, requested_status,
                        workflow_status, adviser_status, chair_status, registrar_status,
                        adviser_remark, chair_remark, registrar_remark,
                        ra10931_status, total_units, total_amount,
                        adviser_processed_at, chair_processed_at, registrar_processed_at
                     ) VALUES (
                        :student_id, :term_id, :section_id, "regular",
                        "registrar_approved", "approved", "approved", "approved",
                        "Direct enrollment by registrar", "Direct enrollment by registrar", "Direct enrollment by registrar",
                        "free", 0, 0,
                        NOW(), NOW(), NOW()
                     )',
                    [
                        'student_id' => $studentId,
                        'term_id' => $termId,
                        'section_id' => $sectionId,
                    ]
                );
                $requestId = (int) db()->lastInsertId();

                $totalUnits = 0;
                foreach ((array) $offeringIds as $offeringId) {
                    execute_sql(
                        'INSERT INTO enrollment_request_items (request_id, offering_id, action_type) VALUES (:request_id, :offering_id, "add")',
                        ['request_id' => $requestId, 'offering_id' => (int) $offeringId]
                    );

                    $offering = fetch_one(
                        'SELECT o.subject_id, o.curriculum_id, (sub.lec_credit + sub.lab_credit) AS units FROM section_subject_offerings o INNER JOIN subjects sub ON sub.subject_id = o.subject_id WHERE o.id = :id',
                        ['id' => (int) $offeringId]
                    );
                    if ($offering) {
                        $totalUnits += (float) $offering['units'];
                        $exists = fetch_one(
                            'SELECT id FROM student_subjects WHERE student_id = :sid AND term_id = :tid AND offering_id = :oid LIMIT 1',
                            ['sid' => $studentId, 'tid' => $termId, 'oid' => (int) $offeringId]
                        );
                        if ($exists === null) {
                            execute_sql(
                                'INSERT INTO student_subjects (student_id, term_id, offering_id, curriculum_id, subject_id, section_id, units, enrollment_status, final_grade, created_at, updated_at)
                                 VALUES (:sid, :tid, :oid, :cid, :subid, :secid, :units, "enrolled", NULL, NOW(), NOW())',
                                [
                                    'sid' => $studentId, 'tid' => $termId, 'oid' => (int) $offeringId,
                                    'cid' => (int) $offering['curriculum_id'], 'subid' => (int) $offering['subject_id'],
                                    'secid' => $sectionId, 'units' => $offering['units'],
                                ]
                            );
                        }
                    }
                }

                execute_sql(
                    'UPDATE enrollment_requests SET total_units = :tu WHERE id = :rid',
                    ['tu' => $totalUnits, 'rid' => $requestId]
                );

                log_audit($requestId, 'registrar_direct_enroll', 'registrar', null, 'registrar_approved', 'Direct enrollment bypassing workflow');

                sync_student_section($studentId, $sectionId);
                db()->commit();

                $student = fetch_one('SELECT full_name FROM students WHERE id = :id', ['id' => $studentId]);
                flash('success', ($student ? $student['full_name'] : 'Student') . ' directly enrolled with ' . $totalUnits . ' units.');
                redirect('registrar/direct_enroll.php');
            } catch (Throwable $e) {
                db()->rollBack();
                flash('error', 'Failed to enroll: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Please fill in all required fields and select at least one subject.');
        }
    }
}

if ($lookupStudent) {
    $offerings = fetch_all(
        'SELECT o.id, o.section_id, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
                o.day_of_week, o.time_range, o.room, sec.section_name,
                CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
         FROM section_subject_offerings o
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN sections sec ON sec.id = o.section_id
         LEFT JOIN staff st ON st.staff_id = o.instructor_id
         WHERE o.term_id = :tid AND o.section_id = :secid
         ORDER BY sub.subject_code',
        ['tid' => (int) $currentTerm['id'], 'secid' => (int) ($lookupStudent['section_id'] ?: 0)]
    );
    $selectedSectionId = (int) ($lookupStudent['section_id'] ?: 0);

    if ($selectedSectionId === 0) {
        $sections = fetch_all(
            'SELECT id, program_id, year_level, section_name FROM sections WHERE program_id = :pid AND year_level = :yl ORDER BY section_name',
            ['pid' => (int) $lookupStudent['program_id'], 'yl' => (int) $lookupStudent['year_level']]
        );
        if (!empty($sections)) {
            $selectedSectionId = (int) $sections[0]['id'];
            $offerings = fetch_all(
                'SELECT o.id, o.section_id, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
                        o.day_of_week, o.time_range, o.room, sec.section_name,
                        CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
                 FROM section_subject_offerings o
                 INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                 INNER JOIN sections sec ON sec.id = o.section_id
                 LEFT JOIN staff st ON st.staff_id = o.instructor_id
                 WHERE o.term_id = :tid AND o.section_id = :secid
                 ORDER BY sub.subject_code',
                ['tid' => (int) $currentTerm['id'], 'secid' => $selectedSectionId]
            );
        }
    }
}

$allSections = fetch_all('SELECT id, program_code, year_level, section_name FROM sections INNER JOIN programs ON programs.programs_id = sections.program_id ORDER BY program_code, year_level, section_name');

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Direct Enrollment (Override)</h1>
        <p>Enroll a student directly, bypassing the standard approval workflow. Use for special cases only.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/enrollment.php')) ?>">Back to Enrollment Queue</a>
    </div>
</div>

<div class="card">
    <h3>Step 1: Lookup Student</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="lookup">
        <div style="display:flex;gap:10px;align-items:flex-end;">
            <div>
                <label>Student Number</label>
                <input type="text" name="student_number" value="<?= h($_POST['student_number'] ?? '') ?>" placeholder="e.g. 20250001" required style="width:200px;">
            </div>
            <button class="btn" type="submit">Lookup</button>
        </div>
    </form>
    <?php if ($lookupError): ?>
        <p class="helper" style="color:var(--danger,#ef4444);margin-top:8px;"><?= h($lookupError) ?></p>
    <?php endif; ?>
</div>

<?php if ($lookupStudent): ?>
<div class="card" style="margin-top:16px;">
    <h3>Step 2: Direct Enrollment Form</h3>
    <div class="form-grid cols-2" style="margin-top:12px;">
        <div class="card slim">
            <h4>Student Details</h4>
            <div class="kv-list">
                <div class="item"><div class="k">Student Number</div><div class="v"><?= h($lookupStudent['student_number']) ?></div></div>
                <div class="item"><div class="k">Full Name</div><div class="v"><?= h($lookupStudent['full_name']) ?></div></div>
                <div class="item"><div class="k">Program</div><div class="v"><?= h($lookupStudent['program_code']) ?></div></div>
                <div class="item"><div class="k">Year Level</div><div class="v"><?= h($lookupStudent['year_level']) ?></div></div>
                <div class="item"><div class="k">Term</div><div class="v"><?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></div></div>
            </div>
        </div>
        <div class="card slim">
            <h4>Section Selection</h4>
            <div>
                <select id="directSectionSelect" style="width:100%;">
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?= h($sec['id']) ?>" <?= $selectedSectionId === (int) $sec['id'] ? 'selected' : '' ?>><?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <form method="post" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="direct_enroll">
        <input type="hidden" name="student_id" value="<?= h($lookupStudent['id']) ?>">
        <input type="hidden" name="term_id" value="<?= h($currentTerm['id']) ?>">
        <input type="hidden" name="section_id" id="directSectionId" value="<?= h($selectedSectionId) ?>">

        <h4>Subjects to Enroll</h4>
        <?php if (empty($offerings)): ?>
            <p class="helper">No subject offerings found for the selected section and term.</p>
        <?php else: ?>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <button type="button" class="btn small secondary" onclick="document.querySelectorAll('.direct-subject').forEach(c=>c.checked=true)">Select All</button>
                <button type="button" class="btn small secondary" onclick="document.querySelectorAll('.direct-subject').forEach(c=>c.checked=false)">Deselect All</button>
                <span class="badge info" id="directUnitCounter">0 units selected</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Select</th><th>Code</th><th>Description</th><th>Units</th><th>Schedule</th><th>Room</th><th>Instructor</th></tr></thead>
                    <tbody>
                    <?php foreach ($offerings as $off): ?>
                        <tr>
                            <td><input type="checkbox" name="offering_ids[]" value="<?= h($off['id']) ?>" class="direct-subject" data-units="<?= h($off['units']) ?>"></td>
                            <td><?= h($off['subject_code']) ?></td>
                            <td><?= h($off['subject_description']) ?></td>
                            <td><?= h($off['units']) ?></td>
                            <td><?= h(trim(($off['day_of_week'] ?: 'TBA') . ' ' . ($off['time_range'] ?: ''))) ?></td>
                            <td><?= h($off['room'] ?: 'TBA') ?></td>
                            <td><?= h($off['instructor_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:16px;">
            <button class="btn danger" type="submit" onclick="return confirm('This will directly enroll the student, bypassing all approval stages. Continue?')">Directly Enroll Student</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sectionSel = document.getElementById('directSectionSelect');
    var sectionIdInput = document.getElementById('directSectionId');
    if (sectionSel) {
        sectionSel.addEventListener('change', function() {
            sectionIdInput.value = sectionSel.value;
        });
    }

    var unitCounter = document.getElementById('directUnitCounter');
    if (unitCounter) {
        document.querySelectorAll('.direct-subject').forEach(function(c) {
            c.addEventListener('change', function() {
                var units = 0;
                document.querySelectorAll('.direct-subject:checked').forEach(function(ch) {
                    units += parseFloat(ch.getAttribute('data-units')) || 0;
                });
                unitCounter.textContent = units + ' units selected';
            });
        });
    }
});
</script>
<?php endif; ?>

<style>
.kv-list{list-style:none;padding:0;margin:0}
.kv-list .item{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line)}
.kv-list .item:last-child{border-bottom:none}
.kv-list .k{color:var(--muted,#64748b);font-size:13px;min-width:120px}
.kv-list .v{font-size:13px;font-weight:600;text-align:right}
</style>
<?php
render_page('Direct Enrollment', 'Direct Enroll', (string) ob_get_clean());
