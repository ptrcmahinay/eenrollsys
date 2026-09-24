<?php
declare(strict_types=1);

/**
 * Transcript of Records (TOR) Engine
 *
 * Handles TOR request workflow, document generation, and verification.
 */

function create_tor_request(
    int $studentId,
    string $purpose,
    ?string $purposeDetail = null,
    ?string $destination = null,
    int $copies = 1
): int {
    execute_sql(
        'INSERT INTO tor_requests (student_id, purpose, purpose_detail, destination, copies, workflow_status, created_at)
         VALUES (:sid, :purpose, :detail, :dest, :copies, "pending", NOW())',
        [
            'sid'     => $studentId,
            'purpose' => $purpose,
            'detail'  => $purposeDetail,
            'dest'    => $destination,
            'copies'  => $copies,
        ]
    );

    $requestId = (int) db()->lastInsertId();

    notify_staff_by_role('registrar',
        'TOR Request Pending',
        'A new Transcript of Records request has been submitted and needs review.'
    );

    return $requestId;
}

function tor_advance_workflow(int $requestId, string $newStatus, ?string $remark = null): void
{
    $fields = ['workflow_status = :ws', 'updated_at = NOW()'];
    $params = ['ws' => $newStatus, 'id' => $requestId];

    if ($remark !== null) {
        $fields[] = 'remarks = :remark';
        $params['remark'] = $remark;
    }

    execute_sql(
        'UPDATE tor_requests SET ' . implode(', ', $fields) . ' WHERE id = :id',
        $params
    );
}

function tor_review(int $requestId, string $action, int $userId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM tor_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'pending') return;

    $status = $action === 'approve' ? 'under_review' : 'rejected';

    execute_sql(
        'UPDATE tor_requests SET
            workflow_status = :ws, reviewed_by = :uid, reviewed_at = NOW(), remarks = :remark, updated_at = NOW()
         WHERE id = :id',
        ['ws' => $status, 'uid' => $userId, 'remark' => $remark, 'id' => $requestId]
    );

    $student = fetch_one('SELECT student_number, CONCAT(first_name, " ", IFNULL(middle_name, ""), " ", last_name) AS full_name FROM students WHERE id = :id', ['id' => $req['student_id']]);

    if ($action === 'reject') {
        send_enrollment_notification((int) $req['student_id'],
            'TOR Request — Rejected',
            'Your TOR request has been rejected. Reason: ' . ($remark ?: 'No reason provided.')
        );
    } else {
        send_enrollment_notification((int) $req['student_id'],
            'TOR Request — Under Review',
            'Your TOR request is now being reviewed by the Registrar.'
        );
    }
}

function tor_clearance(int $requestId, string $type, bool $cleared): void
{
    $col = match($type) {
        'academic' => 'academic_cleared',
        'financial' => 'financial_cleared',
        'library' => 'library_cleared',
        'registrar' => 'registrar_verified',
        default => null,
    };
    if (!$col) return;

    execute_sql(
        "UPDATE tor_requests SET {$col} = :val, updated_at = NOW() WHERE id = :id",
        ['val' => $cleared ? 1 : 0, 'id' => $requestId]
    );

    $req = fetch_one('SELECT * FROM tor_requests WHERE id = :id', ['id' => $requestId]);
    if ($req && $req['academic_cleared'] && $req['financial_cleared'] && $req['library_cleared'] && $req['registrar_verified']) {
        if ($req['workflow_status'] === 'for_clearance') {
            tor_advance_workflow($requestId, 'approved');
        }
    }
}

function tor_generate_document(int $requestId, int $userId): string
{
    $req = fetch_one('SELECT * FROM tor_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req) return '';

    $year = date('Y');
    $count = fetch_one('SELECT COUNT(*) AS cnt FROM tor_requests WHERE document_number IS NOT NULL AND YEAR(created_at) = :y', ['y' => $year]);
    $seq = ((int) ($count['cnt'] ?? 0)) + 1;
    $docNumber = 'TOR-' . $year . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

    execute_sql(
        'UPDATE tor_requests SET document_number = :doc, workflow_status = "ready_for_release", approved_by = :uid, approved_at = NOW(), updated_at = NOW() WHERE id = :id',
        ['doc' => $docNumber, 'uid' => $userId, 'id' => $requestId]
    );

    send_enrollment_notification((int) $req['student_id'],
        'TOR Ready for Release',
        'Your Transcript of Records (Document #: ' . $docNumber . ') is ready for release.'
    );

    return $docNumber;
}

function tor_release(int $requestId, int $userId): void
{
    execute_sql(
        'UPDATE tor_requests SET workflow_status = "released", released_by = :uid, released_at = NOW(), updated_at = NOW() WHERE id = :id',
        ['uid' => $userId, 'id' => $requestId]
    );

    $req = fetch_one('SELECT * FROM tor_requests WHERE id = :id', ['id' => $requestId]);
    if ($req) {
        send_enrollment_notification((int) $req['student_id'],
            'TOR Released',
            'Your Transcript of Records (' . ($req['document_number'] ?? '') . ') has been released.'
        );
    }
}

function get_tor_academic_records(int $studentId): array
{
    $programHistory = get_student_program_history($studentId);

    $allSubjects = fetch_all(
        'SELECT ss.id, ss.subject_id, ss.final_grade, ss.units, ss.term_id,
                sub.subject_code, sub.subject_description,
                t.semester, ay.year_label, ay.start_year
         FROM student_subjects ss
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         INNER JOIN academic_terms t ON t.id = ss.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE ss.student_id = :sid AND ss.enrollment_status = "enrolled"
         ORDER BY ay.start_year, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
        ['sid' => $studentId]
    );

    $programMap = [];
    foreach ($programHistory as $ph) {
        $programMap[(int) $ph['program_id']] = $ph;
    }

    $terms = [];
    foreach ($allSubjects as $s) {
        $tid = (int) $s['term_id'];
        if (!isset($terms[$tid])) {
            $terms[$tid] = [
                'year_label' => $s['year_label'],
                'semester'   => $s['semester'],
                'start_year' => (int) $s['start_year'],
                'subjects'   => [],
            ];
        }
        $terms[$tid]['subjects'][] = $s;
    }

    uasort($terms, function ($a, $b) {
        if ($a['start_year'] !== $b['start_year']) return $a['start_year'] - $b['start_year'];
        $order = ['1' => 1, '2' => 2, 'mid' => 3];
        return ($order[$a['semester']] ?? 0) - ($order[$b['semester']] ?? 0);
    });

    $totalUnits = 0;
    $passedUnits = 0;
    $weightedSum = 0.0;
    $weightedCount = 0;
    foreach ($allSubjects as $s) {
        $units = (float) $s['units'];
        $totalUnits += $units;
        $gradeVal = parse_numeric_grade($s['final_grade']);
        if ($gradeVal !== null && $gradeVal <= 3.0) {
            $passedUnits += $units;
            $weightedSum += $gradeVal * $units;
            $weightedCount += $units;
        }
    }

    $gwa = $weightedCount > 0 ? round($weightedSum / $weightedCount, 2) : null;

    return [
        'program_history' => $programHistory,
        'terms'           => $terms,
        'total_units'     => $totalUnits,
        'passed_units'    => $passedUnits,
        'gwa'             => $gwa,
    ];
}

function verify_tor_document(string $docNumber): ?array
{
    $req = fetch_one(
        'SELECT tor.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name
         FROM tor_requests tor
         INNER JOIN students s ON s.id = tor.student_id
         WHERE tor.document_number = :doc',
        ['doc' => $docNumber]
    );
    return $req;
}

function is_student_on_leave(int $studentId): bool
{
    $student = fetch_one('SELECT academic_status FROM students WHERE id = :id', ['id' => $studentId]);
    if ($student && $student['academic_status'] === 'on_leave') return true;

    $activeLoa = fetch_one(
        'SELECT id FROM loa_requests WHERE student_id = :sid AND workflow_status = "approved" AND term_id = :tid LIMIT 1',
        ['sid' => $studentId, 'tid' => (int) (current_term()['id'] ?? 0)]
    );
    return $activeLoa !== null;
}
