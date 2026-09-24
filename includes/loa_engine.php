<?php
declare(strict_types=1);

/**
 * Leave of Absence (LOA) Engine
 *
 * Handles LOA request workflow, return processing, and enrollment blocking.
 */

function create_loa_request(
    int $studentId,
    int $termId,
    string $reasonCategory,
    ?string $reasonDetail = null,
    ?int $expectedReturnTermId = null
): int {
    $existing = fetch_one(
        'SELECT id FROM loa_requests WHERE student_id = :sid AND term_id = :tid AND workflow_status NOT IN ("rejected","cancelled","returned") LIMIT 1',
        ['sid' => $studentId, 'tid' => $termId]
    );
    if ($existing) return (int) $existing['id'];

    execute_sql(
        'INSERT INTO loa_requests (student_id, term_id, reason_category, reason_detail, expected_return_term_id, workflow_status, created_at)
         VALUES (:sid, :tid, :cat, :detail, :ret_tid, "submitted", NOW())',
        [
            'sid'      => $studentId,
            'tid'      => $termId,
            'cat'      => $reasonCategory,
            'detail'   => $reasonDetail,
            'ret_tid'  => $expectedReturnTermId,
        ]
    );

    $requestId = (int) db()->lastInsertId();

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $studentId]);
    $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

    notify_staff_by_role('adviser',
        'LOA Request Pending Review',
        $label . ' has submitted a Leave of Absence request. Please review.'
    );

    return $requestId;
}

function loa_adviser_review(int $requestId, string $action, int $userId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM loa_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'submitted') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'chair_review' : 'rejected';

    execute_sql(
        'UPDATE loa_requests SET
            adviser_status = :status, adviser_remark = :remark, adviser_processed_by = :uid, adviser_processed_at = NOW(),
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'uid' => $userId, 'ws' => $nextWorkflow, 'id' => $requestId]
    );

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'LOA Request — Adviser Approved',
            'Your Leave of Absence request has been approved by your adviser and is now with your department chair.'
        );

        $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $req['student_id']]);
        $label = $student ? ($student['student_number'] . ' - ' . $student['full_name']) : 'A student';

        $studentProg = fetch_one(
            'SELECT d.chair_id FROM students s INNER JOIN programs p ON p.programs_id = s.program_id INNER JOIN departments d ON d.dept_id = p.department_id WHERE s.id = :sid',
            ['sid' => $req['student_id']]
        );
        if ($studentProg && $studentProg['chair_id']) {
            create_notification('staff', (int) $studentProg['chair_id'], 'info',
                'LOA Request — Chair Review',
                $label . ' has submitted a Leave of Absence request. Please review.'
            );
        }
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'LOA Request — Adviser Declined',
            'Your Leave of Absence request was not approved by your adviser. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function loa_chair_review(int $requestId, string $action, int $userId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM loa_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'chair_review') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'registrar_review' : 'rejected';

    execute_sql(
        'UPDATE loa_requests SET
            chair_status = :status, chair_remark = :remark, chair_processed_by = :uid, chair_processed_at = NOW(),
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'uid' => $userId, 'ws' => $nextWorkflow, 'id' => $requestId]
    );

    if ($action === 'approve') {
        send_enrollment_notification((int) $req['student_id'],
            'LOA Request — Chair Approved',
            'Your Leave of Absence request has been approved by your department chair and is now with the Registrar.'
        );

        notify_staff_by_role('registrar',
            'LOA Request — Ready for Registrar Review',
            'A Leave of Absence request has passed adviser and chair review and needs registrar approval.'
        );
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'LOA Request — Chair Declined',
            'Your Leave of Absence request was not approved by your department chair. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function loa_registrar_review(int $requestId, string $action, int $userId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM loa_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'registrar_review') return;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $nextWorkflow = $action === 'approve' ? 'approved' : 'rejected';

    execute_sql(
        'UPDATE loa_requests SET
            registrar_status = :status, registrar_remark = :remark, registrar_processed_by = :uid, registrar_processed_at = NOW(),
            workflow_status = :ws, updated_at = NOW()
         WHERE id = :id',
        ['status' => $status, 'remark' => $remark, 'uid' => $userId, 'ws' => $nextWorkflow, 'id' => $requestId]
    );

    if ($action === 'approve') {
        execute_sql(
            'UPDATE students SET academic_status = "on_leave" WHERE id = :sid',
            ['sid' => $req['student_id']]
        );

        send_enrollment_notification((int) $req['student_id'],
            'LOA Approved',
            'Your Leave of Absence has been approved. You are now on official leave for this term. Enrollment is suspended until you return.'
        );
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'LOA Request — Registrar Declined',
            'Your Leave of Absence request was not approved by the Registrar. Reason: ' . ($remark ?: 'No reason provided.')
        );
    }
}

function loa_process_return(int $requestId, int $userId): void
{
    $req = fetch_one('SELECT * FROM loa_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'approved') return;

    db()->beginTransaction();
    try {
        execute_sql(
            'UPDATE loa_requests SET workflow_status = "returned", return_processed_by = :uid, return_processed_at = NOW(), updated_at = NOW() WHERE id = :id',
            ['uid' => $userId, 'id' => $requestId]
        );

        execute_sql(
            'UPDATE students SET academic_status = "active" WHERE id = :sid',
            ['sid' => $req['student_id']]
        );

        $studentId = (int) $req['student_id'];
        $placement = get_student_placement($studentId);
        if ($placement) {
            $currentTerm = current_term();
            $termId = $currentTerm ? (int) $currentTerm['id'] : 0;
            if ($termId > 0) {
                create_academic_placement(
                    $studentId,
                    (int) $placement['program_id'],
                    $termId,
                    'reentry',
                    'Returned from Leave of Absence (LOA #' . $requestId . ')',
                    $userId,
                    $placement['curriculum_id'] ? (int) $placement['curriculum_id'] : null,
                    (int) $placement['year_level'],
                    (string) $placement['standing'],
                    $placement['enrollment_status']
                );
            }
        }

        send_enrollment_notification($studentId,
            'Return from Leave of Absence',
            'Your return from Leave of Absence has been processed. Your academic status is now Active. You may proceed with enrollment.'
        );

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
