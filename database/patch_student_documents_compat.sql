-- =============================================================================
-- EduPortal : Student Documents compatibility patch (SAFE, idempotent, re-runnable)
-- Fixes "We'll be right back" on GET /student/documents and fatal on
-- POST /student/documents on Hostinger installs provisioned from an older
-- schema (no academic_documents table, or missing academic_year_id/semester/
-- period_code columns, or old ENUM values).
-- Run: phpMyAdmin > select u788443931_datamex > Import > this file.
-- No DROP, no data loss. Verify with: SHOW COLUMNS FROM academic_documents;
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. Base table (only created when missing; live installs from
--    patch_registrar_accounting.sql already have it).
CREATE TABLE IF NOT EXISTS `academic_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_no` VARCHAR(30) NOT NULL COMMENT 'e.g. DOC-2026-000123',
  `student_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> students.id',
  `doc_type` ENUM('transcript','enrollment_cert','grades_cert','history','other') NOT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','approved','released','rejected') NOT NULL DEFAULT 'pending',
  `requested_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (student)',
  `processed_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `released_at` DATETIME DEFAULT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_no` (`doc_no`),
  KEY `idx_docs_student` (`student_id`),
  KEY `idx_docs_status` (`status`),
  KEY `idx_docs_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Academic context columns (missing on older live installs; the
--    controller now degrades gracefully, but the columns restore filtering).
ALTER TABLE `academic_documents`
  ADD COLUMN IF NOT EXISTS `academic_year_id` INT UNSIGNED DEFAULT NULL AFTER `student_id`,
  ADD COLUMN IF NOT EXISTS `semester` ENUM('1st','2nd','Summer') DEFAULT NULL AFTER `academic_year_id`,
  ADD COLUMN IF NOT EXISTS `period_code` VARCHAR(20) DEFAULT NULL COMMENT 'band-correct period (basic-ed quarters/terms)' AFTER `semester`;

-- 3. New document types + ready/cancelled states (idempotent on MySQL 8;
--    on re-run after success these MODIFYs simply re-apply the same definition).
ALTER TABLE `academic_documents`
  MODIFY COLUMN `doc_type` ENUM('transcript','enrollment_cert','grades_cert','history','grade_slip','registration_form','grade_equivalency','other') NOT NULL;

ALTER TABLE `academic_documents`
  MODIFY COLUMN `status` ENUM('pending','approved','released','rejected','ready','cancelled') NOT NULL DEFAULT 'pending';

-- 4. Helpful indexes for the student portal queries (IF NOT EXISTS guards).
CREATE INDEX IF NOT EXISTS `idx_docs_student_status` ON `academic_documents` (`student_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_docs_ay` ON `academic_documents` (`academic_year_id`);

SET FOREIGN_KEY_CHECKS=1;
