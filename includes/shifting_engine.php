<?php
declare(strict_types=1);

/**
 * Student Shifting & Transferee Engine
 *
 * Handles:
 * - Shifting workflow (student request → adviser → chairs → registrar → placement)
 * - Transferee processing (registrar-driven)
 * - Academic placement (year level, standing, regular/irregular, section)
 * - Subject eligibility engine
 * - Curriculum evaluation & subject equivalencies
 */

/* =========================================================================
   SHIFTING WORKFLOW
   ========================================================================= */

function create_shifting_request(
    int $studentId,
    int $currentProgramId,
    int $targetProgramId,
    int $currentYearLevel,
    ?int $targetYearLevel,
    string $reason
): int {
    execute_sql(
        'INSERT INTO shifting_requests (
            student_id, current_program_id, target_program_id,
            current_year_level, target_year_level, reason,
            workflow_status, adviser_status, current_chair_status, target_chair_status, registrar_status
         ) VALUES (
            :sid, :current_pid, :target_pid,
            :current_yl, :target_yl, :reason,
            "submitted", "pending", "pending", "pending", "pending"
         )',
        [
            'sid'        => $studentId,
            'current_pid'=> $currentProgramId,
            'target_pid' => $targetProgramId,
            'current_yl' => $currentYearLevel,
            'target_yl'  => $targetYearLevel,
            'reason'     => $reason,
        ]
    );

    $requestId = (int) db()->lastInsertId();
    log_audit($requestId, 'student_submit', 'student', null, 'submitted', null);

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $studentId]);
    $targetProgram = fetch_one('SELECT program_name FROM programs WHERE programs_id = :id', ['id' => $targetProgramId]);
    $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

    notify_staff_by_role('adviser',
        'Shifting Request Pending Review',
        $label . ' has requested to shift to ' . ($targetProgram ? $targetProgram['program_name'] : 'another program') . '. Please review.'
    );

    return $requestId;
}

function shifting_adviser_review(int $requestId, string $action, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'submitted') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'current_chair_review' : 'rejected';

    execute_sql(
        'UPDATE shifting_requests SET
            adviser_status = :status, adviser_remark = :remark, adviser_processed_at = NOW(), adviser_processed_by = :uid,
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'ws' => $nextWorkflow, 'uid' => (int) ($_SESSION['user_id'] ?? 0), 'id' => $requestId]
    );

    log_audit($requestId, 'adviser_review', 'adviser', 'submitted', $nextWorkflow, $remark);

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $req['student_id']]);
    $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Adviser Approved',
            'Your shifting request has been approved by your adviser and is now with your current department chair for review.'
        );

        $currentDept = fetch_one(
            'SELECT d.department_name, d.chair_id FROM programs p INNER JOIN departments d ON d.dept_id = p.department_id WHERE p.programs_id = :pid',
            ['pid' => $req['current_program_id']]
        );
        if ($currentDept && $currentDept['chair_id']) {
            create_notification('staff', (int) $currentDept['chair_id'], 'info',
                'Shifting Request — Current Department Review',
                $label . ' is requesting to shift from your department. Please review.'
            );
        }
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Adviser Declined',
            'Your shifting request was not approved by your adviser. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function shifting_current_chair_review(int $requestId, string $action, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'current_chair_review') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'target_chair_review' : 'rejected';

    execute_sql(
        'UPDATE shifting_requests SET
            current_chair_status = :status, current_chair_remark = :remark,
            current_chair_processed_at = NOW(), current_chair_processed_by = :uid,
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'ws' => $nextWorkflow, 'uid' => (int) ($_SESSION['user_id'] ?? 0), 'id' => $requestId]
    );

    log_audit($requestId, 'current_chair_review', 'department_chair', 'current_chair_review', $nextWorkflow, $remark);

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $req['student_id']]);
    $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Current Department Approved',
            'Your shifting request has been approved by your current department chair and is now with the target department for review.'
        );

        $targetDept = fetch_one(
            'SELECT d.department_name, d.chair_id FROM programs p INNER JOIN departments d ON d.dept_id = p.department_id WHERE p.programs_id = :pid',
            ['pid' => $req['target_program_id']]
        );
        if ($targetDept && $targetDept['chair_id']) {
            create_notification('staff', (int) $targetDept['chair_id'], 'info',
                'Shifting Request — Target Department Review',
                $label . ' wants to shift INTO your department. Please review.'
            );
        }
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Current Department Declined',
            'Your shifting request was not approved by your current department chair. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function shifting_target_chair_review(int $requestId, string $action, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'target_chair_review') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'registrar_review' : 'rejected';

    execute_sql(
        'UPDATE shifting_requests SET
            target_chair_status = :status, target_chair_remark = :remark,
            target_chair_processed_at = NOW(), target_chair_processed_by = :uid,
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'ws' => $nextWorkflow, 'uid' => (int) ($_SESSION['user_id'] ?? 0), 'id' => $requestId]
    );

    log_audit($requestId, 'target_chair_review', 'department_chair', 'target_chair_review', $nextWorkflow, $remark);

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $req['student_id']]);
    $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Target Department Approved',
            'Your shifting request has been approved by the target department chair and is now with the Registrar for review.'
        );

        notify_staff_by_role('registrar',
            'Shifting Request — Ready for Registrar Review',
            $label . ' has passed department reviews and is ready for registrar processing.'
        );
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Target Department Declined',
            'Your shifting request was not approved by the target department chair. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function shifting_registrar_review(int $requestId, string $action, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'registrar_review') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'curriculum_evaluation' : 'rejected';

    execute_sql(
        'UPDATE shifting_requests SET
            registrar_status = :status, registrar_remark = :remark,
            registrar_processed_at = NOW(), registrar_processed_by = :uid,
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'ws' => $nextWorkflow, 'uid' => (int) ($_SESSION['user_id'] ?? 0), 'id' => $requestId]
    );

    log_audit($requestId, 'registrar_review', 'registrar', 'registrar_review', $nextWorkflow, $remark);

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Registrar Approved',
            'Your shifting request has been approved by the Registrar. Curriculum evaluation is now in progress.'
        );
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Registrar Declined',
            'Your shifting request was not approved by the Registrar. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function process_shifting(int $requestId, int $registrarUserId): void
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'curriculum_evaluation') return;

    db()->beginTransaction();
    try {
        $studentId = (int) $req['student_id'];
        $currentProgramId = (int) $req['current_program_id'];
        $targetProgramId = (int) $req['target_program_id'];
        $currentTerm = current_term();
        $termId = $currentTerm ? (int) $currentTerm['id'] : 0;

        execute_sql(
            'UPDATE student_program_history SET term_ended = :tid, status = "shifted" WHERE student_id = :sid AND program_id = :pid AND status = "active"',
            ['tid' => $termId, 'sid' => $studentId, 'pid' => $currentProgramId]
        );

        $newCurriculum = fetch_one(
            'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid GROUP BY program_id ORDER BY curriculum_id DESC LIMIT 1',
            ['pid' => $targetProgramId]
        );

        execute_sql(
            'INSERT INTO student_program_history (student_id, program_id, curriculum_id, term_started, status, reason)
             VALUES (:sid, :pid, :cid, :tid, "active", :reason)',
            [
                'sid'    => $studentId,
                'pid'    => $targetProgramId,
                'cid'    => $newCurriculum ? (int) $newCurriculum['curriculum_id'] : null,
                'tid'    => $termId,
                'reason' => 'Approved shift from program ' . $currentProgramId . ' to ' . $targetProgramId,
            ]
        );

        execute_sql(
            'UPDATE students SET program_id = :pid, year_level = COALESCE(:yl, year_level) WHERE id = :sid',
            [
                'pid' => $targetProgramId,
                'yl'  => $req['target_year_level'] ?: null,
                'sid' => $studentId,
            ]
        );

        $evalNotes = $req['evaluation_notes'] ?? '';
        create_academic_placement(
            $studentId, $targetProgramId, $termId,
            'shifting', $evalNotes, $registrarUserId
        );

        execute_sql(
            'UPDATE shifting_requests SET workflow_status = "processed", processed_at = NOW(), processed_by = :uid, updated_at = NOW() WHERE id = :id',
            ['uid' => $registrarUserId, 'id' => $requestId]
        );

        log_audit($requestId, 'shifting_processed', 'registrar', 'curriculum_evaluation', 'processed', 'Program changed from ' . $currentProgramId . ' to ' . $targetProgramId);

        $targetProgram = fetch_one('SELECT program_name, program_code FROM programs WHERE programs_id = :id', ['id' => $targetProgramId]);
        send_enrollment_notification($studentId,
            'Shifting Request Approved — Program Changed',
            'Congratulations! Your shifting request has been processed. You are now enrolled in ' . ($targetProgram ? $targetProgram['program_name'] : 'the new program') . '. Please check your enrollment page for updated curriculum.'
        );

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/* =========================================================================
   SUBJECT EQUIVALENCIES
   ========================================================================= */

function save_subject_equivalencies(int $requestId, array $equivalencies): void
{
    execute_sql('DELETE FROM subject_equivalencies WHERE shifting_request_id = :rid', ['rid' => $requestId]);

    $ins = db()->prepare(
        'INSERT INTO subject_equivalencies (shifting_request_id, old_subject_id, new_subject_id, equivalency_type, credit_units, status, remarks)
         VALUES (:rid, :old_sid, :new_sid, :eq_type, :credits, :status, :remarks)'
    );

    foreach ($equivalencies as $eq) {
        $oldSid = (int) ($eq['old_subject_id'] ?? 0);
        if ($oldSid <= 0) continue;
        $ins->execute([
            'rid'      => $requestId,
            'old_sid'  => $oldSid,
            'new_sid'  => (int) $eq['new_subject_id'],
            'eq_type'  => $eq['equivalency_type'] ?? 'equivalent',
            'credits'  => (float) ($eq['credit_units'] ?? 0),
            'status'   => $eq['status'] ?? 'pending',
            'remarks'  => $eq['remarks'] ?? null,
        ]);
    }
}

/* =========================================================================
   PROGRAM HISTORY HELPERS
   ========================================================================= */

function get_current_student_program(int $studentId): ?array
{
    return fetch_one(
        'SELECT sph.*, p.program_code, p.program_name, p.department_id
         FROM student_program_history sph
         INNER JOIN programs p ON p.programs_id = sph.program_id
         WHERE sph.student_id = :sid AND sph.status = "active"
         ORDER BY sph.id DESC LIMIT 1',
        ['sid' => $studentId]
    );
}

function get_student_program_history(int $studentId): array
{
    return fetch_all(
        'SELECT sph.*, p.program_code, p.program_name,
                ay_start.year_label AS start_year_label, t_start.semester AS start_semester,
                ay_end.year_label AS end_year_label, t_end.semester AS end_semester
         FROM student_program_history sph
         INNER JOIN programs p ON p.programs_id = sph.program_id
         LEFT JOIN academic_terms t_start ON t_start.id = sph.term_started
         LEFT JOIN academic_years ay_start ON ay_start.id = t_start.academic_year_id
         LEFT JOIN academic_terms t_end ON t_end.id = sph.term_ended
         LEFT JOIN academic_years ay_end ON ay_end.id = t_end.academic_year_id
         WHERE sph.student_id = :sid
         ORDER BY sph.id',
        ['sid' => $studentId]
    );
}

/* =========================================================================
   CURRICULUM EVALUATION
   ========================================================================= */

function evaluate_shifting_curriculum(int $requestId): array
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req) return [];

    $studentId = (int) $req['student_id'];
    $targetProgramId = (int) $req['target_program_id'];

    $completedSubjects = fetch_all(
        'SELECT DISTINCT ss.subject_id, sub.subject_code, sub.subject_description,
                ss.final_grade, ss.term_id,
                ay.year_label, t.semester
         FROM student_subjects ss
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         INNER JOIN academic_terms t ON t.id = ss.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE ss.student_id = :sid AND ss.enrollment_status = "enrolled"
           AND ss.final_grade IS NOT NULL AND ss.final_grade != ""
           AND NOT ss.final_grade IN ("5.00", "F", "DRP", "W")
         ORDER BY sub.subject_code',
        ['sid' => $studentId]
    );

    $newCurriculum = fetch_all(
        'SELECT pc.subject_id, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY sub.subject_code',
        ['pid' => $targetProgramId]
    );

    $existingEquivalencies = fetch_all(
        'SELECT * FROM subject_equivalencies WHERE shifting_request_id = :rid',
        ['rid' => $requestId]
    );
    $equivMap = [];
    foreach ($existingEquivalencies as $eq) {
        $equivMap[(int) $eq['new_subject_id']] = $eq;
    }

    $completedMap = [];
    foreach ($completedSubjects as $cs) {
        $completedMap[(int) $cs['subject_id']] = $cs;
    }

    $evaluation = [];
    foreach ($newCurriculum as $nc) {
        $newSid = (int) $nc['subject_id'];
        $status = 'to_take';
        $matchedSubject = null;
        $creditUnits = 0;

        if (isset($equivMap[$newSid])) {
            $eq = $equivMap[$newSid];
            $status = $eq['status'] === 'approved' ? 'credited' : ($eq['equivalency_type'] === 'not_equivalent' ? 'to_take' : 'for_evaluation');
            $creditUnits = (float) $eq['credit_units'];
            $oldSub = fetch_one('SELECT subject_id, subject_code, subject_description FROM subjects WHERE subject_id = :id', ['id' => (int) $eq['old_subject_id']]);
            $matchedSubject = $oldSub ? ['subject_id' => (int) $oldSub['subject_id'], 'subject_code' => $oldSub['subject_code'], 'subject_description' => $oldSub['subject_description']] : null;
        } elseif (isset($completedMap[$newSid])) {
            $status = 'credited';
            $creditUnits = (float) ($nc['lec_credit'] + $nc['lab_credit']);
            $matchedSubject = ['subject_id' => $newSid, 'subject_code' => $nc['subject_code'], 'subject_description' => $nc['subject_description']];
        } else {
            foreach ($completedSubjects as $cs) {
                if ($cs['subject_code'] === $nc['subject_code']) {
                    $status = 'for_evaluation';
                    $matchedSubject = ['subject_id' => (int) $cs['subject_id'], 'subject_code' => $cs['subject_code'], 'subject_description' => $cs['subject_description']];
                    $creditUnits = (float) ($nc['lec_credit'] + $nc['lab_credit']);
                    break;
                }
            }
        }

        $evaluation[] = [
            'new_subject_id'   => $newSid,
            'subject_code'     => $nc['subject_code'],
            'subject_description' => $nc['subject_description'],
            'total_units'      => (float) ($nc['lec_credit'] + $nc['lab_credit']),
            'status'           => $status,
            'matched_subject'  => $matchedSubject,
            'credit_units'     => $creditUnits,
            'equivalency_id'   => $equivMap[$newSid]['id'] ?? null,
        ];
    }

    return $evaluation;
}

function get_student_active_curriculum(int $studentId, ?int $termId = null): array
{
    $student = fetch_one('SELECT program_id, year_level FROM students WHERE id = :id', ['id' => $studentId]);
    if (!$student) return [];

    $programId = (int) $student['program_id'];

    $history = get_current_student_program($studentId);
    if ($history && $history['curriculum_id']) {
        return fetch_all(
            'SELECT pc.*, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit, sub.lec_hours, sub.lab_hours
             FROM program_curriculum pc
             INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
             WHERE pc.curriculum_id = :cid
             ORDER BY pc.year_level, pc.semester, sub.subject_code',
            ['cid' => (int) $history['curriculum_id']]
        );
    }

    return fetch_all(
        'SELECT pc.*, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit, sub.lec_hours, sub.lab_hours
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY pc.year_level, pc.semester, sub.subject_code',
        ['pid' => $programId]
    );
}

/* =========================================================================
   ACADEMIC PLACEMENT
   ========================================================================= */

function create_academic_placement(
    int $studentId,
    int $programId,
    int $termId,
    string $placementType,
    string $remarks = '',
    ?int $approvedBy = null,
    ?int $curriculumId = null,
    ?int $yearLevel = null,
    ?string $standing = null,
    ?string $enrollmentStatus = null,
    ?int $sectionId = null,
    ?string $advancedSubjectAllowed = null
): int {
    $dept = fetch_one('SELECT department_id FROM programs WHERE programs_id = :pid', ['pid' => $programId]);
    $deptId = $dept ? (int) $dept['department_id'] : null;

    if (!$curriculumId) {
        $curr = fetch_one(
            'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid GROUP BY program_id ORDER BY curriculum_id DESC LIMIT 1',
            ['pid' => $programId]
        );
        $curriculumId = $curr ? (int) $curr['curriculum_id'] : null;
    }

    if (!$yearLevel) {
        $student = fetch_one('SELECT year_level FROM students WHERE id = :id', ['id' => $studentId]);
        $yearLevel = $student ? (int) $student['year_level'] : 1;
    }

    $eval = calculate_academic_standing($studentId, $programId, $curriculumId);

    if (!$standing) $standing = $eval['standing'];
    if (!$enrollmentStatus) $enrollmentStatus = $eval['enrollment_status'];
    if (!$advancedSubjectAllowed) $advancedSubjectAllowed = $eval['advanced_allowed'] ? 'yes' : 'no';

    execute_sql(
        'INSERT INTO student_academic_placements (
            student_id, department_id, program_id, curriculum_id, term_id,
            year_level, standing, enrollment_status, section_id,
            placement_type, placement_reason, advanced_subject_allowed,
            approved_by, remarks, created_at
         ) VALUES (
            :sid, :did, :pid, :cid, :tid,
            :yl, :standing, :es, :secid,
            :ptype, :preason, :adv,
            :approved_by, :remarks, NOW()
         )',
        [
            'sid'         => $studentId,
            'did'         => $deptId,
            'pid'         => $programId,
            'cid'         => $curriculumId,
            'tid'         => $termId,
            'yl'          => $yearLevel,
            'standing'    => $standing,
            'es'          => $enrollmentStatus,
            'secid'       => $sectionId,
            'ptype'       => $placementType,
            'preason'     => $remarks,
            'adv'         => $advancedSubjectAllowed,
            'approved_by' => $approvedBy,
            'remarks'     => $remarks,
        ]
    );

    return (int) db()->lastInsertId();
}

function get_student_placement(int $studentId, ?int $termId = null): ?array
{
    if ($termId) {
        return fetch_one(
            'SELECT sap.*, p.program_code, p.program_name, d.department_name
             FROM student_academic_placements sap
             INNER JOIN programs p ON p.programs_id = sap.program_id
             INNER JOIN departments d ON d.dept_id = sap.department_id
             WHERE sap.student_id = :sid AND sap.term_id = :tid
             ORDER BY sap.id DESC LIMIT 1',
            ['sid' => $studentId, 'tid' => $termId]
        );
    }

    return fetch_one(
        'SELECT sap.*, p.program_code, p.program_name, d.department_name
         FROM student_academic_placements sap
         INNER JOIN programs p ON p.programs_id = sap.program_id
         INNER JOIN departments d ON d.dept_id = sap.department_id
         WHERE sap.student_id = :sid
         ORDER BY sap.id DESC LIMIT 1',
        ['sid' => $studentId]
    );
}

function update_placement_override(
    int $placementId,
    ?int $yearLevel = null,
    ?string $standing = null,
    ?string $enrollmentStatus = null,
    ?int $sectionId = null,
    ?string $advancedSubjectAllowed = null,
    string $overrideReason = '',
    int $overrideBy = 0
): void {
    $fields = [];
    $params = ['id' => $placementId];

    if ($yearLevel !== null) { $fields[] = 'year_level = :yl'; $params['yl'] = $yearLevel; }
    if ($standing !== null) { $fields[] = 'standing = :standing'; $params['standing'] = $standing; }
    if ($enrollmentStatus !== null) { $fields[] = 'enrollment_status = :es'; $params['es'] = $enrollmentStatus; }
    if ($sectionId !== null) { $fields[] = 'section_id = :secid'; $params['secid'] = $sectionId; }
    if ($advancedSubjectAllowed !== null) { $fields[] = 'advanced_subject_allowed = :adv'; $params['adv'] = $advancedSubjectAllowed; }

    if ($overrideReason) {
        $fields[] = 'override_reason = :oreason';
        $fields[] = 'overridden_by = :oby';
        $params['oreason'] = $overrideReason;
        $params['oby'] = $overrideBy;
    }

    if ($fields === []) return;

    execute_sql(
        'UPDATE student_academic_placements SET ' . implode(', ', $fields) . ' WHERE id = :id',
        $params
    );
}

function calculate_academic_standing(int $studentId, int $programId, ?int $curriculumId = null): array
{
    $completedSubjects = fetch_all(
        'SELECT ss.subject_id, sub.subject_code
         FROM student_subjects ss
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         WHERE ss.student_id = :sid AND ss.enrollment_status = "enrolled"
           AND ss.final_grade IS NOT NULL AND ss.final_grade != ""
           AND NOT ss.final_grade IN ("5.00", "F", "DRP", "W")',
        ['sid' => $studentId]
    );

    $completedIds = [];
    $completedCodes = [];
    foreach ($completedSubjects as $cs) {
        $completedIds[(int) $cs['subject_id']] = true;
        $completedCodes[$cs['subject_code']] = true;
    }

    if (!$curriculumId) {
        $curr = fetch_one(
            'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid GROUP BY program_id ORDER BY curriculum_id DESC LIMIT 1',
            ['pid' => $programId]
        );
        $curriculumId = $curr ? (int) $curr['curriculum_id'] : null;
    }

    $curriculumSubjects = [];
    if ($curriculumId) {
        $curriculumSubjects = fetch_all(
            'SELECT pc.year_level, pc.semester, pc.subject_id, sub.subject_code
             FROM program_curriculum pc
             INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
             WHERE pc.curriculum_id = :cid
             ORDER BY pc.year_level, pc.semester',
            ['cid' => $curriculumId]
        );
    } else {
        $curriculumSubjects = fetch_all(
            'SELECT pc.year_level, pc.semester, pc.subject_id, sub.subject_code
             FROM program_curriculum pc
             INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
             WHERE pc.program_id = :pid
             ORDER BY pc.year_level, pc.semester',
            ['pid' => $programId]
        );
    }

    $yearTotals = [];
    $yearCompleted = [];
    foreach ($curriculumSubjects as $cs) {
        $yl = (int) $cs['year_level'];
        if (!isset($yearTotals[$yl])) { $yearTotals[$yl] = 0; $yearCompleted[$yl] = 0; }
        $yearTotals[$yl]++;
        if (isset($completedIds[(int) $cs['subject_id']]) || isset($completedCodes[$cs['subject_code']])) {
            $yearCompleted[$yl]++;
        }
    }

    ksort($yearTotals);
    $standing = 1;
    foreach ($yearTotals as $yl => $total) {
        $completed = $yearCompleted[$yl] ?? 0;
        $pct = $total > 0 ? ($completed / $total) * 100 : 0;
        if ($pct >= 75) {
            $standing = $yl + 1;
        }
    }

    $totalSubjects = array_sum($yearTotals);
    $totalCompleted = array_sum($yearCompleted);
    $overallPct = $totalSubjects > 0 ? ($totalCompleted / $totalSubjects) * 100 : 0;

    $hasMissingPrereqs = false;
    foreach ($curriculumSubjects as $cs) {
        if (!isset($completedIds[(int) $cs['subject_id']]) && !isset($completedCodes[$cs['subject_code']])) {
        }
    }

    $irregularSubjects = 0;
    $regularSubjects = 0;
    foreach ($curriculumSubjects as $cs) {
        if (isset($completedIds[(int) $cs['subject_id']]) || isset($completedCodes[$cs['subject_code']])) {
            $regularSubjects++;
        } else {
            $irregularSubjects++;
        }
    }

    $enrollmentStatus = 'regular';
    if ($standing > 1 && $irregularSubjects > 0) {
        $enrollmentStatus = 'irregular';
    }

    $maxYearLevel = 1;
    foreach ($yearTotals as $yl => $total) {
        if ($total > 0) $maxYearLevel = max($maxYearLevel, $yl);
    }
    if ($standing > $maxYearLevel + 1) $standing = $maxYearLevel + 1;

    $advancedAllowed = $overallPct >= 50;

    return [
        'standing'           => (string) $standing,
        'enrollment_status'  => $enrollmentStatus,
        'advanced_allowed'   => $advancedAllowed,
        'curriculum_completion' => round($overallPct, 1),
        'year_completed'     => $yearCompleted,
        'year_totals'        => $yearTotals,
    ];
}

/* =========================================================================
   TRANSFEREE PROCESSING
   ========================================================================= */

function create_transferee_record(
    int $studentId,
    string $previousSchool,
    ?string $previousProgram,
    ?string $torReceived,
    ?string $honorableDismissal,
    string $remarks = ''
): int {
    $existing = fetch_one(
        'SELECT id FROM transferee_records WHERE student_id = :sid AND evaluation_status != "rejected" LIMIT 1',
        ['sid' => $studentId]
    );
    if ($existing) return (int) $existing['id'];

    execute_sql(
        'INSERT INTO transferee_records (
            student_id, previous_school, previous_program,
            tor_received, honorable_dismissal, transfer_credentials_status,
            evaluation_status, remarks, created_at
         ) VALUES (
            :sid, :school, :program,
            :tor, :hd, :cred_status,
            "pending", :remarks, NOW()
         )',
        [
            'sid'       => $studentId,
            'school'    => $previousSchool,
            'program'   => $previousProgram,
            'tor'       => $torReceived,
            'hd'        => $honorableDismissal,
            'cred_status' => ($torReceived === 'received' && $honorableDismissal === 'received') ? 'complete' : 'incomplete',
            'remarks'   => $remarks,
        ]
    );

    return (int) db()->lastInsertId();
}

function add_transferee_subject(
    int $transfereeId,
    string $originalCode,
    string $originalName,
    float $originalUnits,
    ?string $grade,
    ?string $termTaken,
    ?int $equivalentSubjectId = null,
    ?string $equivalencyType = null
): int {
    execute_sql(
        'INSERT INTO transferee_subjects (
            transferee_id, original_subject_code, original_subject_name, original_units,
            grade, term_taken, equivalent_subject_id, equivalency_type, created_at
         ) VALUES (
            :tid, :ocode, :oname, :ounits,
            :grade, :term, :eq_sid, :eq_type, NOW()
         )',
        [
            'tid'      => $transfereeId,
            'ocode'    => $originalCode,
            'oname'    => $originalName,
            'ounits'   => $originalUnits,
            'grade'    => $grade,
            'term'     => $termTaken,
            'eq_sid'   => $equivalentSubjectId,
            'eq_type'  => $equivalencyType,
        ]
    );

    return (int) db()->lastInsertId();
}

function evaluate_transferee_curriculum(int $transfereeId, int $programId): array
{
    $transferee = fetch_one('SELECT * FROM transferee_records WHERE id = :id', ['id' => $transfereeId]);
    if (!$transferee) return [];

    $studentId = (int) $transferee['student_id'];

    $transfereeSubjects = fetch_all(
        'SELECT * FROM transferee_subjects WHERE transferee_id = :tid ORDER BY original_subject_code',
        ['tid' => $transfereeId]
    );

    $targetCurriculum = fetch_all(
        'SELECT pc.subject_id, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY sub.subject_code',
        ['pid' => $programId]
    );

    $evaluation = [];
    foreach ($targetCurriculum as $tc) {
        $status = 'to_take';
        $matchedSubject = null;
        $creditUnits = (float) ($tc['lec_credit'] + $tc['lab_credit']);

        foreach ($transfereeSubjects as $ts) {
            if ($ts['equivalent_subject_id'] && (int) $ts['equivalent_subject_id'] === (int) $tc['subject_id']) {
                $status = ($ts['equivalency_type'] === 'not_equivalent') ? 'to_take' : 'for_evaluation';
                $matchedSubject = ['subject_code' => $ts['original_subject_code'], 'subject_description' => $ts['original_subject_name'], 'grade' => $ts['grade']];
                break;
            }
            if ($ts['original_subject_code'] === $tc['subject_code']) {
                $status = 'for_evaluation';
                $matchedSubject = ['subject_code' => $ts['original_subject_code'], 'subject_description' => $ts['original_subject_name'], 'grade' => $ts['grade']];
                break;
            }
        }

        $evaluation[] = [
            'new_subject_id'    => (int) $tc['subject_id'],
            'subject_code'      => $tc['subject_code'],
            'subject_description' => $tc['subject_description'],
            'total_units'       => $creditUnits,
            'status'            => $status,
            'matched_subject'   => $matchedSubject,
            'credit_units'      => $creditUnits,
        ];
    }

    return $evaluation;
}

function process_transferee(
    int $transfereeId,
    int $programId,
    int $termId,
    int $registrarUserId,
    ?int $yearLevel = null,
    ?string $standing = null,
    ?string $enrollmentStatus = null,
    string $remarks = ''
): void {
    $transferee = fetch_one('SELECT * FROM transferee_records WHERE id = :id', ['id' => $transfereeId]);
    if (!$transferee) return;

    db()->beginTransaction();
    try {
        $studentId = (int) $transferee['student_id'];

        execute_sql(
            'UPDATE students SET program_id = :pid, year_level = COALESCE(:yl, year_level) WHERE id = :sid',
            ['pid' => $programId, 'yl' => $yearLevel, 'sid' => $studentId]
        );

        execute_sql(
            'INSERT INTO student_program_history (student_id, program_id, curriculum_id, term_started, status, reason)
             VALUES (:sid, :pid, NULL, :tid, "active", :reason)',
            [
                'sid'    => $studentId,
                'pid'    => $programId,
                'tid'    => $termId,
                'reason' => 'Transferee from ' . ($transferee['previous_school'] ?? 'unknown school'),
            ]
        );

        $curriculum = fetch_one(
            'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid GROUP BY program_id ORDER BY curriculum_id DESC LIMIT 1',
            ['pid' => $programId]
        );

        create_academic_placement(
            $studentId, $programId, $termId,
            'transfer', $remarks, $registrarUserId,
            $curriculum ? (int) $curriculum['curriculum_id'] : null,
            $yearLevel, $standing, $enrollmentStatus
        );

        execute_sql(
            'UPDATE transferee_records SET evaluation_status = "approved", evaluated_by = :uid, evaluated_at = NOW(), remarks = :remarks WHERE id = :id',
            ['uid' => $registrarUserId, 'remarks' => $remarks, 'id' => $transfereeId]
        );

        log_audit($transfereeId, 'transferee_processed', 'registrar', 'pending', 'approved', 'Transferee processed into program ' . $programId);

        send_enrollment_notification($studentId,
            'Transferee Application Approved',
            'Your transferee application has been processed. You are now enrolled in the new program. Please check your enrollment page.'
        );

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function get_transferee_subjects(int $transfereeId): array
{
    return fetch_all(
        'SELECT ts.*, sub.subject_id AS equivalent_subject_db_id, sub.subject_code AS equiv_code, sub.subject_description AS equiv_desc
         FROM transferee_subjects ts
         LEFT JOIN subjects sub ON sub.subject_id = ts.equivalent_subject_id
         WHERE ts.transferee_id = :tid
         ORDER BY ts.original_subject_code',
        ['tid' => $transfereeId]
    );
}

/* =========================================================================
   SUBJECT ELIGIBILITY ENGINE
   ========================================================================= */

function check_subject_eligibility(int $studentId, int $subjectId, ?int $termId = null): array
{
    $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]);
    if (!$student) return ['eligible' => false, 'reason' => 'Student not found'];

    $placement = get_student_placement($studentId, $termId);
    $yearLevel = $placement ? (int) $placement['year_level'] : (int) $student['year_level'];
    $standing = $placement ? (int) $placement['standing'] : $yearLevel;
    $advancedAllowed = $placement ? ($placement['advanced_subject_allowed'] === 'yes') : false;

    $subject = fetch_one('SELECT * FROM subjects WHERE subject_id = :id', ['id' => $subjectId]);
    if (!$subject) return ['eligible' => false, 'reason' => 'Subject not found'];

    $alreadyCompleted = fetch_one(
        'SELECT id, final_grade FROM student_subjects
         WHERE student_id = :sid AND subject_id = :subid AND enrollment_status = "enrolled"
           AND final_grade IS NOT NULL AND final_grade != ""',
        ['sid' => $studentId, 'subid' => $subjectId]
    );
    if ($alreadyCompleted) {
        if (!in_array($alreadyCompleted['final_grade'], ['5.00', 'F', 'DRP', 'W'], true)) {
            return ['eligible' => false, 'reason' => 'Subject already completed with grade ' . $alreadyCompleted['final_grade']];
        }
    }

    $currentlyEnrolled = fetch_one(
        'SELECT id FROM student_subjects
         WHERE student_id = :sid AND subject_id = :subid AND enrollment_status = "enrolled"
           AND (final_grade IS NULL OR final_grade = "")',
        ['sid' => $studentId, 'subid' => $subjectId]
    );
    if ($currentlyEnrolled) {
        return ['eligible' => false, 'reason' => 'Currently enrolled in this subject'];
    }

    $curriculumSubjects = fetch_all(
        'SELECT pc.year_level FROM program_curriculum pc WHERE pc.subject_id = :sid',
        ['sid' => $subjectId]
    );
    $subjectYearLevel = null;
    foreach ($curriculumSubjects as $cs) {
        $subjectYearLevel = (int) $cs['year_level'];
    }

    if ($subjectYearLevel !== null && !$advancedAllowed && $subjectYearLevel > $standing) {
        return ['eligible' => false, 'reason' => 'Subject requires year level ' . $subjectYearLevel . ' (current standing: ' . $standing . ')'];
    }

    $prereqs = fetch_all(
        'SELECT pc_prereq.subject_id AS prereq_id, sub.subject_code, sub.subject_description
         FROM program_curriculum pc
         INNER JOIN program_curriculum pc_prereq ON pc_prereq.curriculum_id = pc.curriculum_id
            AND pc_prereq.year_level < pc.year_level
         INNER JOIN subjects sub ON sub.subject_id = pc_prereq.subject_id
         WHERE pc.subject_id = :sid AND pc.program_id = :pid',
        ['sid' => $subjectId, 'pid' => $student['program_id']]
    );

    if ($prereqs !== []) {
        foreach ($prereqs as $pr) {
            $prereqGrade = fetch_one(
                'SELECT final_grade FROM student_subjects
                 WHERE student_id = :sid AND subject_id = :subid AND enrollment_status = "enrolled"
                   AND final_grade IS NOT NULL AND final_grade != ""
                 ORDER BY id DESC LIMIT 1',
                ['sid' => $studentId, 'subid' => (int) $pr['prereq_id']]
            );
            if (!$prereqGrade) {
                return ['eligible' => false, 'reason' => 'Missing prerequisite: ' . $pr['subject_code'] . ' - ' . $pr['subject_description']];
            }
            if (in_array($prereqGrade['final_grade'], ['5.00', 'F', 'DRP', 'W'], true)) {
                return ['eligible' => false, 'reason' => 'Prerequisite not passed: ' . $pr['subject_code'] . ' (grade: ' . $prereqGrade['final_grade'] . ')'];
            }
        }
    }

    $schedule = null;
    if ($termId) {
        $schedule = fetch_one(
            'SELECT * FROM class_schedules WHERE subject_id = :sid AND term_id = :tid LIMIT 1',
            ['sid' => $subjectId, 'tid' => $termId]
        );
        if (!$schedule) {
            return ['eligible' => false, 'reason' => 'No schedule available for this subject this term'];
        }
    }

    return ['eligible' => true, 'reason' => '', 'standing_ok' => true, 'prereqs_ok' => true];
}
