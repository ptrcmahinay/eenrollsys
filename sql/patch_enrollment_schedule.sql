-- Enrollment Schedule Patch
-- Adds per-year-level enrollment windows within a term's open period.
-- If no schedule rows exist for a term, the old behavior (term-wide open) applies.

CREATE TABLE IF NOT EXISTS enrollment_schedules (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  term_id       INT NOT NULL,
  year_level    INT NOT NULL COMMENT '1=1st year, 2=2nd year, etc.',
  open_date     DATE NOT NULL   COMMENT 'Date enrollment opens for this year level',
  close_date    DATE NOT NULL   COMMENT 'Date enrollment closes for this year level',
  open_time     TIME NOT NULL   COMMENT 'Daily open time (e.g. 07:00:00)',
  close_time    TIME NOT NULL   COMMENT 'Daily close time (e.g. 19:00:00)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_schedule (term_id, year_level),
  CONSTRAINT fk_schedule_term FOREIGN KEY (term_id)
    REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
