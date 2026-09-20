<?php
declare(strict_types=1);

function ensure_soft_delete_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $needed = [
        'staff'                       => 'AFTER `dept_id`',
        'sections'                    => 'AFTER `max_slots`',
        'subjects'                    => 'AFTER `subject_description`',
        'users'                       => 'AFTER `password`',
        'program_curriculum'          => '',
        'academic_terms'              => '',
        'academic_years'              => '',
        'section_subject_offerings'   => '',
        'departments'                 => '',
    ];

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        foreach ($needed as $table => $position) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
            if ($stmt && $stmt->fetch()) continue;
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' {$position}";
            try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
        }
    } catch (Throwable $e) {
    }
}

function ensure_notifications_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_notifications (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              student_id INT NOT NULL,
              type       VARCHAR(50) NOT NULL DEFAULT 'info',
              subject    VARCHAR(255) NOT NULL,
              body       TEXT NOT NULL,
              is_read    TINYINT(1) NOT NULL DEFAULT 0,
              dismissed  TINYINT(1) NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_notif_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_notifications` LIKE 'type'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_notifications` ADD COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'info' AFTER `student_id`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_notifications` LIKE 'dismissed'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_notifications` ADD COLUMN `dismissed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_deadline_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $deadlines = ['adviser_deadline', 'chair_deadline', 'registrar_deadline', 'grade_deadline'];
        foreach ($deadlines as $col) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `academic_terms` LIKE '$col'");
            if (!$stmt || !$stmt->fetch()) {
                $pdo->exec("ALTER TABLE `academic_terms` ADD COLUMN `$col` DATE NULL AFTER `end_date`");
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_processing_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $cols = [
            'adviser_processed_at',
            'chair_processed_at',
            'registrar_processed_at',
            'adviser_processed_by',
            'chair_processed_by',
            'registrar_processed_by',
        ];

        foreach ($cols as $col) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE '{$col}'");
            if ($stmt && $stmt->fetch()) continue;

            if (str_ends_with($col, '_at')) {
                $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `{$col}` TIMESTAMP NULL");
            } else {
                $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `{$col}` INT NULL");
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_audit_log_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS enrollment_audit_log (
              id           INT AUTO_INCREMENT PRIMARY KEY,
              request_id   INT NOT NULL,
              action       VARCHAR(50) NOT NULL,
              actor_id     INT NULL,
              actor_role   VARCHAR(50) NULL,
              old_status   VARCHAR(50) NULL,
              new_status   VARCHAR(50) NULL,
              remark       TEXT NULL,
              created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_audit_request FOREIGN KEY (request_id) REFERENCES enrollment_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_staff_notifications_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS staff_notifications (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              staff_id   INT NOT NULL,
              type       VARCHAR(50) NOT NULL DEFAULT 'info',
              subject    VARCHAR(255) NOT NULL,
              body       TEXT NOT NULL,
              is_read    TINYINT(1) NOT NULL DEFAULT 0,
              dismissed  TINYINT(1) NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $stmt = $pdo->query("SHOW COLUMNS FROM `staff_notifications` LIKE 'type'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `staff_notifications` ADD COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'info' AFTER `staff_id`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `staff_notifications` LIKE 'dismissed'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `staff_notifications` ADD COLUMN `dismissed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_add_drop_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS add_drop_requests (
              id               INT AUTO_INCREMENT PRIMARY KEY,
              student_id       INT NOT NULL,
              term_id          INT NOT NULL DEFAULT 0,
              action_type      ENUM('add','drop') NOT NULL DEFAULT 'add',
              offering_id      INT NULL,
              subject_id       INT NULL,
              section_id       INT NULL,
              curriculum_id    INT NULL,
              units            DECIMAL(4,1) NOT NULL DEFAULT 0,
              workflow_status  ENUM('submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
              adviser_status   ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              chair_status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              registrar_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              adviser_remark   TEXT NULL,
              chair_remark     TEXT NULL,
              registrar_remark TEXT NULL,
              adviser_processed_at  TIMESTAMP NULL,
              chair_processed_at    TIMESTAMP NULL,
              registrar_processed_at TIMESTAMP NULL,
              adviser_processed_by  INT NULL,
              chair_processed_by    INT NULL,
              registrar_processed_by INT NULL,
              created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_adr_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
              CONSTRAINT fk_adr_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
              CONSTRAINT fk_adr_offering FOREIGN KEY (offering_id) REFERENCES section_subject_offerings(id) ON DELETE SET NULL,
              CONSTRAINT fk_adr_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $addCols = [
            'term_id'          => 'INT NOT NULL DEFAULT 0 AFTER student_id',
            'action_type'      => "ENUM('add','drop') NOT NULL DEFAULT 'add' AFTER term_id",
            'offering_id'      => 'INT NULL AFTER action_type',
            'section_id'       => 'INT NULL AFTER subject_id',
            'curriculum_id'    => 'INT NULL AFTER section_id',
            'units'            => "DECIMAL(4,1) NOT NULL DEFAULT 0 AFTER curriculum_id",
            'workflow_status'  => "ENUM('submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'submitted' AFTER units",
            'adviser_status'   => "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER workflow_status",
            'chair_status'     => "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER adviser_status",
            'registrar_status' => "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER chair_status",
            'adviser_remark'   => 'TEXT NULL AFTER registrar_status',
            'chair_remark'     => 'TEXT NULL AFTER adviser_remark',
            'registrar_remark' => 'TEXT NULL AFTER chair_remark',
            'adviser_processed_at'  => 'TIMESTAMP NULL AFTER registrar_remark',
            'chair_processed_at'    => 'TIMESTAMP NULL AFTER adviser_processed_at',
            'registrar_processed_at' => 'TIMESTAMP NULL AFTER chair_processed_at',
            'adviser_processed_by'  => 'INT NULL AFTER registrar_processed_at',
            'chair_processed_by'    => 'INT NULL AFTER adviser_processed_by',
            'registrar_processed_by' => 'INT NULL AFTER chair_processed_by',
            'created_at'       => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER registrar_processed_by',
            'updated_at'       => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ];

        foreach ($addCols as $col => $def) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `add_drop_requests` LIKE '$col'");
            if (!$stmt || !$stmt->fetch()) {
                $pdo->exec("ALTER TABLE `add_drop_requests` ADD COLUMN `$col` $def");
            }
        }
    } catch (\Throwable $e) {
    }

    ensure_curriculum_prereq_columns();
}

function ensure_curriculum_prereq_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `program_curriculum` LIKE 'prerequisite_subject_2_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `program_curriculum` ADD COLUMN `prerequisite_subject_2_id` INT NULL AFTER `prerequisite_subject_id`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `program_curriculum` LIKE 'prerequisite_subject_3_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `program_curriculum` ADD COLUMN `prerequisite_subject_3_id` INT NULL AFTER `prerequisite_subject_2_id`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `program_curriculum` LIKE 'standing'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `program_curriculum` ADD COLUMN `standing` VARCHAR(50) NULL AFTER `prerequisite_subject_3_id`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_draft_status(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'workflow_status'");
        $row = $stmt ? $stmt->fetch() : null;
        if ($row && strpos($row['Type'], 'draft') === false) {
            $pdo->exec("ALTER TABLE `enrollment_requests` MODIFY COLUMN `workflow_status` ENUM('draft','submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'draft'");
        }
    } catch (\Throwable $e) {
    }

    ensure_password_reset_tokens_table();
    ensure_payments_table();
}

function ensure_email_verification_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'verification_token'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `verification_token` VARCHAR(255) NULL AFTER `password`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'verified_at'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `verified_at` TIMESTAMP NULL AFTER `verification_token`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'display_name'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `display_name` VARCHAR(255) NULL AFTER `email`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_password_reset_tokens_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              user_id    INT NOT NULL,
              token      VARCHAR(255) NOT NULL UNIQUE,
              expires_at TIMESTAMP NOT NULL,
              used       TINYINT(1) NOT NULL DEFAULT 0,
              used_at    TIMESTAMP NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(users_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $stmt = $pdo->query("SHOW COLUMNS FROM `password_reset_tokens` LIKE 'user_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `password_reset_tokens` ADD COLUMN `user_id` INT NULL AFTER `id`");
            $pdo->exec("ALTER TABLE `password_reset_tokens` DROP COLUMN `email`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `password_reset_tokens` LIKE 'used'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `password_reset_tokens` ADD COLUMN `used` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expires_at`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `password_reset_tokens` LIKE 'used_at'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `password_reset_tokens` ADD COLUMN `used_at` TIMESTAMP NULL AFTER `used`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_curriculum_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `programs` LIKE 'program_major'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `programs` ADD COLUMN `program_major` VARCHAR(255) NULL AFTER `program_name`");
        }

        $creditCols = ['lec_credit', 'lab_credit', 'lec_hours', 'lab_hours'];
        foreach ($creditCols as $col) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `subjects` LIKE '{$col}'");
            if (!$stmt || !$stmt->fetch()) {
                $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `{$col}` DECIMAL(4,1) NOT NULL DEFAULT 0 AFTER `subject_description`");
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_drop_units_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `subjects` LIKE 'units'");
        if ($stmt && $stmt->fetch()) {
            $pdo->exec("ALTER TABLE `subjects` DROP COLUMN `units`");
        }
    } catch (Throwable $e) {
    }
}

function ensure_fee_items_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fee_items (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                category      ENUM('laboratory','other','assessment') NOT NULL,
                fee_name      VARCHAR(255) NOT NULL,
                amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                program_id    INT NULL,
                year_level    INT NULL,
                semester      VARCHAR(20) NULL,
                is_mandatory  TINYINT(1) NOT NULL DEFAULT 0,
                is_active     TINYINT(1) NOT NULL DEFAULT 1,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_fee_program FOREIGN KEY (program_id) REFERENCES programs(programs_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_payments_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
              id              INT AUTO_INCREMENT PRIMARY KEY,
              request_id      INT NOT NULL,
              student_id      INT NOT NULL,
              or_number       VARCHAR(50) NULL,
              amount_paid     DECIMAL(10,2) NOT NULL DEFAULT 0,
              balance         DECIMAL(10,2) NOT NULL DEFAULT 0,
              payment_method  ENUM('cash','check','bank_transfer','online') NOT NULL DEFAULT 'cash',
              payment_date    DATE NOT NULL,
              remarks         TEXT NULL,
              cashier_id      INT NOT NULL,
              created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_pay_request FOREIGN KEY (request_id) REFERENCES enrollment_requests(id) ON DELETE CASCADE,
              CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
              CONSTRAINT fk_pay_cashier FOREIGN KEY (cashier_id) REFERENCES users(users_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'payment_status'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `payment_status` ENUM('unpaid','partial','paid','waived') NOT NULL DEFAULT 'unpaid' AFTER `ra10931_status`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_student_subjects_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'remarks'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `remarks` VARCHAR(255) NULL AFTER `final_grade`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_sched_code_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `section_subject_offerings` LIKE 'sched_code'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `section_subject_offerings` ADD COLUMN `sched_code` VARCHAR(50) NULL AFTER `max_slots`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_fee_workflow_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `programs` LIKE 'lab_fee_per_unit'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `programs` ADD COLUMN `lab_fee_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `program_major`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'workflow_status'");
        $row = $stmt ? $stmt->fetch() : null;
        if ($row && strpos($row['Type'], 'registrar_forwarded') === false) {
            $pdo->exec("ALTER TABLE `enrollment_requests` MODIFY COLUMN `workflow_status` ENUM('draft','submitted','adviser_approved','chair_approved','registrar_forwarded','cashier_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'draft'");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'cashier_processed_at'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `cashier_processed_at` TIMESTAMP NULL AFTER `registrar_processed_by`");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'cashier_processed_by'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `cashier_processed_by` INT NULL AFTER `cashier_processed_at`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_schedule_code_id_in_student_subjects(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'schedule_code_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `schedule_code_id` INT NULL AFTER `subject_id`, ADD INDEX idx_ss_sched_code (schedule_code_id)");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `class_schedules` LIKE 'start_time'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `class_schedules` ADD COLUMN `start_time` TIME NULL AFTER `day`");
            $pdo->exec("ALTER TABLE `class_schedules` ADD COLUMN `end_time` TIME NULL AFTER `start_time`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_schedule_codes_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW TABLES LIKE 'schedule_codes'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE schedule_codes (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  sched_code VARCHAR(20) NOT NULL UNIQUE,
                  offering_id INT NOT NULL,
                  term_id INT NOT NULL,
                  program_id INT NOT NULL,
                  section_id INT NOT NULL,
                  curriculum_id INT NOT NULL,
                  subject_id INT NOT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  CONSTRAINT fk_sc_offering FOREIGN KEY (offering_id) REFERENCES section_subject_offerings(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_program FOREIGN KEY (program_id) REFERENCES programs(programs_id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_curriculum FOREIGN KEY (curriculum_id) REFERENCES program_curriculum(curriculum_id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
                  INDEX idx_sched_code (sched_code),
                  INDEX idx_term_program (term_id, program_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_class_schedules_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW TABLES LIKE 'class_schedules'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE class_schedules (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  schedule_code_id INT NOT NULL UNIQUE,
                  day VARCHAR(20) NULL,
                  time_range VARCHAR(60) NULL,
                  room VARCHAR(100) NULL,
                  instructor_id INT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  CONSTRAINT fk_cs_schedule_code FOREIGN KEY (schedule_code_id) REFERENCES schedule_codes(id) ON DELETE CASCADE,
                  CONSTRAINT fk_cs_instructor FOREIGN KEY (instructor_id) REFERENCES staff(staff_id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_multiple_schedules_per_offering(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $uniqueName = null;
        $stmt = $pdo->query("SHOW INDEX FROM `class_schedules` WHERE Column_name = 'schedule_code_id'");
        if ($stmt) {
            foreach ($stmt as $idx) {
                if ((int) $idx['Non_unique'] === 0) {
                    $uniqueName = $idx['Key_name'];
                }
            }
        }

        if ($uniqueName !== null) {
            // The foreign key needs an index on schedule_code_id, so add the
            // non-unique index first, then drop the old unique one.
            $idxExists = false;
            $stmt = $pdo->query("SHOW INDEX FROM `class_schedules` WHERE Key_name = 'idx_cs_schedule_code'");
            if ($stmt && $stmt->fetch()) {
                $idxExists = true;
            }
            if (!$idxExists) {
                $pdo->exec("ALTER TABLE `class_schedules` ADD INDEX `idx_cs_schedule_code` (`schedule_code_id`)");
            }
            $pdo->exec("ALTER TABLE `class_schedules` DROP INDEX `{$uniqueName}`");
        } else {
            $idxExists = false;
            $stmt = $pdo->query("SHOW INDEX FROM `class_schedules` WHERE Key_name = 'idx_cs_schedule_code'");
            if ($stmt && $stmt->fetch()) {
                $idxExists = true;
            }
            if (!$idxExists) {
                $pdo->exec("ALTER TABLE `class_schedules` ADD INDEX `idx_cs_schedule_code` (`schedule_code_id`)");
            }
        }
    } catch (\Throwable $e) {
    }
}

function backfill_schedule_code_id_in_student_subjects(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM student_subjects WHERE schedule_code_id IS NOT NULL LIMIT 1");
        if ($stmt && $stmt->fetch()['cnt'] > 0) return;

        $pdo->exec("
            UPDATE student_subjects ss
            INNER JOIN schedule_codes sc ON sc.offering_id = ss.offering_id
            SET ss.schedule_code_id = sc.id
            WHERE sc.offering_id IS NOT NULL
        ");
    } catch (\Throwable $e) {
    }
}

function migrate_offering_data_to_new_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM schedule_codes");
        if (!$stmt) return;
        $row = $stmt->fetch();
        if ($row && (int)$row['cnt'] > 0) return;

        $offerings = $pdo->query("
            SELECT o.id, o.sched_code, o.term_id, o.section_id, o.curriculum_id, o.subject_id,
                   o.day_of_week, o.time_range, o.room, o.instructor_id,
                   sec.program_id
            FROM section_subject_offerings o
            INNER JOIN sections sec ON sec.id = o.section_id
            WHERE o.sched_code IS NOT NULL AND o.sched_code != ''
        ");
        if (!$offerings) return;

        $insertSc = $pdo->prepare("
            INSERT INTO schedule_codes (sched_code, offering_id, term_id, program_id, section_id, curriculum_id, subject_id, created_at)
            VALUES (:code, :offering_id, :term_id, :program_id, :section_id, :curriculum_id, :subject_id, NOW())
        ");
        $insertCs = $pdo->prepare("
            INSERT INTO class_schedules (schedule_code_id, day, time_range, room, instructor_id, created_at)
            VALUES (:sc_id, :day, :time_range, :room, :instructor_id, NOW())
        ");

        foreach ($offerings as $o) {
            $insertSc->execute([
                'code' => $o['sched_code'],
                'offering_id' => $o['id'],
                'term_id' => $o['term_id'],
                'program_id' => $o['program_id'],
                'section_id' => $o['section_id'],
                'curriculum_id' => $o['curriculum_id'],
                'subject_id' => $o['subject_id'],
            ]);
            $scId = $pdo->lastInsertId();

            $day = $o['day_of_week'] ?? null;
            $time = $o['time_range'] ?? null;
            $room = $o['room'] ?? null;
            $instructorId = $o['instructor_id'] ?? null;

            if ($day || $time || $room || $instructorId) {
                $insertCs->execute([
                    'sc_id' => $scId,
                    'day' => $day,
                    'time_range' => $time,
                    'room' => $room,
                    'instructor_id' => $instructorId,
                ]);
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_new_class_schedules_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $cols = [
            'subject_id'       => 'INT NULL AFTER `schedule_code_id`',
            'section_id'       => 'INT NULL AFTER `subject_id`',
            'term_id'          => 'INT NULL AFTER `section_id`',
            'schedule_code'    => "VARCHAR(20) NULL AFTER `term_id`",
            'status'           => "ENUM('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft' AFTER `schedule_code`",
            'created_by'       => 'INT NULL AFTER `status`',
            'submitted_at'     => 'TIMESTAMP NULL AFTER `created_by`',
            'approved_by'      => 'INT NULL AFTER `submitted_at`',
            'approved_at'      => 'TIMESTAMP NULL AFTER `approved_by`',
            'rejection_reason' => 'TEXT NULL AFTER `approved_at`',
        ];

        foreach ($cols as $col => $def) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `class_schedules` LIKE '{$col}'");
            if ($stmt && $stmt->fetch()) continue;
            try { $pdo->exec("ALTER TABLE `class_schedules` ADD COLUMN `{$col}` {$def}"); } catch (\Throwable $e) {}
        }

        $stmt = $pdo->query("SHOW INDEX FROM `class_schedules` WHERE Column_name = 'schedule_code' AND Non_unique = 0");
        if (!$stmt || !$stmt->fetch()) {
            try { $pdo->exec("ALTER TABLE `class_schedules` ADD UNIQUE INDEX `uq_cs_schedule_code` (`schedule_code`)"); } catch (\Throwable $e) {}
        }

        $idxCols = ['subject_id', 'section_id', 'term_id', 'status'];
        foreach ($idxCols as $col) {
            $idxName = "idx_cs_{$col}";
            $stmt = $pdo->query("SHOW INDEX FROM `class_schedules` WHERE Key_name = '{$idxName}'");
            if (!$stmt || !$stmt->fetch()) {
                try { $pdo->exec("ALTER TABLE `class_schedules` ADD INDEX `{$idxName}` (`{$col}`)"); } catch (\Throwable $e) {}
            }
        }

        // Make schedule_code_id nullable and drop FK so new flow (direct insert) works
        $stmt = $pdo->query("SHOW CREATE TABLE `class_schedules`");
        $createTable = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $ddl = $createTable['Create Table'] ?? $createTable['Create Table'] ?? '';

        if (strpos($ddl, 'fk_cs_schedule_code') !== false) {
            try { $pdo->exec("ALTER TABLE `class_schedules` DROP FOREIGN KEY `fk_cs_schedule_code`"); } catch (\Throwable $e) {}
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `class_schedules` WHERE Field = 'schedule_code_id' AND Null = 'NO'");
        if ($stmt && $stmt->fetch()) {
            try { $pdo->exec("ALTER TABLE `class_schedules` MODIFY COLUMN `schedule_code_id` INT NULL"); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
    }
}

function ensure_schedule_change_requests_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schedule_change_requests (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                schedule_id     INT NOT NULL,
                field_changed   VARCHAR(50) NOT NULL,
                old_value       VARCHAR(255) NULL,
                new_value       VARCHAR(255) NULL,
                reason          TEXT NOT NULL,
                requested_by    INT NOT NULL,
                requested_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_by     INT NULL,
                reviewed_at     TIMESTAMP NULL,
                status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                review_remark   TEXT NULL,
                CONSTRAINT fk_scr_schedule FOREIGN KEY (schedule_id) REFERENCES class_schedules(id) ON DELETE CASCADE,
                CONSTRAINT fk_scr_requester FOREIGN KEY (requested_by) REFERENCES staff(staff_id) ON DELETE RESTRICT,
                CONSTRAINT fk_scr_reviewer FOREIGN KEY (reviewed_by) REFERENCES staff(staff_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_student_subjects_schedule_id(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'schedule_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `schedule_id` INT NULL AFTER `schedule_code_id`, ADD INDEX idx_ss_new_schedule_id (`schedule_id`)");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_enrollment_request_items_schedule_id(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_request_items` LIKE 'schedule_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `enrollment_request_items` ADD COLUMN `schedule_id` INT NULL AFTER `offering_id`, ADD INDEX idx_eri_schedule_id (`schedule_id`)");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_composite_indexes(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $indexes = [
            'student_subjects' => [
                'name' => 'idx_ss_student_term_status',
                'cols' => '(student_id, term_id, enrollment_status)',
            ],
            'enrollment_requests' => [
                'name' => 'idx_er_student_term_status',
                'cols' => '(student_id, term_id, workflow_status)',
            ],
            'student_notifications' => [
                'name' => 'idx_sn_student_dismissed',
                'cols' => '(student_id, dismissed)',
            ],
            'staff_notifications' => [
                'name' => 'idx_stn_staff_dismissed',
                'cols' => '(staff_id, dismissed)',
            ],
            'section_subject_offerings' => [
                'name' => 'idx_sso_term_section',
                'cols' => '(term_id, section_id)',
            ],
        ];

        foreach ($indexes as $table => $idx) {
            $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$idx['name']}'");
            if (!$stmt || !$stmt->fetch()) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$idx['name']}` {$idx['cols']}");
                } catch (\Throwable $e) {
                }
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_student_academic_placements_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW TABLES LIKE 'student_academic_placements'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `student_academic_placements` (
                    `id`                        INT AUTO_INCREMENT PRIMARY KEY,
                    `student_id`                INT NOT NULL,
                    `department_id`             INT NULL,
                    `program_id`                INT NOT NULL,
                    `curriculum_id`             INT NULL,
                    `term_id`                   INT NOT NULL,
                    `year_level`                INT NOT NULL DEFAULT 1,
                    `standing`                  INT NOT NULL DEFAULT 1,
                    `enrollment_status`         ENUM('regular','irregular') NOT NULL DEFAULT 'irregular',
                    `section_id`                INT NULL,
                    `placement_type`            ENUM('shifting','transfer','reentry','regular') NOT NULL DEFAULT 'regular',
                    `placement_reason`          TEXT NULL,
                    `advanced_subject_allowed`  ENUM('yes','no') NOT NULL DEFAULT 'no',
                    `approved_by`               INT NULL,
                    `override_reason`           TEXT NULL,
                    `overridden_by`             INT NULL,
                    `remarks`                   TEXT NULL,
                    `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_sap_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_sap_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`programs_id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_sap_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `program_curriculum`(`curriculum_id`) ON DELETE SET NULL,
                    INDEX `idx_sap_student` (`student_id`),
                    INDEX `idx_sap_term` (`term_id`),
                    INDEX `idx_sap_program` (`program_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_transferee_records_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW TABLES LIKE 'transferee_records'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `transferee_records` (
                    `id`                            INT AUTO_INCREMENT PRIMARY KEY,
                    `student_id`                    INT NOT NULL,
                    `previous_school`               VARCHAR(255) NOT NULL,
                    `previous_program`              VARCHAR(255) NULL,
                    `date_of_transfer`              DATE NULL,
                    `tor_received`                  ENUM('received','pending') NOT NULL DEFAULT 'pending',
                    `tor_date`                      DATE NULL,
                    `honorable_dismissal`           ENUM('received','pending') NOT NULL DEFAULT 'pending',
                    `transfer_credentials_status`   ENUM('complete','incomplete') NOT NULL DEFAULT 'incomplete',
                    `evaluation_status`             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                    `remarks`                       TEXT NULL,
                    `evaluated_by`                  INT NULL,
                    `evaluated_at`                  TIMESTAMP NULL,
                    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_tr_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                    INDEX `idx_tr_student` (`student_id`),
                    INDEX `idx_tr_status` (`evaluation_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_transferee_subjects_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW TABLES LIKE 'transferee_subjects'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `transferee_subjects` (
                    `id`                        INT AUTO_INCREMENT PRIMARY KEY,
                    `transferee_id`             INT NOT NULL,
                    `original_subject_code`     VARCHAR(50) NOT NULL,
                    `original_subject_name`     VARCHAR(255) NOT NULL,
                    `original_units`            DECIMAL(4,1) NOT NULL DEFAULT 0,
                    `grade`                     VARCHAR(10) NULL,
                    `term_taken`                VARCHAR(100) NULL,
                    `equivalent_subject_id`     INT NULL,
                    `equivalency_type`          ENUM('exact','equivalent','not_equivalent') NULL,
                    `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_ts_transferee` FOREIGN KEY (`transferee_id`) REFERENCES `transferee_records`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_ts_subject` FOREIGN KEY (`equivalent_subject_id`) REFERENCES `subjects`(`subject_id`) ON DELETE SET NULL,
                    INDEX `idx_ts_transferee` (`transferee_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_shifting_requests_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `shifting_requests` (
                `id`                    INT AUTO_INCREMENT PRIMARY KEY,
                `student_id`            INT NOT NULL,
                `current_program_id`    INT NOT NULL,
                `target_program_id`     INT NOT NULL,
                `current_year_level`    INT NOT NULL,
                `target_year_level`     INT NULL,
                `reason`                TEXT NOT NULL,
                `workflow_status`       ENUM('submitted','adviser_review','current_chair_review','target_chair_review','registrar_review','curriculum_evaluation','approved','processed','rejected','cancelled') NOT NULL DEFAULT 'submitted',
                `adviser_status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `current_chair_status`  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `target_chair_status`   ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `registrar_status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `adviser_remark`        TEXT NULL,
                `current_chair_remark`  TEXT NULL,
                `target_chair_remark`   TEXT NULL,
                `registrar_remark`      TEXT NULL,
                `adviser_processed_at`  TIMESTAMP NULL,
                `current_chair_processed_at` TIMESTAMP NULL,
                `target_chair_processed_at`  TIMESTAMP NULL,
                `registrar_processed_at`     TIMESTAMP NULL,
                `adviser_processed_by`  INT NULL,
                `current_chair_processed_by` INT NULL,
                `target_chair_processed_by`  INT NULL,
                `registrar_processed_by`     INT NULL,
                `evaluation_notes`      TEXT NULL,
                `processed_at`          TIMESTAMP NULL,
                `processed_by`          INT NULL,
                `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_sr_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sr_current_program` FOREIGN KEY (`current_program_id`) REFERENCES `programs`(`programs_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_sr_target_program` FOREIGN KEY (`target_program_id`) REFERENCES `programs`(`programs_id`) ON DELETE RESTRICT,
                INDEX `idx_sr_student` (`student_id`),
                INDEX `idx_sr_status` (`workflow_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_student_program_history_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `student_program_history` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `student_id`        INT NOT NULL,
                `program_id`        INT NOT NULL,
                `curriculum_id`     INT NULL,
                `term_started`      INT NOT NULL,
                `term_ended`        INT NULL,
                `status`            ENUM('active','shifted','transferred','dropped','graduated') NOT NULL DEFAULT 'active',
                `reason`            TEXT NULL,
                `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_sph_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sph_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`programs_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_sph_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `program_curriculum`(`curriculum_id`) ON DELETE SET NULL,
                INDEX `idx_sph_student` (`student_id`),
                INDEX `idx_sph_program` (`program_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_subject_equivalencies_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `subject_equivalencies` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `shifting_request_id` INT NULL,
                `old_subject_id`    INT NOT NULL,
                `new_subject_id`    INT NOT NULL,
                `equivalency_type`  ENUM('exact','equivalent','not_equivalent') NOT NULL DEFAULT 'equivalent',
                `credit_units`      DECIMAL(4,1) NOT NULL DEFAULT 0,
                `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `approved_by`       INT NULL,
                `approved_at`       TIMESTAMP NULL,
                `remarks`           TEXT NULL,
                `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_se_old_subject` FOREIGN KEY (`old_subject_id`) REFERENCES `subjects`(`subject_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_se_new_subject` FOREIGN KEY (`new_subject_id`) REFERENCES `subjects`(`subject_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_se_shifting` FOREIGN KEY (`shifting_request_id`) REFERENCES `shifting_requests`(`id`) ON DELETE SET NULL,
                INDEX `idx_se_shifting` (`shifting_request_id`),
                INDEX `idx_se_old` (`old_subject_id`),
                INDEX `idx_se_new` (`new_subject_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_shifting_workflow_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'shifting_request_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `students` ADD COLUMN `shifting_request_id` INT NULL AFTER `ra10931_override`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_student_subjects_grades_locked_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'grades_locked'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `grades_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `midterm_grade`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_grades_term_id_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `grades` LIKE 'term_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `grades` ADD COLUMN `term_id` INT NULL AFTER `sched_code`");

            // Backfill term_id from student_subjects -> academic_terms
            $pdo->exec("
                UPDATE grades g
                INNER JOIN student_subjects ss ON ss.id = g.student_subject_id
                SET g.term_id = ss.term_id
                WHERE g.term_id IS NULL
            ");

            // Add index and FK constraint
            try { $pdo->exec("ALTER TABLE `grades` ADD INDEX `idx_grades_term_id` (`term_id`)"); } catch (\Throwable $e) {}
            try {
                $pdo->exec("ALTER TABLE `grades` ADD CONSTRAINT `fk_grades_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE SET NULL");
            } catch (\Throwable $e) {}
        }

        // Ensure term_id is populated for existing grades via sched_code lookup as fallback
        $stmt2 = $pdo->query("SELECT COUNT(*) AS cnt FROM grades WHERE term_id IS NULL");
        if ($stmt2) {
            $row = $stmt2->fetch();
            if ($row && (int)$row['cnt'] > 0) {
                $pdo->exec("
                    UPDATE grades g
                    INNER JOIN student_subjects ss ON ss.id = g.student_subject_id
                    SET g.term_id = ss.term_id
                    WHERE g.term_id IS NULL
                ");
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_offering_term_protection(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        // Ensure student_subjects has proper FK to academic_terms
        $stmt = $pdo->query("SHOW CREATE TABLE `student_subjects`");
        if ($stmt) {
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            $ddl = $createTable['Create Table'] ?? '';
            if (strpos($ddl, 'fk_ss_term') === false) {
                try {
                    $pdo->exec("ALTER TABLE `student_subjects` ADD CONSTRAINT `fk_ss_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT");
                } catch (\Throwable $e) {}
            }
        }

        // Ensure enrollment_requests has proper FK to academic_terms
        $stmt2 = $pdo->query("SHOW CREATE TABLE `enrollment_requests`");
        if ($stmt2) {
            $createTable2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            $ddl2 = $createTable2['Create Table'] ?? '';
            if (strpos($ddl2, 'fk_er_term') === false) {
                try {
                    $pdo->exec("ALTER TABLE `enrollment_requests` ADD CONSTRAINT `fk_er_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT");
                } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {
    }
}

function ensure_subject_teaching_department_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `subjects` LIKE 'teaching_department_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `teaching_department_id` INT NULL AFTER `subject_description`");
            $pdo->exec("ALTER TABLE `subjects` ADD CONSTRAINT `fk_subject_teaching_dept` FOREIGN KEY (`teaching_department_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL");

            $pdo->exec("
                UPDATE subjects s
                INNER JOIN program_curriculum pc ON pc.subject_id = s.subject_id
                INNER JOIN programs p ON p.programs_id = pc.program_id
                SET s.teaching_department_id = p.department_id
                WHERE s.teaching_department_id IS NULL
                GROUP BY s.subject_id
            ");
        }

        $stmt2 = $pdo->query("SHOW COLUMNS FROM `class_schedules` LIKE 'schedule_type'");
        if (!$stmt2 || !$stmt2->fetch()) {
            $pdo->exec("ALTER TABLE `class_schedules` ADD COLUMN `schedule_type` ENUM('LEC','LAB','ASYNC') NOT NULL DEFAULT 'LEC' AFTER `schedule_code`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_department_chair_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `departments` LIKE 'chair_id'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `departments` ADD COLUMN `chair_id` INT NULL AFTER `department_name`");
            $pdo->exec("ALTER TABLE `departments` ADD CONSTRAINT `fk_dept_chair` FOREIGN KEY (`chair_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL");

            $pdo->exec("
                UPDATE departments d
                INNER JOIN staff s ON s.dept_id = d.dept_id
                INNER JOIN user_roles ur ON ur.user_id = s.users_id
                INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = 'department_chair'
                SET d.chair_id = s.staff_id
                WHERE d.chair_id IS NULL
            ");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_fee_items_calculation_type_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `fee_items` LIKE 'calculation_type'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `fee_items` ADD COLUMN `calculation_type` ENUM('per_unit','per_lab_unit','fixed','per_subject','per_student') NOT NULL DEFAULT 'fixed' AFTER `amount`");
        }

        $stmt2 = $pdo->query("SHOW COLUMNS FROM `fee_items` LIKE 'term_id'");
        if (!$stmt2 || !$stmt2->fetch()) {
            $pdo->exec("ALTER TABLE `fee_items` ADD COLUMN `term_id` INT NULL AFTER `semester`");
            try {
                $pdo->exec("ALTER TABLE `fee_items` ADD CONSTRAINT `fk_fee_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE SET NULL");
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
    }
}

function ensure_enrollment_request_fees_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `enrollment_request_fees` (
                `id`                    INT AUTO_INCREMENT PRIMARY KEY,
                `enrollment_request_id` INT NOT NULL,
                `fee_item_id`           INT NULL,
                `fee_name`              VARCHAR(255) NOT NULL,
                `category`              ENUM('laboratory','other','assessment') NOT NULL,
                `calculation_type`      ENUM('per_unit','per_lab_unit','fixed','per_subject','per_student') NOT NULL DEFAULT 'fixed',
                `quantity`              DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                `rate`                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `gross_amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `discount_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `net_amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `notes`                 TEXT NULL,
                `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_erf_request` FOREIGN KEY (`enrollment_request_id`) REFERENCES `enrollment_requests`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_erf_fee_item` FOREIGN KEY (`fee_item_id`) REFERENCES `fee_items`(`id`) ON DELETE SET NULL,
                INDEX `idx_erf_request` (`enrollment_request_id`),
                INDEX `idx_erf_fee_item` (`fee_item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (\Throwable $e) {
    }
}

function ensure_fee_status_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        $stmt = $pdo->query("SHOW COLUMNS FROM `enrollment_requests` LIKE 'fee_status'");
        if (!$stmt || !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `enrollment_requests` ADD COLUMN `fee_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `payment_status`");
        }
    } catch (\Throwable $e) {
    }
}

function ensure_grading_engine_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        // 1. Grade scale table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grade_scale` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_code` VARCHAR(10) NOT NULL,
                `numeric_value` DECIMAL(4,2) DEFAULT NULL,
                `is_passing` TINYINT(1) NOT NULL DEFAULT 0,
                `is_blocking` TINYINT(1) NOT NULL DEFAULT 0,
                `counts_for_gwa` TINYINT(1) NOT NULL DEFAULT 0,
                `is_withdrawal` TINYINT(1) NOT NULL DEFAULT 0,
                `display_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_grade_code` (`grade_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 2. Grading periods table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grading_periods` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `period_code` VARCHAR(20) NOT NULL,
                `period_name` VARCHAR(50) NOT NULL,
                `display_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_period_code` (`period_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 3. Redesigned grades table (rename old one first if it exists)
        $stmt = $pdo->query("SHOW TABLES LIKE 'grades'");
        $hasGrades = $stmt && $stmt->fetch();
        $stmt2 = $pdo->query("SHOW TABLES LIKE 'grades_old'");
        $hasGradesOld = $stmt2 && $stmt2->fetch();

        if ($hasGrades && !$hasGradesOld) {
            $pdo->exec("RENAME TABLE `grades` TO `grades_old`");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grades` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `student_subject_id` INT NOT NULL,
                `sched_code` VARCHAR(50) NOT NULL DEFAULT '',
                `grading_period_id` INT NOT NULL,
                `grade_value` VARCHAR(10) DEFAULT NULL,
                `grade_status` ENUM('draft','submitted','locked','correction_pending','corrected') NOT NULL DEFAULT 'draft',
                `entered_by` INT DEFAULT NULL,
                `entered_at` TIMESTAMP NULL,
                `submitted_by` INT DEFAULT NULL,
                `submitted_at` TIMESTAMP NULL,
                `locked_by` INT DEFAULT NULL,
                `locked_at` TIMESTAMP NULL,
                `updated_by` INT DEFAULT NULL,
                `updated_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_student_subject` (`student_subject_id`),
                KEY `idx_sched_code` (`sched_code`),
                KEY `idx_grading_period` (`grading_period_id`),
                KEY `idx_status` (`grade_status`),
                CONSTRAINT `fk_grade_student_subject` FOREIGN KEY (`student_subject_id`) REFERENCES `student_subjects`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_grade_period` FOREIGN KEY (`grading_period_id`) REFERENCES `grading_periods`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 4. Grade audit log
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grade_audit_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_id` INT NOT NULL,
                `action` VARCHAR(50) NOT NULL,
                `old_value` VARCHAR(10) DEFAULT NULL,
                `new_value` VARCHAR(10) DEFAULT NULL,
                `performed_by` INT NOT NULL,
                `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reason` TEXT DEFAULT NULL,
                KEY `idx_grade_id` (`grade_id`),
                CONSTRAINT `fk_audit_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 5. Academic honor rules
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `academic_honor_rules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `honor_name` VARCHAR(100) NOT NULL,
                `minimum_gwa` DECIMAL(4,2) DEFAULT NULL,
                `maximum_gwa` DECIMAL(4,2) DEFAULT NULL,
                `minimum_passing_grade` DECIMAL(4,2) DEFAULT NULL,
                `allow_failed_grade` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_inc` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_drp` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_withdrawal` TINYINT(1) NOT NULL DEFAULT 0,
                `required_units` DECIMAL(6,1) DEFAULT NULL,
                `display_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 6. Student honor status
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `student_honor_status` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `student_id` INT NOT NULL,
                `term_id` INT NOT NULL,
                `honor_rule_id` INT NOT NULL,
                `is_eligible` TINYINT(1) NOT NULL DEFAULT 0,
                `is_official` TINYINT(1) NOT NULL DEFAULT 0,
                `evaluated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `evaluated_by` INT DEFAULT NULL,
                KEY `idx_student_term` (`student_id`, `term_id`),
                CONSTRAINT `fk_honor_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`),
                CONSTRAINT `fk_honor_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`),
                CONSTRAINT `fk_honor_rule` FOREIGN KEY (`honor_rule_id`) REFERENCES `academic_honor_rules`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // 7. Add midterm_grade column to student_subjects
        $stmt3 = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'midterm_grade'");
        if (!$stmt3 || !$stmt3->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `midterm_grade` VARCHAR(10) DEFAULT NULL AFTER `final_grade`");
        }

        $stmt4 = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'grade_submitted_at'");
        if (!$stmt4 || !$stmt4->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `grade_submitted_at` TIMESTAMP NULL AFTER `midterm_grade`");
        }

        $stmt5 = $pdo->query("SHOW COLUMNS FROM `student_subjects` LIKE 'grade_submitted_by'");
        if (!$stmt5 || !$stmt5->fetch()) {
            $pdo->exec("ALTER TABLE `student_subjects` ADD COLUMN `grade_submitted_by` INT NULL AFTER `grade_submitted_at`");
        }

        // 8. Seed grade_scale if empty
        $countRow = $pdo->query("SELECT COUNT(*) AS cnt FROM `grade_scale`")->fetch();
        if ((int)($countRow['cnt'] ?? 0) === 0) {
            $grades = [
                ['1.00', 1.00, 1, 0, 1, 0, 1],
                ['1.25', 1.25, 1, 0, 1, 0, 2],
                ['1.50', 1.50, 1, 0, 1, 0, 3],
                ['1.75', 1.75, 1, 0, 1, 0, 4],
                ['2.00', 2.00, 1, 0, 1, 0, 5],
                ['2.25', 2.25, 1, 0, 1, 0, 6],
                ['2.50', 2.50, 1, 0, 1, 0, 7],
                ['2.75', 2.75, 1, 0, 1, 0, 8],
                ['3.00', 3.00, 1, 0, 1, 0, 9],
                ['3.25', 3.25, 0, 1, 1, 0, 10],
                ['3.50', 3.50, 0, 1, 1, 0, 11],
                ['3.75', 3.75, 0, 1, 1, 0, 12],
                ['4.00', 4.00, 0, 1, 1, 0, 13],
                ['4.25', 4.25, 0, 1, 1, 0, 14],
                ['4.50', 4.50, 0, 1, 1, 0, 15],
                ['4.75', 4.75, 0, 1, 1, 0, 16],
                ['5.00', 5.00, 0, 1, 1, 0, 17],
                ['INC', null, 0, 1, 0, 0, 18],
                ['DRP', null, 0, 1, 0, 0, 19],
                ['W',   null, 0, 1, 0, 1, 20],
                ['P',   null, 1, 0, 0, 0, 21],
                ['S',   null, 1, 0, 0, 0, 22],
                ['F',   null, 0, 1, 1, 0, 23],
            ];
            $insert = $pdo->prepare(
                "INSERT INTO `grade_scale` (`grade_code`, `numeric_value`, `is_passing`, `is_blocking`, `counts_for_gwa`, `is_withdrawal`, `display_order`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($grades as $g) {
                $insert->execute($g);
            }
        }

        // 9. Seed grading_periods if empty
        $countRow2 = $pdo->query("SELECT COUNT(*) AS cnt FROM `grading_periods`")->fetch();
        if ((int)($countRow2['cnt'] ?? 0) === 0) {
            $pdo->exec("INSERT INTO `grading_periods` (`period_code`, `period_name`, `display_order`) VALUES ('midterm', 'Midterm', 1), ('final', 'Final', 2)");
        }

        // 10. Seed academic_honor_rules if empty
        $countRow3 = $pdo->query("SELECT COUNT(*) AS cnt FROM `academic_honor_rules`")->fetch();
        if ((int)($countRow3['cnt'] ?? 0) === 0) {
            $honors = [
                ['Summa Cum Laude',   1.00, 1.20, 1.50, 0, 0, 0, 0, null, 1],
                ['Magna Cum Laude',   1.21, 1.45, 1.75, 0, 0, 0, 0, null, 2],
                ['Cum Laude',         1.46, 1.75, 2.00, 0, 0, 0, 0, null, 3],
            ];
            $insert2 = $pdo->prepare(
                "INSERT INTO `academic_honor_rules` (`honor_name`, `minimum_gwa`, `maximum_gwa`, `minimum_passing_grade`, `allow_failed_grade`, `allow_inc`, `allow_drp`, `allow_withdrawal`, `required_units`, `display_order`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($honors as $h) {
                $insert2->execute($h);
            }
        }

        // 11. Migrate old grades data to new grades table if grades_old exists and new grades is empty
        $hasOldGrades = $pdo->query("SHOW TABLES LIKE 'grades_old'")->fetch();
        if ($hasOldGrades) {
            $newCount = $pdo->query("SELECT COUNT(*) AS cnt FROM `grades`")->fetch();
            if ((int)($newCount['cnt'] ?? 0) === 0) {
                // Get the default final grading period ID
                $finalPeriod = $pdo->query("SELECT id FROM `grading_periods` WHERE period_code = 'final' LIMIT 1")->fetch();
                $finalPeriodId = (int)($finalPeriod['id'] ?? 2);

                try {
                    $pdo->exec("
                        INSERT IGNORE INTO `grades` (`student_subject_id`, `sched_code`, `grading_period_id`, `grade_value`, `grade_status`, `entered_by`, `entered_at`, `submitted_by`, `submitted_at`, `created_at`)
                        SELECT ss.id, COALESCE(o.sched_code, ''), {$finalPeriodId}, g.grade, 'submitted', g.instructor_id, g.created_at, g.instructor_id, g.created_at, g.created_at
                        FROM `grades_old` g
                        INNER JOIN `student_subjects` ss ON ss.student_id = g.student_id AND ss.offering_id = g.offering_id
                        LEFT JOIN `section_subject_offerings` o ON o.id = g.offering_id
                        WHERE g.grade IS NOT NULL AND g.grade != ''
                    ");
                } catch (\Throwable $e) {
                    // Ignore migration errors
                }
            }
        }

    } catch (\Throwable $e) {
    }
}