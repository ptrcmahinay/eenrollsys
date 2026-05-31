-- Adds soft-delete status columns to tables that don't already have one.
-- Safe to run multiple times.

ALTER TABLE staff
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER dept_id;

ALTER TABLE sections
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER max_slots;

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER subject_description;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER password;

ALTER TABLE program_curriculum
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE academic_terms
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE academic_years
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE section_subject_offerings
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';
