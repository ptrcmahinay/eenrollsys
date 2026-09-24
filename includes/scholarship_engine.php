<?php
declare(strict_types=1);

/* ─── Scholarship Program CRUD ─── */

function get_all_scholarship_programs(string $status = ''): array
{
    $sql = 'SELECT sp.*,
            (SELECT COUNT(*) FROM student_scholarships ss WHERE ss.scholarship_id = sp.id AND ss.status = "ACTIVE") AS assigned_count
            FROM scholarship_programs sp';
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE sp.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY sp.code ASC';
    return fetch_all($sql, $params);
}

function get_scholarship_program(int $id): ?array
{
    return fetch_one('SELECT * FROM scholarship_programs WHERE id = :id LIMIT 1', ['id' => $id]);
}

function get_scholarship_program_by_code(string $code): ?array
{
    return fetch_one('SELECT * FROM scholarship_programs WHERE code = :code LIMIT 1', ['code' => $code]);
}

function create_scholarship_program(array $data): int
{
    execute_sql(
        'INSERT INTO scholarship_programs (code, name, description, type, duration_type, duration_value, grace_period_terms, status)
         VALUES (:code, :name, :desc, :type, :dtype, :dval, :grace, :status)',
        [
            'code'   => $data['code'],
            'name'   => $data['name'],
            'desc'   => $data['description'] ?? null,
            'type'   => $data['type'] ?? 'TUITION_WAIVER',
            'dtype'  => $data['duration_type'] ?? 'TERMS',
            'dval'   => (int) ($data['duration_value'] ?? 10),
            'grace'  => (int) ($data['grace_period_terms'] ?? 2),
            'status' => $data['status'] ?? 'ACTIVE',
        ]
    );
    return (int) db()->lastInsertId();
}

function update_scholarship_program(int $id, array $data): void
{
    execute_sql(
        'UPDATE scholarship_programs SET code = :code, name = :name, description = :desc, type = :type,
         duration_type = :dtype, duration_value = :dval, grace_period_terms = :grace, status = :status
         WHERE id = :id',
        [
            'id'    => $id,
            'code'  => $data['code'],
            'name'  => $data['name'],
            'desc'  => $data['description'] ?? null,
            'type'  => $data['type'] ?? 'TUITION_WAIVER',
            'dtype' => $data['duration_type'] ?? 'TERMS',
            'dval'  => (int) ($data['duration_value'] ?? 10),
            'grace' => (int) ($data['grace_period_terms'] ?? 2),
            'status' => $data['status'] ?? 'ACTIVE',
        ]
    );
}

/* ─── Scholarship Rules ─── */

function get_scholarship_rules(int $scholarshipId): ?array
{
    return fetch_one('SELECT * FROM scholarship_rules WHERE scholarship_id = :sid LIMIT 1', ['sid' => $scholarshipId]);
}

function save_scholarship_rules(int $scholarshipId, array $data): void
{
    $existing = get_scholarship_rules($scholarshipId);
    $params = [
        'sid'            => $scholarshipId,
        'reg'            => (int) ($data['requires_regular_status'] ?? 0),
        'active'         => (int) ($data['requires_active_enrollment'] ?? 1),
        'loa'            => (int) ($data['allow_during_loa'] ?? 0),
        'max_yl'         => !empty($data['max_year_level']) ? (int) $data['max_year_level'] : null,
        'min_yl'         => !empty($data['min_year_level']) ? (int) $data['min_year_level'] : null,
        'max_shift_yl'   => !empty($data['max_shifting_year_level']) ? (int) $data['max_shifting_year_level'] : null,
        'programs'       => !empty($data['applicable_programs']) ? $data['applicable_programs'] : null,
        'excluded'       => !empty($data['excluded_programs']) ? $data['excluded_programs'] : null,
        'priority'       => (int) ($data['priority'] ?? 1),
        'status'         => $data['status'] ?? 'ACTIVE',
    ];
    if ($existing) {
        execute_sql(
            'UPDATE scholarship_rules SET requires_regular_status = :reg, requires_active_enrollment = :active,
             allow_during_loa = :loa, max_year_level = :max_yl, min_year_level = :min_yl,
             max_shifting_year_level = :max_shift_yl,
             applicable_programs = :programs, excluded_programs = :excluded, priority = :priority, status = :status
             WHERE scholarship_id = :sid',
            $params
        );
    } else {
        execute_sql(
            'INSERT INTO scholarship_rules (scholarship_id, requires_regular_status, requires_active_enrollment,
             allow_during_loa, max_year_level, min_year_level, max_shifting_year_level,
             applicable_programs, excluded_programs, priority, status)
             VALUES (:sid, :reg, :active, :loa, :max_yl, :min_yl, :max_shift_yl, :programs, :excluded, :priority, :status)',
            $params
        );
    }
}

/* ─── Scholarship Benefits ─── */

function get_scholarship_benefits(int $scholarshipId): array
{
    return fetch_all(
        'SELECT * FROM scholarship_benefits WHERE scholarship_id = :sid ORDER BY fee_item_name ASC',
        ['sid' => $scholarshipId]
    );
}

function save_scholarship_benefits(int $scholarshipId, array $benefits): void
{
    execute_sql('DELETE FROM scholarship_benefits WHERE scholarship_id = :sid', ['sid' => $scholarshipId]);
    foreach ($benefits as $b) {
        if (trim($b['fee_item_name'] ?? '') === '') continue;
        execute_sql(
            'INSERT INTO scholarship_benefits (scholarship_id, fee_item_name, benefit_type, benefit_value, status)
             VALUES (:sid, :fname, :btype, :bval, :status)',
            [
                'sid'   => $scholarshipId,
                'fname' => $b['fee_item_name'],
                'btype' => $b['benefit_type'] ?? 'WAIVE',
                'bval'  => (float) ($b['benefit_value'] ?? 0),
                'status' => $b['status'] ?? 'ACTIVE',
            ]
        );
    }
}

/* ─── Student Scholarship Assignment ─── */

function assign_student_scholarship(int $studentId, int $scholarshipId, int $termId, ?int $approvedBy = null, string $remarks = ''): int
{
    $existing = fetch_one(
        'SELECT id FROM student_scholarships WHERE student_id = :sid AND scholarship_id = :schid AND status = "ACTIVE" LIMIT 1',
        ['sid' => $studentId, 'schid' => $scholarshipId]
    );
    if ($existing) {
        return (int) $existing['id'];
    }
    execute_sql(
        'INSERT INTO student_scholarships (student_id, scholarship_id, start_entry_year, start_term_id, status, approved_by, approved_at, remarks)
         VALUES (:sid, :schid, (SELECT start_year FROM academic_terms WHERE id = :tid LIMIT 1), :tid, "ACTIVE", :by, NOW(), :remarks)',
        [
            'sid'     => $studentId,
            'schid'   => $scholarshipId,
            'tid'     => $termId,
            'by'      => $approvedBy,
            'remarks' => $remarks,
        ]
    );
    return (int) db()->lastInsertId();
}

function revoke_student_scholarship(int $studentScholarshipId, string $remarks = ''): void
{
    execute_sql(
        'UPDATE student_scholarships SET status = "REVOKED", remarks = CONCAT(COALESCE(remarks, ""), " | Revoked: ", :r) WHERE id = :id',
        ['id' => $studentScholarshipId, 'r' => $remarks]
    );
}

function get_student_scholarships(int $studentId, string $status = ''): array
{
    $sql = 'SELECT ss.*, sp.code AS scholarship_code, sp.name AS scholarship_name, sp.type AS scholarship_type,
            sp.duration_type, sp.duration_value
            FROM student_scholarships ss
            INNER JOIN scholarship_programs sp ON sp.id = ss.scholarship_id
            WHERE ss.student_id = :sid';
    $params = ['sid' => $studentId];
    if ($status !== '') {
        $sql .= ' AND ss.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY ss.created_at DESC';
    return fetch_all($sql, $params);
}

function get_active_student_scholarship(int $studentId, string $scholarshipCode): ?array
{
    return fetch_one(
        'SELECT ss.*, sp.code AS scholarship_code, sp.name AS scholarship_name, sp.type, sp.duration_type, sp.duration_value, sp.grace_period_terms
         FROM student_scholarships ss
         INNER JOIN scholarship_programs sp ON sp.id = ss.scholarship_id
         WHERE ss.student_id = :sid AND sp.code = :code AND ss.status = "ACTIVE"
         LIMIT 1',
        ['sid' => $studentId, 'code' => $scholarshipCode]
    );
}

/* ─── Program Duration ─── */

function get_program_duration(int $programId): int
{
    $row = fetch_one('SELECT prescribed_years FROM program_durations WHERE program_id = :pid LIMIT 1', ['pid' => $programId]);
    return $row ? (int) $row['prescribed_years'] : 4;
}

function set_program_duration(int $programId, int $years, string $notes = ''): void
{
    $existing = fetch_one('SELECT id FROM program_durations WHERE program_id = :pid LIMIT 1', ['pid' => $programId]);
    if ($existing) {
        execute_sql('UPDATE program_durations SET prescribed_years = :y, notes = :n WHERE program_id = :pid', ['y' => $years, 'n' => $notes, 'pid' => $programId]);
    } else {
        execute_sql('INSERT INTO program_durations (program_id, prescribed_years, notes) VALUES (:pid, :y, :n)', ['pid' => $programId, 'y' => $years, 'n' => $notes]);
    }
}

/* ─── Previous Financial Assistance (Transferee Records) ─── */

function get_previous_financial_assistance(int $studentId): array
{
    return fetch_all(
        'SELECT * FROM previous_financial_assistance WHERE student_id = :sid ORDER BY created_at DESC',
        ['sid' => $studentId]
    );
}

function save_previous_financial_assistance(int $studentId, array $records): void
{
    execute_sql('DELETE FROM previous_financial_assistance WHERE student_id = :sid', ['sid' => $studentId]);
    foreach ($records as $r) {
        if (trim($r['previous_hei'] ?? '') === '') continue;
        execute_sql(
            'INSERT INTO previous_financial_assistance
             (student_id, previous_hei, program_name, academic_year, semester, assistance_type,
              government_funded, amount, has_bachelor_degree, verified, verified_by, verified_at, remarks, encoded_by)
             VALUES (:sid, :hei, :prog, :ay, :sem, :atype, :govt, :amt, :degree, :verified, :vb, :va, :remarks, :encoded_by)',
            [
                'sid'       => $studentId,
                'hei'       => $r['previous_hei'],
                'prog'      => $r['program_name'] ?? null,
                'ay'        => $r['academic_year'] ?? null,
                'sem'       => $r['semester'] ?? null,
                'atype'     => $r['assistance_type'] ?? 'FHE',
                'govt'      => (int) ($r['government_funded'] ?? 1),
                'amt'       => !empty($r['amount']) ? (float) $r['amount'] : null,
                'degree'    => (int) ($r['has_bachelor_degree'] ?? 0),
                'verified'  => (int) ($r['verified'] ?? 0),
                'vb'        => $r['verified_by'] ?? null,
                'va'        => $r['verified_at'] ?? null,
                'remarks'   => $r['remarks'] ?? null,
                'encoded_by' => $r['encoded_by'] ?? null,
            ]
        );
    }
}

function get_previous_government_assistance_terms(int $studentId): int
{
    $row = fetch_one(
        'SELECT COUNT(*) AS cnt FROM previous_financial_assistance
         WHERE student_id = :sid AND government_funded = 1 AND has_bachelor_degree = 0',
        ['sid' => $studentId]
    );
    return (int) ($row['cnt'] ?? 0);
}

function has_previous_bachelor_degree(int $studentId): bool
{
    $row = fetch_one(
        'SELECT COUNT(*) AS cnt FROM previous_financial_assistance WHERE student_id = :sid AND has_bachelor_degree = 1',
        ['sid' => $studentId]
    );
    return (int) ($row['cnt'] ?? 0) > 0;
}

/* ─── Scholarship Consumption Tracking ─── */

function record_scholarship_term(int $studentScholarshipId, int $studentId, int $termId, string $status = 'NOT_ENROLLED', bool $consumes = false, ?int $programId = null): void
{
    $existing = fetch_one(
        'SELECT id FROM student_scholarship_terms WHERE student_scholarship_id = :ssid AND term_id = :tid LIMIT 1',
        ['ssid' => $studentScholarshipId, 'tid' => $termId]
    );
    if ($existing) {
        execute_sql(
            'UPDATE student_scholarship_terms SET status = :status, consumes_scholarship = :consume WHERE id = :id',
            ['status' => $status, 'consume' => $consumes ? 1 : 0, 'id' => (int) $existing['id']]
        );
    } else {
        execute_sql(
            'INSERT INTO student_scholarship_terms (student_scholarship_id, student_id, term_id, status, consumes_scholarship)
             VALUES (:ssid, :sid, :tid, :status, :consume)',
            ['ssid' => $studentScholarshipId, 'sid' => $studentId, 'tid' => $termId, 'status' => $status, 'consume' => $consumes ? 1 : 0]
        );
    }
}

function get_student_scholarship_terms(int $studentScholarshipId): array
{
    return fetch_all(
        'SELECT sst.*, ay.start_year, ay.end_year, at2.semester
         FROM student_scholarship_terms sst
         INNER JOIN academic_terms at2 ON at2.id = sst.term_id
         INNER JOIN academic_years ay ON ay.id = at2.academic_year_id
         WHERE sst.student_scholarship_id = :ssid
         ORDER BY ay.start_year DESC, FIELD(at2.semester, "1", "2", "mid")',
        ['ssid' => $studentScholarshipId]
    );
}

function get_consumed_scholarship_terms(int $studentScholarshipId): int
{
    $row = fetch_one(
        'SELECT COUNT(*) AS cnt FROM student_scholarship_terms WHERE student_scholarship_id = :ssid AND consumes_scholarship = 1',
        ['ssid' => $studentScholarshipId]
    );
    return (int) ($row['cnt'] ?? 0);
}

/* ─── FHE Settings ─── */

function get_fhe_settings(): array
{
    $row = fetch_one('SELECT * FROM fhe_settings ORDER BY id ASC LIMIT 1');
    if (!$row) {
        execute_sql('INSERT INTO fhe_settings (max_allowed_years, max_allowed_semesters, max_university_residency_years) VALUES (5, 10, 6)');
        return get_fhe_settings();
    }
    return $row;
}

function save_fhe_settings(array $data, ?int $userId = null): void
{
    $existing = fetch_one('SELECT id FROM fhe_settings ORDER BY id ASC LIMIT 1');
    if ($existing) {
        execute_sql(
            'UPDATE fhe_settings SET max_allowed_years = :y, max_allowed_semesters = :s, max_university_residency_years = :r,
             university_residency_action = :action, allow_registrar_override = :override, updated_by = :uid WHERE id = :id',
            [
                'id'       => (int) $existing['id'],
                'y'        => (int) ($data['max_allowed_years'] ?? 5),
                's'        => (int) ($data['max_allowed_semesters'] ?? 10),
                'r'        => (int) ($data['max_university_residency_years'] ?? 6),
                'action'   => $data['university_residency_action'] ?? 'BLOCK',
                'override' => (int) ($data['allow_registrar_override'] ?? 1),
                'uid'      => $userId,
            ]
        );
    } else {
        execute_sql(
            'INSERT INTO fhe_settings (max_allowed_years, max_allowed_semesters, max_university_residency_years, university_residency_action, allow_registrar_override, updated_by)
             VALUES (:y, :s, :r, :action, :override, :uid)',
            [
                'y'        => (int) ($data['max_allowed_years'] ?? 5),
                's'        => (int) ($data['max_allowed_semesters'] ?? 10),
                'r'        => (int) ($data['max_university_residency_years'] ?? 6),
                'action'   => $data['university_residency_action'] ?? 'BLOCK',
                'override' => (int) ($data['allow_registrar_override'] ?? 1),
                'uid'      => $userId,
            ]
        );
    }
}

/* ─── Enrollment Overrides ─── */

function has_active_enrollment_override(int $studentId, string $type): bool
{
    $row = fetch_one(
        'SELECT id FROM enrollment_overrides WHERE student_id = :sid AND override_type = :type AND status = "ACTIVE"
         AND (approved_until_term_id IS NULL OR approved_until_term_id >= (SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1)) LIMIT 1',
        ['sid' => $studentId, 'type' => $type]
    );
    return $row !== null;
}

function create_enrollment_override(int $studentId, string $type, string $reason, ?int $untilTermId, int $approvedBy): int
{
    execute_sql(
        'INSERT INTO enrollment_overrides (student_id, override_type, reason, approved_until_term_id, approved_by)
         VALUES (:sid, :type, :reason, :tid, :by)',
        [
            'sid'  => $studentId,
            'type' => $type,
            'reason' => $reason,
            'tid'  => $untilTermId,
            'by'   => $approvedBy,
        ]
    );
    return (int) db()->lastInsertId();
}

function get_student_overrides(int $studentId): array
{
    return fetch_all(
        'SELECT eo.*, CONCAT(u.first_name, " ", u.last_name) AS approved_by_name
         FROM enrollment_overrides eo
         LEFT JOIN users u ON u.id = eo.approved_by
         WHERE eo.student_id = :sid ORDER BY eo.created_at DESC',
        ['sid' => $studentId]
    );
}

function revoke_enrollment_override(int $overrideId): void
{
    execute_sql('UPDATE enrollment_overrides SET status = "REVOKED" WHERE id = :id', ['id' => $overrideId]);
}

/* ─── University Residency Check ─── */

function check_university_residency(int $studentId, ?array $student = null): array
{
    if (!$student) {
        $student = fetch_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    }
    if (!$student) {
        return ['blocked' => false, 'reason' => 'Student not found.', 'years_enrolled' => 0, 'max_years' => 0, 'has_override' => false];
    }

    $fheSettings = get_fhe_settings();
    $maxYears = (int) $fheSettings['max_university_residency_years'];
    $action = $fheSettings['university_residency_action'];

    $enrollmentYears = fetch_one(
        'SELECT COUNT(DISTINCT ay.start_year) AS years
         FROM enrollment_requests er
         INNER JOIN academic_terms at2 ON at2.id = er.academic_term_id
         INNER JOIN academic_years ay ON ay.id = at2.academic_year_id
         WHERE er.student_id = :sid AND er.workflow_status IN ("approved", "finalized")',
        ['sid' => $studentId]
    );
    $yearsEnrolled = (int) ($enrollmentYears['years'] ?? 0);

    $hasOverride = has_active_enrollment_override($studentId, 'UNIVERSITY_RESIDENCY');

    if ($yearsEnrolled >= $maxYears && !$hasOverride) {
        $reason = 'University maximum residency exceeded (' . $yearsEnrolled . '/' . $maxYears . ' years).';
        if ($action === 'BLOCK') {
            return ['blocked' => true, 'reason' => $reason, 'years_enrolled' => $yearsEnrolled, 'max_years' => $maxYears, 'has_override' => false];
        }
        return ['blocked' => false, 'reason' => $reason, 'years_enrolled' => $yearsEnrolled, 'max_years' => $maxYears, 'has_override' => false, 'warning' => true];
    }

    return ['blocked' => false, 'reason' => '', 'years_enrolled' => $yearsEnrolled, 'max_years' => $maxYears, 'has_override' => $hasOverride];
}

/* ─── FHE Eligibility Engine ─── */

function check_fhe_eligibility(int $studentId, ?array $student = null, ?array $term = null): array
{
    if (!$student) {
        $student = fetch_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    }
    if (!$student) {
        return ['eligible' => false, 'reason' => 'Student not found.', 'student_type' => 'unknown'];
    }

    $scholarship = get_scholarship_program_by_code('RA10931');
    if (!$scholarship || $scholarship['status'] !== 'ACTIVE') {
        return ['eligible' => false, 'reason' => 'FHE program not configured.', 'student_type' => 'unknown'];
    }

    if (has_previous_bachelor_degree($studentId)) {
        return ['eligible' => false, 'reason' => 'Student already holds a bachelor\'s degree — not eligible for FHE.', 'student_type' => (string) ($student['classification'] ?? 'New')];
    }

    $fheSettings = get_fhe_settings();
    $classification = (string) ($student['classification'] ?? 'New');
    $programId = (int) $student['program_id'];
    $yearLevel = (int) $student['year_level'];
    $academicStatus = (string) ($student['academic_status'] ?? 'active');

    $allowedSemesters = (int) $fheSettings['max_allowed_semesters'];
    $prescribedYears = get_program_duration($programId);
    $prescribedTerms = $prescribedYears * 2;

    $activeSS = get_active_student_scholarship($studentId, 'RA10931');
    $internalConsumed = 0;
    $loaExcluded = 0;
    $termHistory = [];

    if ($activeSS) {
        $terms = get_student_scholarship_terms((int) $activeSS['id']);
        foreach ($terms as $t) {
            $termHistory[] = $t;
            if ($t['consumes_scholarship']) {
                $internalConsumed++;
            }
            if ($t['status'] === 'LOA') {
                $loaExcluded++;
            }
        }
    }

    $previousFhe = 0;
    $previousHei = '';
    $previousHeiType = 'OTHER';
    $pfaRecords = get_previous_financial_assistance($studentId);
    foreach ($pfaRecords as $p) {
        if ((int) ($p['government_funded'] ?? 0) && !(int) ($p['has_bachelor_degree'] ?? 0) && (int) ($p['fhe_verified'] ?? 0)) {
            $previousFhe += (int) ($p['previous_fhe_semesters'] ?? 0);
            if ($previousHei === '') {
                $previousHei = $p['previous_hei'] ?? '';
                $previousHeiType = $p['previous_hei_type'] ?? 'OTHER';
            }
        }
    }

    $totalConsumed = $internalConsumed + $previousFhe;
    $remaining = $allowedSemesters - $totalConsumed;

    $notes = [];
    $notes[] = 'Allowed: ' . $allowedSemesters . ' semesters (' . $fheSettings['max_allowed_years'] . ' years)';
    if ($previousFhe > 0) {
        $notes[] = 'Previous HEI (' . h($previousHei) . '): ' . $previousFhe . ' semesters';
    }
    if ($internalConsumed > 0) {
        $notes[] = 'CvSU FHE consumed: ' . $internalConsumed . ' semesters';
    }
    if ($loaExcluded > 0) {
        $notes[] = 'LOA excluded: ' . $loaExcluded . ' term(s)';
    }

    if ($classification === 'Shiftee') {
        $notes[] = 'Shiftee — previous FHE terms (' . $internalConsumed . ' internal) preserved.';
        $rules = get_scholarship_rules((int) $scholarship['id']);
        $maxShiftYl = (int) setting('max_shifting_year_level', '2');
        if ($maxShiftYl > 0 && $yearLevel > $maxShiftYl) {
            return ['eligible' => false, 'reason' => 'Shifting only allowed up to Year ' . $maxShiftYl . '.', 'student_type' => $classification];
        }
    }

    if ($classification === 'Transferee') {
        if ($previousFhe > 0) {
            $notes[] = 'Transferee — ' . $previousFhe . ' previous FHE semester(s) counted.';
        } else {
            $notes[] = 'Transferee — no verified previous FHE semesters recorded.';
        }
    }

    if ($classification === 'Returnee') {
        $notes[] = 'Returnee — LOA period(s) excluded from FHE consumption.';
    }

    $notes[] = 'Total consumed: ' . $totalConsumed . ' of ' . $allowedSemesters . ' (' . $remaining . ' remaining).';

    $hasOverride = has_active_enrollment_override($studentId, 'FHE');

    if ($remaining <= 0 && !$hasOverride) {
        return [
            'eligible'            => false,
            'reason'              => 'FHE allowance exhausted (' . $totalConsumed . ' of ' . $allowedSemesters . ' semesters used).',
            'student_type'        => $classification,
            'consumed'            => $totalConsumed,
            'internal_consumed'   => $internalConsumed,
            'previous_fhe'        => $previousFhe,
            'loa_excluded'        => $loaExcluded,
            'allowable'           => $allowedSemesters,
            'remaining'           => $remaining,
            'prescribed_years'    => $prescribedYears,
            'notes'               => $notes,
            'has_override'        => false,
            'previous_hei'        => $previousHei,
            'previous_hei_type'   => $previousHeiType,
        ];
    }

    return [
        'eligible'            => true,
        'reason'              => $hasOverride ? 'FHE exhausted but Registrar override active.' : 'Eligible for FHE',
        'student_type'        => $classification,
        'consumed'            => $totalConsumed,
        'internal_consumed'   => $internalConsumed,
        'previous_fhe'        => $previousFhe,
        'loa_excluded'        => $loaExcluded,
        'allowable'           => $allowedSemesters,
        'remaining'           => $remaining,
        'prescribed_years'    => $prescribedYears,
        'notes'               => $notes,
        'has_override'        => $hasOverride,
        'previous_hei'        => $previousHei,
        'previous_hei_type'   => $previousHeiType,
    ];
}

/* ─── Fee Adjustment Calculation ─── */

function calculate_scholarship_adjustments(int $studentId, array $feeItems, ?array $term = null): array
{
    $adjustments = [];
    $student = fetch_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    if (!$student) return $adjustments;

    $activeScholarships = get_student_scholarships($studentId, 'ACTIVE');
    if (empty($activeScholarships)) return $adjustments;

    foreach ($activeScholarships as $ss) {
        $eligibility = check_scholarship_eligibility($studentId, (int) $ss['scholarship_id'], $student, $term);
        if (!$eligibility['eligible']) continue;

        $benefits = get_scholarship_benefits((int) $ss['scholarship_id']);
        if (empty($benefits)) continue;

        foreach ($feeItems as &$item) {
            foreach ($benefits as $benefit) {
                if (strcasecmp($item['fee_name'], $benefit['fee_item_name']) !== 0) continue;
                if ($benefit['status'] !== 'ACTIVE') continue;

                $gross = (float) ($item['gross_amount'] ?? 0);
                if ($gross <= 0) continue;

                $discount = 0.0;
                switch ($benefit['benefit_type']) {
                    case 'WAIVE':
                        $discount = $gross;
                        break;
                    case 'DISCOUNT_PERCENT':
                        $discount = $gross * ((float) $benefit['benefit_value'] / 100);
                        break;
                    case 'DISCOUNT_FIXED':
                        $discount = min((float) $benefit['benefit_value'], $gross);
                        break;
                }

                if ($discount > 0) {
                    $existingDiscount = (float) ($item['discount_amount'] ?? 0);
                    $newDiscount = min($existingDiscount + $discount, $gross);
                    $item['discount_amount'] = $newDiscount;
                    $item['net_amount'] = $gross - $newDiscount;

                    $adjustments[] = [
                        'scholarship'    => $ss['scholarship_name'],
                        'fee_name'       => $item['fee_name'],
                        'benefit_type'   => $benefit['benefit_type'],
                        'discount'       => $discount,
                        'new_discount'   => $newDiscount,
                        'notes'          => $ss['scholarship_code'] . ' — ' . $ss['scholarship_name'],
                    ];
                }
                break;
            }
        }
        unset($item);
    }

    return $adjustments;
}

/* ─── LOA → Scholarship Consumption ─── */

function pause_scholarship_for_loa(int $studentId, int $termId): void
{
    $activeScholarships = get_student_scholarships($studentId, 'ACTIVE');
    foreach ($activeScholarships as $ss) {
        $rules = get_scholarship_rules((int) $ss['scholarship_id']);
        if ($rules && $rules['allow_during_loa']) continue;
        record_scholarship_term((int) $ss['id'], $studentId, $termId, 'LOA', false);
    }
}

function resume_scholarship_from_loa(int $studentId, int $termId): void
{
    $activeScholarships = get_student_scholarships($studentId, 'ACTIVE');
    foreach ($activeScholarships as $ss) {
        $existing = fetch_one(
            'SELECT id FROM student_scholarship_terms WHERE student_scholarship_id = :ssid AND term_id = :tid LIMIT 1',
            ['ssid' => (int) $ss['id'], 'tid' => $termId]
        );
        if ($existing) {
            execute_sql(
                'UPDATE student_scholarship_terms SET status = "NOT_ENROLLED", consumes_scholarship = 0 WHERE id = :id',
                ['id' => (int) $existing['id']]
            );
        }
    }
}

function mark_scholarship_term_on_enrollment(int $studentId, int $termId): void
{
    $activeScholarships = get_student_scholarships($studentId, 'ACTIVE');
    foreach ($activeScholarships as $ss) {
        $eligibility = check_scholarship_eligibility($studentId, (int) $ss['scholarship_id']);
        if (!$eligibility['eligible']) {
            record_scholarship_term((int) $ss['id'], $studentId, $termId, 'NOT_ENROLLED', false);
            continue;
        }
        record_scholarship_term((int) $ss['id'], $studentId, $termId, 'ENROLLED', true);
    }
}

/* ─── Generic Scholarship Eligibility (non-FHE) ─── */

function check_scholarship_eligibility(int $studentId, int $scholarshipId, ?array $student = null, ?array $term = null): array
{
    $scholarship = get_scholarship_program($scholarshipId);
    if (!$scholarship || $scholarship['status'] !== 'ACTIVE') {
        return ['eligible' => false, 'reason' => 'Scholarship program not found or inactive.'];
    }

    $rules = get_scholarship_rules($scholarshipId);
    if (!$rules || $rules['status'] !== 'ACTIVE') {
        return ['eligible' => true, 'reason' => 'No rules configured — eligible by default.'];
    }

    if (!$student) {
        $student = fetch_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    }
    if (!$student) {
        return ['eligible' => false, 'reason' => 'Student not found.'];
    }

    if ($rules['requires_regular_status'] && ($student['status'] ?? '') !== 'Regular') {
        return ['eligible' => false, 'reason' => 'Requires regular academic standing.'];
    }

    if ($rules['requires_active_enrollment'] && ($student['academic_status'] ?? '') !== 'active') {
        return ['eligible' => false, 'reason' => 'Student is not actively enrolled.'];
    }

    if (!$rules['allow_during_loa'] && is_student_on_leave($studentId)) {
        return ['eligible' => false, 'reason' => 'Scholarship not available during LOA.'];
    }

    if ($rules['max_year_level'] && ($student['year_level'] ?? 0) > (int) $rules['max_year_level']) {
        return ['eligible' => false, 'reason' => 'Exceeds maximum year level for this scholarship.'];
    }

    if ($rules['min_year_level'] && ($student['year_level'] ?? 0) < (int) $rules['min_year_level']) {
        return ['eligible' => false, 'reason' => 'Below minimum year level for this scholarship.'];
    }

    $activeScholarship = get_active_student_scholarship($studentId, $scholarship['code']);
    if (!$activeScholarship) {
        return ['eligible' => false, 'reason' => 'Student does not have this scholarship assigned.'];
    }

    $consumed = get_consumed_scholarship_terms((int) $activeScholarship['id']);
    $totalEntitlement = (int) $scholarship['duration_value'];
    if ($scholarship['duration_type'] === 'YEARS') {
        $totalEntitlement *= 2;
    }
    $remaining = $totalEntitlement - $consumed + (int) ($scholarship['grace_period_terms'] ?? 0);

    if ($remaining <= 0) {
        return ['eligible' => false, 'reason' => 'Scholarship entitlement fully consumed (' . $consumed . ' of ' . $totalEntitlement . ' terms).'];
    }

    return [
        'eligible'             => true,
        'reason'               => 'Eligible',
        'student_scholarship'  => $activeScholarship,
        'consumed_terms'       => $consumed,
        'total_entitlement'    => $totalEntitlement,
        'remaining_terms'      => $remaining,
        'grace_period'         => (int) ($scholarship['grace_period_terms'] ?? 0),
    ];
}

/* ─── Financial Profile (replaces hardcoded logic) ─── */

function scholarship_financial_profile(array $student, ?array $term = null): array
{
    $studentId = (int) $student['id'];
    $term = $term ?? current_term();
    if (!$term) {
        return ['status' => 'tuition', 'scholarship_applies' => false, 'adjustments' => []];
    }

    $fhe = get_active_student_scholarship($studentId, 'RA10931');
    if (!$fhe) {
        $override = (string) ($student['ra10931_override'] ?? 'auto');
        if ($override !== 'auto' && $override !== '') {
            return ['status' => $override, 'scholarship_applies' => ($override === 'free'), 'adjustments' => []];
        }
        return ['status' => 'tuition', 'scholarship_applies' => false, 'adjustments' => []];
    }

    $eligibility = check_fhe_eligibility($studentId, $student, $term);
    if (!$eligibility['eligible']) {
        return ['status' => 'tuition', 'scholarship_applies' => false, 'reason' => $eligibility['reason'], 'adjustments' => []];
    }

    return [
        'status'             => 'free',
        'scholarship_applies' => true,
        'scholarship'        => $fhe,
        'eligibility'        => $eligibility,
        'adjustments'        => [],
    ];
}

/* ─── Shifting Year Level Check ─── */

function can_student_shift(int $studentId, ?array $student = null): array
{
    if (!$student) {
        $student = fetch_one('SELECT * FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    }
    if (!$student) {
        return ['allowed' => false, 'reason' => 'Student not found.'];
    }

    $maxShiftYl = (int) setting('max_shifting_year_level', '2');
    if ($maxShiftYl > 0 && (int) $student['year_level'] > $maxShiftYl) {
        return ['allowed' => false, 'reason' => 'Shifting is only allowed until Year ' . $maxShiftYl . '. Current year level: ' . $student['year_level'] . '.'];
    }

    return ['allowed' => true, 'reason' => ''];
}
