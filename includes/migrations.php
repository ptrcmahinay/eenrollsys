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
