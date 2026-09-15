<?php
declare(strict_types=1);

/**
 * Grading Engine — Centralized grade logic for the enrollment system.
 *
 * All grade decisions (passing, blocking, GWA, validation) go through this class
 * using the configurable grade_scale table. No grade values are hardcoded here.
 */
class GradingEngine
{
    // ── Grade Scale ─────────────────────────────────────────────────────────

    private static ?array $scaleCache = null;

    /**
     * Return all active grade_scale rows keyed by grade_code (uppercase).
     */
    public static function getGradeScale(): array
    {
        if (self::$scaleCache !== null) {
            return self::$scaleCache;
        }
        $rows = db()->query(
            "SELECT grade_code, numeric_value, is_passing, is_blocking, counts_for_gwa, is_withdrawal
             FROM grade_scale WHERE is_active = 1 ORDER BY display_order"
        )->fetchAll(PDO::FETCH_ASSOC);

        $scale = [];
        foreach ($rows as $row) {
            $key = strtoupper(trim($row['grade_code']));
            $scale[$key] = [
                'grade_code'     => $row['grade_code'],
                'numeric_value'  => $row['numeric_value'] !== null ? (float) $row['numeric_value'] : null,
                'is_passing'     => (bool) $row['is_passing'],
                'is_blocking'    => (bool) $row['is_blocking'],
                'counts_for_gwa' => (bool) $row['counts_for_gwa'],
                'is_withdrawal'  => (bool) $row['is_withdrawal'],
            ];
        }
        self::$scaleCache = $scale;
        return $scale;
    }

    /**
     * Clear the grade scale cache (for use after settings changes).
     */
    public static function clearCache(): void
    {
        self::$scaleCache = null;
    }

    /**
     * Get the grade scale entry for a given grade code.
     */
    public static function lookupGrade(?string $grade): ?array
    {
        if ($grade === null) return null;
        $normalized = strtoupper(trim($grade));
        if ($normalized === '') return null;
        $scale = self::getGradeScale();
        return $scale[$normalized] ?? null;
    }

    /**
     * Validate a grade against the configured grade scale.
     */
    public static function validateGrade(string $grade): bool
    {
        return self::lookupGrade($grade) !== null;
    }

    /**
     * Get all valid grade codes as a simple array.
     */
    public static function validGradeCodes(): array
    {
        return array_keys(self::getGradeScale());
    }

    // ── Grade Classification ───────────────────────────────────────────────

    /**
     * Parse a grade string to its numeric value. Returns null for non-numeric grades.
     */
    public static function parseNumeric(?string $grade): ?float
    {
        if ($grade === null) return null;
        $normalized = strtoupper(trim($grade));
        if ($normalized === '') return null;

        $entry = self::lookupGrade($normalized);
        if ($entry !== null && $entry['numeric_value'] !== null) {
            return $entry['numeric_value'];
        }

        // Fallback: try direct numeric parse if not in scale
        if (is_numeric($normalized)) {
            return (float) $normalized;
        }

        return null;
    }

    /**
     * Is this grade a passing grade?
     */
    public static function isPassing(?string $grade): bool
    {
        if ($grade === null) return false;
        $normalized = strtoupper(trim($grade));
        if ($normalized === '') return false;

        $entry = self::lookupGrade($normalized);
        if ($entry !== null) {
            return $entry['is_passing'];
        }

        // Fallback for grades not in scale
        $numeric = self::parseNumeric($normalized);
        if ($numeric !== null) {
            return $numeric > 0 && $numeric <= 3.0;
        }
        return false;
    }

    /**
     * Is this grade "blocking" (makes a student irregular)?
     */
    public static function isBlocking(?string $grade): bool
    {
        if ($grade === null) return true;
        $normalized = strtoupper(trim($grade));
        if ($normalized === '') return true;

        $entry = self::lookupGrade($normalized);
        if ($entry !== null) {
            return $entry['is_blocking'];
        }

        // Fallback
        $numeric = self::parseNumeric($normalized);
        if ($numeric !== null) {
            return $numeric > 3.0;
        }
        return true;
    }

    /**
     * Does this grade count toward GWA calculation?
     */
    public static function countsForGwa(?string $grade): bool
    {
        if ($grade === null) return false;
        $normalized = strtoupper(trim($grade));
        if ($normalized === '') return false;

        $entry = self::lookupGrade($normalized);
        if ($entry !== null) {
            return $entry['counts_for_gwa'];
        }

        // Fallback: numeric grades count
        return self::parseNumeric($normalized) !== null;
    }

    /**
     * Is this a withdrawal grade?
     */
    public static function isWithdrawal(?string $grade): bool
    {
        if ($grade === null) return false;
        $normalized = strtoupper(trim($grade));
        $entry = self::lookupGrade($normalized);
        return $entry !== null && $entry['is_withdrawal'];
    }

    // ── Grading Periods ────────────────────────────────────────────────────

    /**
     * Get all active grading periods.
     */
    public static function getGradingPeriods(): array
    {
        return db()->query(
            "SELECT id, period_code, period_name, display_order
             FROM grading_periods WHERE is_active = 1 ORDER BY display_order"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a grading period by code.
     */
    public static function getGradingPeriod(string $code): ?array
    {
        $stmt = db()->prepare(
            "SELECT id, period_code, period_name FROM grading_periods WHERE period_code = ? AND is_active = 1"
        );
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── GWA Calculation ────────────────────────────────────────────────────

    /**
     * Compute GWA from an array of {units, final_grade} items.
     * Returns {gwa, units_total, units_taken, units_earned}.
     */
    public static function computeGwa(array $items): array
    {
        $totalUnits = 0.0;
        $weighted = 0.0;
        $earned = 0.0;
        $allUnits = 0.0;

        foreach ($items as $it) {
            $units = (float) ($it['units'] ?? 0);
            $allUnits += $units;
            $grade = $it['final_grade'] ?? null;

            if ($grade !== null && $grade !== '' && self::countsForGwa($grade)) {
                $numeric = self::parseNumeric($grade);
                if ($numeric !== null) {
                    $weighted += $numeric * $units;
                    $totalUnits += $units;
                    if (self::isPassing($grade)) {
                        $earned += $units;
                    }
                }
            }
        }

        return [
            'gwa'          => $totalUnits > 0 ? round($weighted / $totalUnits, 2) : null,
            'units_total'  => $allUnits,
            'units_taken'  => $totalUnits,
            'units_earned' => $earned,
        ];
    }

    /**
     * Compute cumulative GWA for a student across all terms (or excluding one term).
     */
    public static function computeCumulativeGwa(int $studentId, ?int $excludeTermId = null): array
    {
        $params = ['student_id' => $studentId];
        $filter = '';
        if ($excludeTermId !== null) {
            $filter = ' AND ss.term_id != :exclude_term';
            $params['exclude_term'] = $excludeTermId;
        }

        $rows = db()->prepare(
            "SELECT ss.final_grade, ss.units
             FROM student_subjects ss
             WHERE ss.student_id = :student_id AND ss.enrollment_status != 'dropped'" . $filter
        );
        $rows->execute($params);
        $items = $rows->fetchAll(PDO::FETCH_ASSOC);

        return self::computeGwa($items);
    }

    // ── Student Grade Helpers ───────────────────────────────────────────────

    /**
     * Return a map of subject_id => final_grade for a student (latest grade per subject).
     */
    public static function studentGradeLookup(int $studentId): array
    {
        $rows = db()->prepare(
            "SELECT subject_id, final_grade, MAX(created_at) AS latest_created_at
             FROM student_subjects
             WHERE student_id = :student_id
             GROUP BY subject_id, final_grade
             ORDER BY latest_created_at DESC"
        );
        $rows->execute(['student_id' => $studentId]);

        $lookup = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subjectId = (int) $row['subject_id'];
            if (!array_key_exists($subjectId, $lookup)) {
                $lookup[$subjectId] = $row['final_grade'];
            }
        }
        return $lookup;
    }

    /**
     * Is this student irregular (has any blocking grade)?
     */
    public static function isIrregular(int $studentId): bool
    {
        $rows = db()->prepare(
            "SELECT final_grade FROM student_subjects
             WHERE student_id = :student_id AND final_grade IS NOT NULL"
        );
        $rows->execute(['student_id' => $studentId]);

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::isBlocking($row['final_grade'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a student has passed a prerequisite subject.
     */
    public static function hasPassedPrerequisite(int $studentId, int $prerequisiteSubjectId): bool
    {
        $lookup = self::studentGradeLookup($studentId);
        $grade = $lookup[$prerequisiteSubjectId] ?? null;
        return self::isPassing($grade);
    }

    // ── Grade Entry / Draft ────────────────────────────────────────────────

    /**
     * Save or update a grade draft for a student subject and grading period.
     * Returns ['success' => bool, 'message' => string].
     */
    public static function saveDraft(int $studentSubjectId, int $gradingPeriodId, ?string $grade, int $staffId): array
    {
        $grade = trim($grade);

        // Validate grade
        if ($grade !== '' && !self::validateGrade($grade)) {
            return ['success' => false, 'message' => "Invalid grade code: {$grade}"];
        }

        // Check deadline
        if (self::deadlinePassed()) {
            return ['success' => false, 'message' => 'The grade submission deadline has passed. Contact the registrar.'];
        }

        // Get student subject
        $ss = db()->prepare("SELECT * FROM student_subjects WHERE id = ?");
        $ss->execute([$studentSubjectId]);
        $studentSubject = $ss->fetch(PDO::FETCH_ASSOC);
        if (!$studentSubject) {
            return ['success' => false, 'message' => 'Student subject record not found.'];
        }

        // Get sched_code from the offering
        $offering = db()->prepare(
            "SELECT COALESCE(o.sched_code, '') AS sched_code FROM section_subject_offerings o WHERE o.id = ?"
        );
        $offering->execute([(int) $studentSubject['offering_id']]);
        $schedCode = $offering->fetchColumn() ?: '';

        // Check if grade record exists for this student_subject + period
        $existing = db()->prepare(
            "SELECT id, grade_value, grade_status FROM grades WHERE student_subject_id = ? AND grading_period_id = ?"
        );
        $existing->execute([$studentSubjectId, $gradingPeriodId]);
        $gradeRecord = $existing->fetch(PDO::FETCH_ASSOC);

        $gradeValue = $grade !== '' ? $grade : null;

        if ($gradeRecord) {
            // Cannot edit locked or submitted grades
            if (in_array($gradeRecord['grade_status'], ['locked', 'submitted', 'corrected'], true)) {
                return ['success' => false, 'message' => 'Cannot edit a grade that has been submitted or locked.'];
            }

            $oldValue = $gradeRecord['grade_value'];
            db()->prepare(
                "UPDATE grades SET grade_value = ?, updated_by = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$gradeValue, $staffId, $gradeRecord['id']]);

            // Audit log
            self::auditLog((int) $gradeRecord['id'], 'update', $oldValue, $gradeValue, $staffId);

            $gradeId = (int) $gradeRecord['id'];
        } else {
            $termId = (int) ($studentSubject['term_id'] ?? 0);
            db()->prepare(
                "INSERT INTO grades (student_subject_id, sched_code, term_id, grading_period_id, grade_value, grade_status, entered_by, entered_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())"
            )->execute([$studentSubjectId, $schedCode, $termId ?: null, $gradingPeriodId, $gradeValue, $staffId]);

            $gradeId = (int) db()->lastInsertId();
            self::auditLog($gradeId, 'enter', null, $gradeValue, $staffId);
        }

        // Also update student_subjects for backward compatibility
        self::syncStudentSubjectGrade($studentSubjectId, $gradingPeriodId, $gradeValue);

        return ['success' => true, 'message' => 'Grade saved.', 'grade_id' => $gradeId];
    }

    // ── Grade Submission ───────────────────────────────────────────────────

    /**
     * Submit all draft grades for a sched_code and grading period.
     * The instructor is resolved from the sched_code (not passed manually).
     */
    public static function submitGrades(string $schedCode, int $gradingPeriodId, int $submittedBy): array
    {
        if (self::deadlinePassed()) {
            return ['success' => false, 'message' => 'The grade submission deadline has passed.'];
        }

        // Find all grades for this sched_code + period that are in draft status
        $grades = db()->prepare(
            "SELECT g.id, g.grade_value, g.student_subject_id
             FROM grades g
             WHERE g.sched_code = ? AND g.grading_period_id = ? AND g.grade_status = 'draft'"
        );
        $grades->execute([$schedCode, $gradingPeriodId]);
        $gradeRows = $grades->fetchAll(PDO::FETCH_ASSOC);

        if (empty($gradeRows)) {
            return ['success' => false, 'message' => 'No draft grades found to submit for this sched code.'];
        }

        $count = 0;
        $update = db()->prepare(
            "UPDATE grades SET grade_status = 'submitted', submitted_by = ?, submitted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?"
        );

        foreach ($gradeRows as $row) {
            $update->execute([$submittedBy, $submittedBy, $row['id']]);
            self::auditLog((int) $row['id'], 'submit', 'draft', 'submitted', $submittedBy);

            // Update student_subjects
            $ssUpdate = db()->prepare(
                "UPDATE student_subjects SET grade_submitted_at = NOW(), grade_submitted_by = ? WHERE id = ?"
            );
            $ssUpdate->execute([$submittedBy, $row['student_subject_id']]);

            $count++;
        }

        return ['success' => true, "message" => "{$count} grade(s) submitted.", 'count' => $count];
    }

    // ── Grade Locking ──────────────────────────────────────────────────────

    /**
     * Lock all submitted grades for a sched_code and grading period.
     */
    public static function lockGrades(string $schedCode, int $gradingPeriodId, int $lockedBy): array
    {
        $grades = db()->prepare(
            "SELECT g.id, g.student_subject_id
             FROM grades g
             WHERE g.sched_code = ? AND g.grading_period_id = ? AND g.grade_status = 'submitted'"
        );
        $grades->execute([$schedCode, $gradingPeriodId]);
        $gradeRows = $grades->fetchAll(PDO::FETCH_ASSOC);

        if (empty($gradeRows)) {
            return ['success' => false, 'message' => 'No submitted grades found to lock.'];
        }

        $count = 0;
        $update = db()->prepare(
            "UPDATE grades SET grade_status = 'locked', locked_by = ?, locked_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?"
        );

        $syncLock = db()->prepare(
            "UPDATE student_subjects SET grades_locked = 1, updated_at = NOW() WHERE id = ?"
        );

        foreach ($gradeRows as $row) {
            $update->execute([$lockedBy, $lockedBy, $row['id']]);
            self::auditLog((int) $row['id'], 'lock', 'submitted', 'locked', $lockedBy);
            if (!empty($row['student_subject_id'])) {
                $syncLock->execute([(int) $row['student_subject_id']]);
            }
            $count++;
        }

        return ['success' => true, "message" => "{$count} grade(s) locked.", 'count' => $count];
    }

    // ── Grade Correction ───────────────────────────────────────────────────

    /**
     * Request a correction on a locked grade.
     */
    public static function requestCorrection(int $gradeId, string $newGrade, string $reason, int $requestedBy): array
    {
        if (!self::validateGrade($newGrade)) {
            return ['success' => false, 'message' => "Invalid grade code: {$newGrade}"];
        }

        $grade = db()->prepare("SELECT * FROM grades WHERE id = ?");
        $grade->execute([$gradeId]);
        $record = $grade->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return ['success' => false, 'message' => 'Grade record not found.'];
        }

        if ($record['grade_status'] !== 'locked') {
            return ['success' => false, 'message' => 'Only locked grades can be corrected.'];
        }

        $oldValue = $record['grade_value'];
        db()->prepare(
            "UPDATE grades SET grade_status = 'correction_pending', grade_value = ?, updated_by = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$newGrade, $requestedBy, $gradeId]);

        self::auditLog($gradeId, 'request_correction', $oldValue, $newGrade, $requestedBy, $reason);

        return ['success' => true, 'message' => 'Correction request submitted.'];
    }

    /**
     * Approve a correction request.
     */
    public static function approveCorrection(int $gradeId, int $approvedBy): array
    {
        $grade = db()->prepare("SELECT * FROM grades WHERE id = ?");
        $grade->execute([$gradeId]);
        $record = $grade->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return ['success' => false, 'message' => 'Grade record not found.'];
        }

        if ($record['grade_status'] !== 'correction_pending') {
            return ['success' => false, 'message' => 'This grade is not pending correction.'];
        }

        db()->prepare(
            "UPDATE grades SET grade_status = 'corrected', updated_by = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$approvedBy, $gradeId]);

        self::auditLog($gradeId, 'approve_correction', 'correction_pending', 'corrected', $approvedBy);

        // Sync to student_subjects
        self::syncStudentSubjectGrade((int) $record['student_subject_id'], (int) $record['grading_period_id'], $record['grade_value']);

        return ['success' => true, 'message' => 'Correction approved.'];
    }

    // ── Deadline ───────────────────────────────────────────────────────────

    /**
     * Has the grade submission deadline passed?
     */
    public static function deadlinePassed(): bool
    {
        $deadline = self::getDeadline();
        if ($deadline === null) return false;
        return strtotime($deadline) < time();
    }

    /**
     * Get the grade submission deadline as a datetime string, or null.
     */
    public static function getDeadline(): ?string
    {
        $days = (int) setting('grade_deadline_days', '30');
        if ($days <= 0) return null;

        $term = current_term();
        if ($term === null || empty($term['end_date'])) return null;

        return date('Y-m-d H:i:s', strtotime((string) $term['end_date'] . " +{$days} days"));
    }

    // ── Academic Honors ────────────────────────────────────────────────────

    /**
     * Get all active honor rules.
     */
    public static function getHonorRules(): array
    {
        return db()->query(
            "SELECT * FROM academic_honor_rules WHERE is_active = 1 ORDER BY display_order"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Evaluate honor eligibility for a student at a given term.
     * Returns array of ['rule' => ..., 'eligible' => bool, 'reasons' => [...]].
     */
    public static function evaluateHonors(int $studentId, int $termId): array
    {
        $rules = self::getHonorRules();
        if (empty($rules)) return [];

        // Get cumulative GWA (all terms up to and including this term)
        $gwaData = self::computeCumulativeGwaForTerm($studentId, $termId);
        $gwa = $gwaData['gwa'];
        $earnedUnits = $gwaData['units_earned'];

        // Get all grades for this student
        $allGrades = self::getAllStudentGrades($studentId, $termId);

        $results = [];
        foreach ($rules as $rule) {
            $eligible = true;
            $reasons = [];

            // GWA range check
            if ($gwa === null) {
                $eligible = false;
                $reasons[] = 'No GWA available.';
            } else {
                if ($rule['minimum_gwa'] !== null && $gwa < (float) $rule['minimum_gwa']) {
                    $eligible = false;
                    $reasons[] = "GWA {$gwa} is below minimum {$rule['minimum_gwa']}.";
                }
                if ($rule['maximum_gwa'] !== null && $gwa > (float) $rule['maximum_gwa']) {
                    $eligible = false;
                    $reasons[] = "GWA {$gwa} exceeds maximum {$rule['maximum_gwa']}.";
                }
            }

            // Lowest grade check
            if ($rule['minimum_passing_grade'] !== null) {
                $lowest = self::getLowestNumericGrade($allGrades);
                if ($lowest !== null && $lowest > (float) $rule['minimum_passing_grade']) {
                    $eligible = false;
                    $reasons[] = "Lowest grade {$lowest} exceeds allowed maximum {$rule['minimum_passing_grade']}.";
                }
            }

            // Failed grade check
            if (!$rule['allow_failed_grade']) {
                $hasFailed = self::hasGradeInSet($allGrades, function ($g) {
                    return !self::isPassing($g) && !self::isWithdrawal($g) && strtoupper(trim($g)) !== 'INC';
                });
                if ($hasFailed) {
                    $eligible = false;
                    $reasons[] = 'Student has failing grades.';
                }
            }

            // INC check
            if (!$rule['allow_inc']) {
                $hasInc = self::hasGradeInSet($allGrades, fn($g) => strtoupper(trim($g)) === 'INC');
                if ($hasInc) {
                    $eligible = false;
                    $reasons[] = 'Student has INC grades.';
                }
            }

            // DRP check
            if (!$rule['allow_drp']) {
                $hasDrp = self::hasGradeInSet($allGrades, fn($g) => strtoupper(trim($g)) === 'DRP');
                if ($hasDrp) {
                    $eligible = false;
                    $reasons[] = 'Student has DRP grades.';
                }
            }

            // Withdrawal check
            if (!$rule['allow_withdrawal']) {
                $hasW = self::hasGradeInSet($allGrades, fn($g) => self::isWithdrawal($g));
                if ($hasW) {
                    $eligible = false;
                    $reasons[] = 'Student has withdrawal grades.';
                }
            }

            // Required units check
            if ($rule['required_units'] !== null && $earnedUnits < (float) $rule['required_units']) {
                $eligible = false;
                $reasons[] = "Earned units {$earnedUnits} below required {$rule['required_units']}.";
            }

            $results[] = [
                'rule'     => $rule,
                'eligible' => $eligible,
                'reasons'  => $reasons,
            ];
        }

        return $results;
    }

    /**
     * Get official (registrar-confirmed) honors for a student.
     */
    public static function getOfficialHonors(int $studentId): array
    {
        $stmt = db()->prepare(
            "SELECT shr.*, ahr.honor_name
             FROM student_honor_status shr
             INNER JOIN academic_honor_rules ahr ON ahr.id = shr.honor_rule_id
             WHERE shr.student_id = ? AND shr.is_official = 1
             ORDER BY ahr.display_order"
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Instructor Resolution ──────────────────────────────────────────────

    /**
     * Resolve the instructor for a given sched_code or offering.
     * Uses COALESCE(class_schedules.instructor_id, section_subject_offerings.instructor_id).
     */
    public static function resolveInstructor(string $schedCode): ?array
    {
        $stmt = db()->prepare(
            "SELECT COALESCE(cs.instructor_id, o.instructor_id) AS instructor_id,
                    st.full_name, st.staff_id
             FROM section_subject_offerings o
             LEFT JOIN class_schedules cs ON cs.schedule_code = ?
             LEFT JOIN staff st ON st.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
             WHERE o.sched_code = ?
             LIMIT 1"
        );
        $stmt->execute([$schedCode, $schedCode]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Grade Status Helpers ───────────────────────────────────────────────

    /**
     * Get grade status display label.
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'               => 'Draft',
            'submitted'           => 'Submitted',
            'locked'              => 'Locked',
            'correction_pending'  => 'Correction Pending',
            'corrected'           => 'Corrected',
            default               => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * Get badge CSS class for a grade status.
     */
    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft'               => 'secondary',
            'submitted'           => 'info',
            'locked'              => 'success',
            'correction_pending'  => 'warning',
            'corrected'           => 'success',
            default               => 'info',
        };
    }

    // ── Registrar Grade Management Queries ─────────────────────────────────

    /**
     * Get grade summary by sched_code for a term.
     */
    public static function getGradeSummaryBySchedCode(int $termId, array $filters = []): array
    {
        $params = ['term_id' => $termId];
        $where = "o.term_id = :term_id AND o.status = 'active'";

        if (!empty($filters['sched_code'])) {
            $where .= " AND o.sched_code LIKE :sched_code";
            $params['sched_code'] = '%' . $filters['sched_code'] . '%';
        }
        if (!empty($filters['department_id'])) {
            $where .= " AND p.department_id = :dept_id";
            $params['dept_id'] = (int) $filters['department_id'];
        }
        if (!empty($filters['subject_id'])) {
            $where .= " AND o.subject_id = :subject_id";
            $params['subject_id'] = (int) $filters['subject_id'];
        }
        if (!empty($filters['instructor_id'])) {
            $where .= " AND COALESCE(cs.instructor_id, o.instructor_id) = :instructor_id";
            $params['instructor_id'] = (int) $filters['instructor_id'];
        }

        $sql = "SELECT o.id AS offering_id, o.sched_code,
                       sub.subject_code, sub.subject_description,
                       sec.year_level, sec.section_name, p.program_code,
                       COALESCE(st.full_name, 'TBA') AS instructor_name,
                       (SELECT COUNT(*) FROM student_subjects ss2
                        WHERE ss2.offering_id = o.id AND ss2.enrollment_status = 'enrolled') AS enrolled_count,
                       (SELECT COUNT(*) FROM grades g2
                        INNER JOIN grading_periods gp ON gp.id = g2.grading_period_id
                        WHERE g2.sched_code = o.sched_code AND gp.period_code = 'final' AND g2.grade_status IN ('submitted','locked','corrected')) AS graded_count,
                       (SELECT g3.grade_status FROM grades g3
                        INNER JOIN grading_periods gp2 ON gp2.id = g3.grading_period_id
                        WHERE g3.sched_code = o.sched_code AND gp2.period_code = 'final'
                        ORDER BY FIELD(g3.grade_status, 'locked','corrected','submitted','correction_pending','draft') LIMIT 1) AS overall_status
                FROM section_subject_offerings o
                INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                INNER JOIN sections sec ON sec.id = o.section_id
                INNER JOIN programs p ON p.programs_id = sec.program_id
                LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
                LEFT JOIN staff st ON st.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
                WHERE {$where}
                GROUP BY o.id
                ORDER BY o.sched_code";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get detailed grades for a specific sched_code and grading period.
     */
    public static function getGradesForSchedCode(string $schedCode, int $gradingPeriodId): array
    {
        $stmt = db()->prepare(
            "SELECT ss.id AS student_subject_id, s.student_number,
                    CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
                    g.id AS grade_id, g.grade_value, g.grade_status,
                    g.entered_at, g.submitted_at, g.locked_at,
                    g.entered_by, g.submitted_by, g.locked_by,
                    e.full_name AS entered_by_name,
                    s.full_name AS submitted_by_name,
                    l.full_name AS locked_by_name
             FROM student_subjects ss
             INNER JOIN students s ON s.id = ss.student_id
             LEFT JOIN grades g ON g.student_subject_id = ss.id AND g.grading_period_id = ?
             LEFT JOIN staff e ON e.staff_id = g.entered_by
             LEFT JOIN staff s2 ON s2.staff_id = g.submitted_by
             LEFT JOIN staff l ON l.staff_id = g.locked_by
             INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
             WHERE o.sched_code = ? AND ss.enrollment_status = 'enrolled'
             ORDER BY s.student_number"
        );
        $stmt->execute([$gradingPeriodId, $schedCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    private static function auditLog(int $gradeId, string $action, ?string $oldValue, ?string $newValue, int $performedBy, ?string $reason = null): void
    {
        $stmt = db()->prepare(
            "INSERT INTO grade_audit_log (grade_id, action, old_value, new_value, performed_by, reason, performed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$gradeId, $action, $oldValue, $newValue, $performedBy, $reason]);
    }

    private static function syncStudentSubjectGrade(int $studentSubjectId, int $gradingPeriodId, ?string $gradeValue): void
    {
        // Only sync "final" period grade to student_subjects.final_grade for backward compatibility
        $period = self::getGradingPeriod('final');
        if ($period && (int) $period['id'] === $gradingPeriodId) {
            db()->prepare(
                "UPDATE student_subjects SET final_grade = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$gradeValue, $studentSubjectId]);
        }
        // Sync "midterm" period grade to student_subjects.midterm_grade
        $midPeriod = self::getGradingPeriod('midterm');
        if ($midPeriod && (int) $midPeriod['id'] === $gradingPeriodId) {
            db()->prepare(
                "UPDATE student_subjects SET midterm_grade = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$gradeValue, $studentSubjectId]);
        }
    }

    private static function computeCumulativeGwaForTerm(int $studentId, int $termId): array
    {
        $stmt = db()->prepare(
            "SELECT ss.final_grade, ss.units
             FROM student_subjects ss
             INNER JOIN academic_terms t ON t.id = ss.term_id
             INNER JOIN academic_years ay ON ay.id = t.academic_year_id
             WHERE ss.student_id = :student_id AND ss.enrollment_status != 'dropped'
             AND (ay.start_year < (SELECT ay2.start_year FROM academic_terms t2
                                  INNER JOIN academic_years ay2 ON ay2.id = t2.academic_year_id
                                  WHERE t2.id = :term_id)
                  OR (ay.start_year = (SELECT ay2.start_year FROM academic_terms t2
                                       INNER JOIN academic_years ay2 ON ay2.id = t2.academic_year_id
                                       WHERE t2.id = :term_id2)
                      AND FIELD(t.semester, '1', '2', 'mid') <= FIELD(
                          (SELECT t3.semester FROM academic_terms t3 WHERE t3.id = :term_id3),
                          '1', '2', 'mid'
                      )))"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'term_id'    => $termId,
            'term_id2'   => $termId,
            'term_id3'   => $termId,
        ]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return self::computeGwa($items);
    }

    private static function getAllStudentGrades(int $studentId, int $termId): array
    {
        $stmt = db()->prepare(
            "SELECT ss.final_grade
             FROM student_subjects ss
             INNER JOIN academic_terms t ON t.id = ss.term_id
             INNER JOIN academic_years ay ON ay.id = t.academic_year_id
             WHERE ss.student_id = :student_id AND ss.enrollment_status != 'dropped'
             AND ss.final_grade IS NOT NULL
             AND (ay.start_year < (SELECT ay2.start_year FROM academic_terms t2
                                  INNER JOIN academic_years ay2 ON ay2.id = t2.academic_year_id
                                  WHERE t2.id = :term_id)
                  OR (ay.start_year = (SELECT ay2.start_year FROM academic_terms t2
                                       INNER JOIN academic_years ay2 ON ay2.id = t2.academic_year_id
                                       WHERE t2.id = :term_id2)
                      AND FIELD(t.semester, '1', '2', 'mid') <= FIELD(
                          (SELECT t3.semester FROM academic_terms t3 WHERE t3.id = :term_id3),
                          '1', '2', 'mid'
                      )))"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'term_id'    => $termId,
            'term_id2'   => $termId,
            'term_id3'   => $termId,
        ]);
        $grades = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['final_grade'] !== null) {
                $grades[] = $row['final_grade'];
            }
        }
        return $grades;
    }

    private static function getLowestNumericGrade(array $grades): ?float
    {
        $lowest = null;
        foreach ($grades as $g) {
            $n = self::parseNumeric($g);
            if ($n !== null && ($lowest === null || $n > $lowest)) {
                $lowest = $n;
            }
        }
        return $lowest;
    }

    private static function hasGradeInSet(array $grades, callable $predicate): bool
    {
        foreach ($grades as $g) {
            if ($predicate($g)) return true;
        }
        return false;
    }
}
