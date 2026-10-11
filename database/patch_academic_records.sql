-- =============================================================================
-- EduPortal : Academic Grade Sheets + Official Records + Document Requests
-- Datamex College of Saint Adeline
-- File : database/patch_academic_records.sql
-- Run once: mysql -u root eduportal_db < database/patch_academic_records.sql
--       or Hostinger phpMyAdmin > Import (IF NOT EXISTS guards make re-runs safe,
--       except the academic_documents ENUM expands + FK adds which error harmlessly
--       on re-run meaning "already applied").
-- Purpose : Teacher term-grade submission (Prelim/Midterm/Prefinal/Finals) with
--       Draft → Submitted → Under Review → Returned/Approved → Finalized workflow,
--       configurable grading periods + grade scale, units per subject, richer
--       academic document requests (grade slip, registration form, equivalency).
--       Reuses subject_classrooms, classroom_members, students, teachers, users,
--       academic_years, notifications, audit_logs. No existing tables dropped.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. Configurable grading periods (school already uses Prelim/Midterm/Prefinal/Finals)
CREATE TABLE IF NOT EXISTS `grade_periods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL COMMENT 'Prelim, Midterm, Prefinal, Finals',
  `name` VARCHAR(60) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_period_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `grade_periods` (`code`,`name`,`sort_order`,`is_active`) VALUES
('Prelim','Prelim',1,1),
('Midterm','Midterm',2,1),
('Prefinal','Pre-Finals',3,1),
('Finals','Finals',4,1);

-- 2. Grade sheets: one per subject-class × grading period (teacher-owned)
CREATE TABLE IF NOT EXISTS `grade_sheets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classroom_id` INT UNSIGNED NOT NULL COMMENT 'FK -> subject_classrooms.id',
  `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'FK -> academic_years.id',
  `semester` ENUM('1st','2nd','Summer') DEFAULT NULL,
  `period_id` INT UNSIGNED NOT NULL COMMENT 'FK -> grade_periods.id',
  `status` ENUM('draft','submitted','under_review','returned','approved','finalized')
    NOT NULL DEFAULT 'draft' COMMENT 'Teacher draft → registrar pipeline → locked record',
  `submitted_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (teacher)',
  `submitted_at` DATETIME DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_remarks` VARCHAR(500) DEFAULT NULL,
  `finalized_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `finalized_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sheet_class_period` (`classroom_id`,`period_id`),
  KEY `idx_sheet_status` (`status`),
  KEY `idx_sheet_teacher` (`submitted_by`),
  CONSTRAINT `fk_sheet_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_period` FOREIGN KEY (`period_id`) REFERENCES `grade_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_finalizer` FOREIGN KEY (`finalized_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Grade sheet rows: one per enrolled student (NULL grade = missing)
CREATE TABLE IF NOT EXISTS `grade_sheet_rows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> grade_sheets.id',
  `student_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> students.id',
  `grade` DECIMAL(4,2) DEFAULT NULL COMMENT 'Term grade, school point scale (e.g. 1.00-5.00)',
  `remarks` VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sheet_student` (`sheet_id`,`student_id`),
  KEY `idx_gsr_student` (`student_id`),
  CONSTRAINT `fk_gsr_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `grade_sheets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gsr_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Grade sheet audit history (status transitions + corrections)
CREATE TABLE IF NOT EXISTS `grade_sheet_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> grade_sheets.id',
  `from_status` VARCHAR(20) DEFAULT NULL,
  `to_status` VARCHAR(20) NOT NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'created|saved|submitted|under_review|returned|approved|finalized|resubmitted|correction',
  `remarks` VARCHAR(500) DEFAULT NULL,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gsl_sheet` (`sheet_id`),
  CONSTRAINT `fk_gsl_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `grade_sheets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gsl_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Configurable grade scale (registrar-managed; teachers/students read-only)
CREATE TABLE IF NOT EXISTS `grade_scales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `min_grade` DECIMAL(4,2) NOT NULL,
  `max_grade` DECIMAL(4,2) NOT NULL,
  `equivalent` VARCHAR(20) NOT NULL COMMENT 'Label shown on slip, e.g. Excellent',
  `remarks` VARCHAR(60) NOT NULL DEFAULT 'PASSED',
  `is_passing` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `grade_scales` (`id`,`min_grade`,`max_grade`,`equivalent`,`remarks`,`is_passing`,`sort_order`) VALUES
(1,1.00,1.25,'Excellent','PASSED',1,1),
(2,1.26,1.50,'Very Good','PASSED',1,2),
(3,1.51,1.75,'Good','PASSED',1,3),
(4,1.76,2.25,'Satisfactory','PASSED',1,4),
(5,2.26,2.75,'Fair','PASSED',1,5),
(6,2.76,3.00,'Passed','PASSED',1,6),
(7,3.01,4.00,'Conditional','CONDITIONAL',0,7),
(8,4.01,5.00,'Failed','FAILED',0,8);

-- 6. Units per subject-class (registration form + grade slip totals)
ALTER TABLE `subject_classrooms`
  ADD COLUMN IF NOT EXISTS `units` DECIMAL(3,1) NOT NULL DEFAULT 3.0 AFTER `section_name`;

-- 7. Document requests: new types + ready/cancelled states + academic context
ALTER TABLE `academic_documents`
  MODIFY COLUMN `doc_type` ENUM('transcript','enrollment_cert','grades_cert','history','grade_slip','registration_form','grade_equivalency','other') NOT NULL;

ALTER TABLE `academic_documents`
  MODIFY COLUMN `status` ENUM('pending','approved','released','rejected','ready','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE `academic_documents`
  ADD COLUMN IF NOT EXISTS `academic_year_id` INT UNSIGNED DEFAULT NULL AFTER `student_id`,
  ADD COLUMN IF NOT EXISTS `semester` ENUM('1st','2nd','Summer') DEFAULT NULL AFTER `academic_year_id`;

SET FOREIGN_KEY_CHECKS=1;
