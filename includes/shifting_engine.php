<?php
declare(strict_types=1);

/**
 * Student Shifting Engine
 *
 * Handles the complete shifting workflow:
 * - Student request
 * - Adviser review
 * - Current department chair review
 * - Target department chair review
 * - Registrar review & curriculum evaluation
 * - Processing & program history update
 */

/**
 * Create a new shifting request.
 */
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

    // Notify adviser
    $student = fetch_one('SELECT CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $studentId]);
    $targetProgram = fetch_one('SELECT program_name FROM programs WHERE programs_id = :id', ['id' => $targetProgramId]);

    notify_staff_by_role('adviser',
        'Shifting Request Pending Review',
        ($student ? $student['full_name'] : 'A student') . ' has requested to shift to ' . ($targetProgram ? $targetProgram['program_name'] : 'another program') . '. Please review.'
    );

    return $requestId;
}

/**
 * Advance shifting workflow to the next stage.
 */
function advance_shifting_workflow(int $requestId, string $newStatus): void
{
    execute_sql(
        'UPDATE shifting_requests SET workflow_status = :status, updated_at = NOW() WHERE id = :id',
        ['status' => $newStatus, 'id' => $requestId]
    );
}

/**
 * Adviser review of shifting request.
 */
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
        // Notify current department chair
        $currentDept = fetch_one(
            'SELECT d.dept_name, d.chair_id FROM programs p INNER JOIN departments d ON d.dept_id = p.department_id WHERE p.programs_id = :pid',
            ['pid' => $req['current_program_id']]
        );
        if ($currentDept && $currentDept['chair_id']) {
            $chairStaff = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => $currentDept['chair_id']]);
            if ($chairStaff) {
                send_staff_notification((int) $chairStaff['users_id'],
                    'Shifting Request — Current Department Review',
                    $label . ' is requesting to shift from your department. Please review.'
                );
            }
        }
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Adviser Declined',
            'Your shifting request was not approved by your adviser. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

/**
 * Current department chair review.
 */
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
        // Notify target department chair
        $targetDept = fetch_one(
            'SELECT d.dept_name, d.chair_id FROM programs p INNER JOIN departments d ON d.dept_id = p.department_id WHERE p.programs_id = :pid',
            ['pid' => $req['target_program_id']]
        );
        if ($targetDept && $targetDept['chair_id']) {
            $chairStaff = fetch_one('SELECT users_id FROM staff WHERE staff_id = :sid', ['sid' => $targetDept['chair_id']]);
            if ($chairStaff) {
                send_staff_notification((int) $chairStaff['users_id'],
                    'Shifting Request — Target Department Review',
                    $label . ' wants to shift INTO your department. Please review.'
                );
            }
        }
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Current Department Declined',
            'Your shifting request was not approved by your current department chair. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

/**
 * Target department chair review.
 */
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
        // Notify registrar
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

/**
 * Registrar review — approves and moves to curriculum evaluation.
 */
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

    if ($action === 'reject') {
        send_enrollment_notification((int) $req['student_id'],
            'Shifting Request — Registrar Declined',
            'Your shifting request was not approved by the Registrar. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

/**
 * Process the approved shifting request — update student's program and create history.
 */
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

        // 1. End the current program history
        execute_sql(
            'UPDATE student_program_history SET term_ended = :tid, status = "shifted" WHERE student_id = :sid AND program_id = :pid AND status = "active"',
            ['tid' => $termId, 'sid' => $studentId, 'pid' => $currentProgramId]
        );

        // 2. Get the active curriculum for the new program
        $newCurriculum = fetch_one(
            'SELECT curriculum_id FROM program_curriculum WHERE program_id = :pid GROUP BY program_id ORDER BY curriculum_id DESC LIMIT 1',
            ['pid' => $targetProgramId]
        );

        // 3. Create new program history entry
        execute_sql(
            'INSERT INTO student_program_history (student_id, program_id, curriculum_id, term_started, status, reason)
             VALUES (:sid, :pid, :cid, :tid, "active", :reason)',
            [
                'sid'    => $studentId,
                'pid'    => $targetProgramId,
                'cid'    => $newCurriculum ? (int) $newCurriculum['curriculum_id'] : null,
                'tid'    => $termId,
                'reason' => 'Approved shift from ' . $currentProgramId . ' to ' . $targetProgramId,
            ]
        );

        // 4. Update the student's current program
        execute_sql(
            'UPDATE students SET program_id = :pid, year_level = COALESCE(:yl, year_level) WHERE id = :sid',
            [
                'pid' => $targetProgramId,
                'yl'  => $req['target_year_level'] ?: null,
                'sid' => $studentId,
            ]
        );

        // 5. Mark the shifting request as processed
        execute_sql(
            'UPDATE shifting_requests SET workflow_status = "processed", processed_at = NOW(), processed_by = :uid, updated_at = NOW() WHERE id = :id',
            ['uid' => $registrarUserId, 'id' => $requestId]
        );

        log_audit($requestId, 'shifting_processed', 'registrar', 'curriculum_evaluation', 'processed', 'Program changed from ' . $currentProgramId . ' to ' . $targetProgramId);

        // 6. Notify student
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

/**
 * Save subject equivalencies for a shifting request.
 */
function save_subject_equivalencies(int $requestId, array $equivalencies): void
{
    // Clear existing equivalencies for this request
    execute_sql('DELETE FROM subject_equivalencies WHERE shifting_request_id = :rid', ['rid' => $requestId]);

    $ins = db()->prepare(
        'INSERT INTO subject_equivalencies (shifting_request_id, old_subject_id, new_subject_id, equivalency_type, credit_units, status, remarks)
         VALUES (:rid, :old_sid, :new_sid, :eq_type, :credits, :status, :remarks)'
    );

    foreach ($equivalencies as $eq) {
        $ins->execute([
            'rid'      => $requestId,
            'old_sid'  => (int) $eq['old_subject_id'],
            'new_sid'  => (int) $eq['new_subject_id'],
            'eq_type'  => $eq['equivalency_type'] ?? 'equivalent',
            'credits'  => (float) ($eq['credit_units'] ?? 0),
            'status'   => $eq['status'] ?? 'pending',
            'remarks'  => $eq['remarks'] ?? null,
        ]);
    }
}

/**
 * Get the student's current active program from history.
 */
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

/**
 * Get all program history for a student.
 */
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

/**
 * Evaluate curriculum subjects for a shifting request.
 * Returns an array comparing old curriculum subjects with new curriculum subjects.
 */
function evaluate_shifting_curriculum(int $requestId): array
{
    $req = fetch_one('SELECT * FROM shifting_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req) return [];

    $studentId = (int) $req['student_id'];
    $currentProgramId = (int) $req['current_program_id'];
    $targetProgramId = (int) $req['target_program_id'];

    // Get subjects the student has completed (from old program)
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

    // Get the new program's curriculum subjects
    $newCurriculum = fetch_all(
        'SELECT pc.subject_id, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY sub.subject_code',
        ['pid' => $targetProgramId]
    );

    // Get existing equivalencies
    $existingEquivalencies = fetch_all(
        'SELECT * FROM subject_equivalencies WHERE shifting_request_id = :rid',
        ['rid' => $requestId]
    );
    $equivMap = [];
    foreach ($existingEquivalencies as $eq) {
        $equivMap[(int) $eq['new_subject_id']] = $eq;
    }

    $evaluation = [];
    $completedMap = [];
    foreach ($completedSubjects as $cs) {
        $completedMap[(int) $cs['subject_id']] = $cs;
    }

    foreach ($newCurriculum as $nc) {
        $newSid = (int) $nc['subject_id'];
        $status = 'to_take';
        $matchedSubject = null;
        $creditUnits = 0;

        // Check for existing equivalency
        if (isset($equivMap[$newSid])) {
            $eq = $equivMap[$newSid];
            $status = $eq['status'] === 'approved' ? 'credited' : ($eq['equivalency_type'] === 'not_equivalent' ? 'to_take' : 'for_evaluation');
            $creditUnits = (float) $eq['credit_units'];
            $matchedSubject = fetch_one('SELECT subject_code, subject_description FROM subjects WHERE subject_id = :id', ['id' => (int) $eq['old_subject_id']]);
        }
        // Check for direct match (same subject_id)
        elseif (isset($completedMap[$newSid])) {
            $status = 'credited';
            $creditUnits = (float) ($nc['lec_credit'] + $nc['lab_credit']);
            $matchedSubject = ['subject_code' => $nc['subject_code'], 'subject_description' => $nc['subject_description']];
        }
        // Check for same subject_code match
        else {
            foreach ($completedSubjects as $cs) {
                if ($cs['subject_code'] === $nc['subject_code']) {
                    $status = 'for_evaluation';
                    $matchedSubject = ['subject_code' => $cs['subject_code'], 'subject_description' => $cs['subject_description']];
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

/**
 * Get the active curriculum for a student's current program.
 */
function get_student_active_curriculum(int $studentId, ?int $termId = null): array
{
    $student = fetch_one('SELECT program_id, year_level FROM students WHERE id = :id', ['id' => $studentId]);
    if (!$student) return [];

    $programId = (int) $student['program_id'];

    // Try to get curriculum from program history first
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

    // Fallback: get latest curriculum for the program
    return fetch_all(
        'SELECT pc.*, sub.subject_code, sub.subject_description, sub.lec_credit, sub.lab_credit, sub.lec_hours, sub.lab_hours
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY pc.year_level, pc.semester, sub.subject_code',
        ['pid' => $programId]
    );
}
