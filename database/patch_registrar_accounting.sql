-- =============================================================================
-- EduPortal : Registrar + Accounting Roles Extension (SAFE, idempotent)
-- Datamex College of Saint Adeline
-- File : database/patch_registrar_accounting.sql
-- Run : mysql -u root eduportal_db < database/patch_registrar_accounting.sql
--       or Hostinger phpMyAdmin > Import (re-runnable, no data loss)
-- Extends existing schema: roles, grades review, financial clearance,
-- academic documents, audit/history. No DROP of existing tables.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. New RBAC roles (admin=1, teacher=2, student=3, parent=4 keep IDs)
INSERT IGNORE INTO `roles` (`id`,`name`,`description`) VALUES
(5,'registrar','Registrar - academic records, grades verification, permits, documents'),
(6,'accounting','Accounting - enrollment finance, payments, balances, financial clearance');

-- 2. Grade verification workflow (extends teacher grading, does not replace it)
-- classroom_task_submissions.status stays teacher-controlled (submitted/late/graded/returned).
-- review_status is registrar-controlled (draft/submitted/reviewed/approved/returned).
ALTER TABLE `classroom_task_submissions`
  ADD COLUMN IF NOT EXISTS `review_status` ENUM('draft','submitted','reviewed','approved','returned') NOT NULL DEFAULT 'submitted' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` BIGINT UNSIGNED DEFAULT NULL AFTER `review_status`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN IF NOT EXISTS `review_remarks` VARCHAR(500) DEFAULT NULL AFTER `reviewed_at`;
CREATE INDEX IF NOT EXISTS `idx_cts_review` ON `classroom_task_submissions` (`review_status`);

-- Backfill: teacher-graded work enters registrar queue as submitted - ungraded stays draft.
UPDATE `classroom_task_submissions` SET `review_status`='submitted' WHERE `status` IN ('submitted','late','graded') AND `review_status`='submitted';
UPDATE `classroom_task_submissions` SET `review_status`='draft' WHERE `status` NOT IN ('submitted','late','graded','returned');

-- Grade review history (registrar approvals/returns + corrections audit)
CREATE TABLE IF NOT EXISTS `grade_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> classroom_task_submissions.id',
  `from_status` VARCHAR(20) DEFAULT NULL,
  `to_status` VARCHAR(20) NOT NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'submitted|reviewed|approved|returned|correction',
  `remarks` VARCHAR(500) DEFAULT NULL,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gr_submission` (`submission_id`),
  KEY `idx_gr_actor` (`actor_user_id`),
  CONSTRAINT `fk_gr_submission` FOREIGN KEY (`submission_id`) REFERENCES `classroom_task_submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gr_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Financial clearance (extends academic teacher-sign clearance, does not replace it)
-- clearances.status stays teacher-driven (pending/signed/issued/used).
-- financial_status is accounting-driven (pending/for_review/cleared/not_cleared/on_hold).
ALTER TABLE `clearances`
  ADD COLUMN IF NOT EXISTS `financial_status` ENUM('pending','for_review','cleared','not_cleared','on_hold') NOT NULL DEFAULT 'pending' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `financial_reviewed_by` BIGINT UNSIGNED DEFAULT NULL AFTER `financial_status`,
  ADD COLUMN IF NOT EXISTS `financial_reviewed_at` DATETIME DEFAULT NULL AFTER `financial_reviewed_by`,
  ADD COLUMN IF NOT EXISTS `financial_remarks` VARCHAR(500) DEFAULT NULL AFTER `financial_reviewed_at`;
CREATE INDEX IF NOT EXISTS `idx_clearance_financial` ON `clearances` (`financial_status`);

-- Financial clearance history (no hard FK to clearances.id: live installs lack a PK there)
CREATE TABLE IF NOT EXISTS `clearance_status_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clearance_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> clearances.id (logical, no constraint for compat)',
  `from_status` VARCHAR(20) DEFAULT NULL,
  `to_status` VARCHAR(20) NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_csl_clearance` (`clearance_id`),
  CONSTRAINT `fk_csl_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Academic documents registry (registrar-issued: TOR, enrollment cert, grades cert, history)
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
  KEY `idx_docs_type` (`doc_type`),
  CONSTRAINT `fk_docs_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_docs_requester` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_docs_processor` FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
