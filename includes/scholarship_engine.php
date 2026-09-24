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
        'programs'       => !empty($data['applicable_programs']) ? $data['applicable_programs'] : null,
        'excluded'       => !empty($data['excluded_programs']) ? $data['excluded_programs'] : null,
        'priority'       => (int) ($data['priority'] ?? 1),
        'status'         => $data['status'] ?? 'ACTIVE',
    ];
    if ($existing) {
        execute_sql(
            'UPDATE scholarship_rules SET requires_regular_status = :reg, requires_active_enrollment = :active,
             allow_during_loa = :loa, max_year_level = :max_yl, min_year_level = :min_yl,
             applicable_programs = :programs, excluded_programs = :excluded, priority = :priority, status = :status
             WHERE scholarship_id = :sid',
            $params
        );
    } else {
        execute_sql(
            'INSERT INTO scholarship_rules (scholarship_id, requires_regular_status, requires_active_enrollment,
             allow_during_loa, max_year_level, min_year_level, applicable_programs, excluded_programs, priority, status)
             VALUES (:sid, :reg, :active, :loa, :max_yl, :min_yl, :programs, :excluded, :priority, :status)',
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

/* ─── Scholarship Consumption Tracking ─── */

function record_scholarship_term(int $studentScholarshipId, int $studentId, int $termId, string $status = 'NOT_ENROLLED', bool $consumes = false): void
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

function mark_scholarship_term_enrolled(int $studentScholarshipId, int $termId): void
{
    record_scholarship_term($studentScholarshipId, 0, $termId, 'ENROLLED', true);
}

function mark_scholarship_term_loa(int $studentScholarshipId, int $termId): void
{
    record_scholarship_term($studentScholarshipId, 0, $termId, 'LOA', false);
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

/* ─── Eligibility Check ─── */

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

    $eligibility = check_scholarship_eligibility($studentId, (int) $fhe['scholarship_id'], $student, $term);
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
