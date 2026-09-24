<?php
declare(strict_types=1);

/**
 * Leave of Absence (LOA) Engine — Registrar-Only
 *
 * Handles LOA record entry, monitoring, return processing, and extension.
 * Students do NOT submit LOA through the system; the Registrar encodes
 * official LOA forms submitted to the office.
 */

function create_loa_record(
    int $studentId,
    string $studentNo,
    string $programId,
    ?int $departmentId,
    string $dateFiled,
    string $semester,
    string $academicYear,
    string $effectiveFrom,
    string $effectiveTo,
    ?string $reason,
    ?string $expectedReturnSemester,
    ?string $expectedReturnAcademicYear,
    int $encodedBy,
    ?string $remarks = null
): int {
    execute_sql(
        'INSERT INTO leave_of_absence
            (student_id, student_no, program_id, department_id, date_filed,
             semester, academic_year, effective_date_from, effective_date_to,
             reason, expected_return_semester, expected_return_academic_year,
             status, encoded_by, encoded_at, remarks, created_at)
         VALUES
            (:sid, :sno, :pid, :did, :df,
             :sem, :ay, :edfrom, :edto,
             :reason, :ret_sem, :ret_ay,
             "active", :uid, NOW(), :remarks, NOW())',
        [
            'sid'      => $studentId,
            'sno'      => $studentNo,
            'pid'      => $programId,
            'did'      => $departmentId,
            'df'       => $dateFiled,
            'sem'      => $semester,
            'ay'       => $academicYear,
            'edfrom'   => $effectiveFrom,
            'edto'     => $effectiveTo,
            'reason'   => $reason,
            'ret_sem'  => $expectedReturnSemester,
            'ret_ay'   => $expectedReturnAcademicYear,
            'uid'      => $encodedBy,
            'remarks'  => $remarks,
        ]
    );

    $loaId = (int) db()->lastInsertId();

    $currentTerm = current_term();
    if ($currentTerm) {
        set_student_term_status($studentId, (int) $currentTerm['id'], 'on_leave', $encodedBy);
    }

    execute_sql(
        'UPDATE students SET academic_status = "on_leave" WHERE id = :sid',
        ['sid' => $studentId]
    );

    return $loaId;
}

function update_loa_record(
    int $loaId,
    ?string $reason = null,
    ?string $effectiveFrom = null,
    ?string $effectiveTo = null,
    ?string $expectedReturnSemester = null,
    ?string $expectedReturnAcademicYear = null,
    ?string $remarks = null,
    ?string $status = null
): void {
    $sets = ['updated_at = NOW()'];
    $params = ['id' => $loaId];

    if ($reason !== null) { $sets[] = 'reason = :reason'; $params['reason'] = $reason; }
    if ($effectiveFrom !== null) { $sets[] = 'effective_date_from = :edfrom'; $params['edfrom'] = $effectiveFrom; }
    if ($effectiveTo !== null) { $sets[] = 'effective_date_to = :edto'; $params['edto'] = $effectiveTo; }
    if ($expectedReturnSemester !== null) { $sets[] = 'expected_return_semester = :ret_sem'; $params['ret_sem'] = $expectedReturnSemester; }
    if ($expectedReturnAcademicYear !== null) { $sets[] = 'expected_return_academic_year = :ret_ay'; $params['ret_ay'] = $expectedReturnAcademicYear; }
    if ($remarks !== null) { $sets[] = 'remarks = :remarks'; $params['remarks'] = $remarks; }
    if ($status !== null) { $sets[] = 'status = :status'; $params['status'] = $status; }

    execute_sql(
        'UPDATE leave_of_absence SET ' . implode(', ', $sets) . ' WHERE id = :id',
        $params
    );
}

function mark_loa_returned(int $loaId, int $userId): void
{
    $loa = fetch_one('SELECT * FROM leave_of_absence WHERE id = :id', ['id' => $loaId]);
    if (!$loa || $loa['status'] !== 'active') return;

    db()->beginTransaction();
    try {
        execute_sql(
            'UPDATE leave_of_absence SET status = "returned", returned_by = :uid, returned_at = NOW(), updated_at = NOW() WHERE id = :id',
            ['uid' => $userId, 'id' => $loaId]
        );

        execute_sql(
            'UPDATE students SET academic_status = "active" WHERE id = :sid',
            ['sid' => $loa['student_id']]
        );

        $currentTerm = current_term();
        if ($currentTerm) {
            set_student_term_status((int) $loa['student_id'], (int) $currentTerm['id'], 'active', $userId);
        }

        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function extend_loa(int $loaId, string $newEffectiveTo, ?string $newExpectedReturnSemester, ?string $newExpectedReturnAcademicYear, int $userId): void
{
    $loa = fetch_one('SELECT * FROM leave_of_absence WHERE id = :id', ['id' => $loaId]);
    if (!$loa || $loa['status'] !== 'active') return;

    execute_sql(
        'UPDATE leave_of_absence SET status = "extended", updated_at = NOW() WHERE id = :id',
        ['id' => $loaId]
    );

    create_loa_record(
        (int) $loa['student_id'],
        $loa['student_no'],
        $loa['program_id'],
        $loa['department_id'] ? (int) $loa['department_id'] : null,
        date('Y-m-d'),
        $loa['semester'],
        $loa['academic_year'],
        $loa['effective_date_from'],
        $newEffectiveTo,
        $loa['reason'],
        $newExpectedReturnSemester,
        $newExpectedReturnAcademicYear,
        $userId,
        'Extended from LOA #' . $loaId
    );
}

function cancel_loa(int $loaId, int $userId): void
{
    $loa = fetch_one('SELECT * FROM leave_of_absence WHERE id = :id', ['id' => $loaId]);
    if (!$loa || $loa['status'] !== 'active') return;

    execute_sql(
        'UPDATE leave_of_absence SET status = "cancelled", updated_at = NOW() WHERE id = :id',
        ['id' => $loaId]
    );

    execute_sql(
        'UPDATE students SET academic_status = "active" WHERE id = :sid',
        ['sid' => $loa['student_id']]
    );

    $currentTerm = current_term();
    if ($currentTerm) {
        set_student_term_status((int) $loa['student_id'], (int) $currentTerm['id'], 'active', $userId);
    }
}

function set_student_term_status(int $studentId, int $termId, string $status, ?int $userId = null): void
{
    execute_sql(
        'INSERT INTO student_term_status (student_id, term_id, status, updated_by, updated_at)
         VALUES (:sid, :tid, :status, :uid, NOW())
         ON DUPLICATE KEY UPDATE status = :status2, updated_by = :uid2, updated_at = NOW()',
        [
            'sid'     => $studentId,
            'tid'     => $termId,
            'status'  => $status,
            'uid'     => $userId,
            'status2' => $status,
            'uid2'    => $userId,
        ]
    );
}

function is_student_on_leave(int $studentId): bool
{
    $student = fetch_one('SELECT academic_status FROM students WHERE id = :id', ['id' => $studentId]);
    if ($student && $student['academic_status'] === 'on_leave') return true;

    $currentTerm = current_term();
    if ($currentTerm) {
        $termStatus = fetch_one(
            'SELECT status FROM student_term_status WHERE student_id = :sid AND term_id = :tid',
            ['sid' => $studentId, 'tid' => (int) $currentTerm['id']]
        );
        if ($termStatus && $termStatus['status'] === 'on_leave') return true;
    }

    return false;
}

function get_student_term_status(int $studentId, int $termId): string
{
    $row = fetch_one(
        'SELECT status FROM student_term_status WHERE student_id = :sid AND term_id = :tid',
        ['sid' => $studentId, 'tid' => $termId]
    );
    return $row ? (string) $row['status'] : 'active';
}

function get_active_loa_for_student(int $studentId): ?array
{
    return fetch_one(
        'SELECT * FROM leave_of_absence WHERE student_id = :sid AND status = "active" LIMIT 1',
        ['sid' => $studentId]
    );
}

function get_loa_return_monitoring(string $academicYear, string $semester): array
{
    $rows = fetch_all(
        'SELECT loa.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
                p.program_name
         FROM leave_of_absence loa
         INNER JOIN students s ON s.id = loa.student_id
         INNER JOIN programs p ON p.programs_id = loa.program_id
         WHERE loa.expected_return_academic_year = :ay AND loa.expected_return_semester = :sem
         ORDER BY loa.status = "active" DESC, s.student_number ASC',
        ['ay' => $academicYear, 'sem' => $semester]
    );

    $total = count($rows);
    $returned = 0;
    $notYet = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'returned') $returned++;
        elseif ($r['status'] === 'active') $notYet++;
    }

    return [
        'rows'     => $rows,
        'total'    => $total,
        'returned' => $returned,
        'not_yet'  => $notYet,
    ];
}

function get_loa_stats(): array
{
    $active = fetch_one('SELECT COUNT(*) AS cnt FROM leave_of_absence WHERE status = "active"');
    $returned = fetch_one('SELECT COUNT(*) AS cnt FROM leave_of_absence WHERE status = "returned"');
    $extended = fetch_one('SELECT COUNT(*) AS cnt FROM leave_of_absence WHERE status = "extended"');
    $expired = fetch_one('SELECT COUNT(*) AS cnt FROM leave_of_absence WHERE status = "active" AND effective_date_to < CURDATE()');

    return [
        'active'   => (int) ($active['cnt'] ?? 0),
        'returned' => (int) ($returned['cnt'] ?? 0),
        'extended' => (int) ($extended['cnt'] ?? 0),
        'expired'  => (int) ($expired['cnt'] ?? 0),
    ];
}

function expire_overdue_loa(): void
{
    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $stmt = $pdo->query("SHOW TABLES LIKE 'leave_of_absence'");
        if (!$stmt || !$stmt->fetch()) return;

        execute_sql(
            'UPDATE leave_of_absence SET status = "expired", updated_at = NOW()
             WHERE status = "active" AND effective_date_to < CURDATE()'
        );
    } catch (\Throwable $e) {
    }
}

function get_semester_label(string $semester): string
{
    return match($semester) {
        '1' => '1st Semester',
        '2' => '2nd Semester',
        'summer' => 'Summer',
        default => $semester,
    };
}
