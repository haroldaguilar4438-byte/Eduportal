-- =============================================================================
-- EduPortal : Permanent student identity + reusable documents + enrollment form
-- File : database/patch_enrollment_form_docs.sql
-- Run : mysql -u root eduportal_db < database/patch_enrollment_form_docs.sql
--       or Hostinger phpMyAdmin > Import (re-runnable, no data loss)
-- Purpose (EXTEND only, never replace working workflows):
--   1. student_documents  : permanent per-student reusable docs with versions
--   2. student_no_changes : audit log for authorized student-no corrections
--   3. enrollment_applications.clearance_id (if missing from older installs)
--   4. enrollment_documents review columns (accounting verify / resubmit)
--   5. system_settings seeds : scholarship rules, form notes, misc-fee note
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------------------------- 0. helpers
DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col`$$
CREATE PROCEDURE `__edu_add_col`(
  IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @__sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
DELIMITER ;

-- ------------------------------------------------- 1. permanent documents
CREATE TABLE IF NOT EXISTS `student_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> students.id (permanent owner)',
  `doc_type` VARCHAR(40) NOT NULL COMMENT 'birth_certificate|valid_id|form137|form138_report_card|good_moral|id_photo|previous_school_record|others',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'increments on resubmission, old rows kept',
  `original_filename` VARCHAR(191) NOT NULL,
  `stored_path` VARCHAR(255) NOT NULL COMMENT 'relative under /uploads/documents',
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size_bytes` INT UNSIGNED NOT NULL,
  `status` ENUM('submitted','verified','needs_resubmission','rejected') NOT NULL DEFAULT 'submitted',
  `review_reason` VARCHAR(500) DEFAULT NULL COMMENT 'accounting reason when needs_resubmission/rejected',
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (accounting/admin)',
  `reviewed_at` DATETIME DEFAULT NULL,
  `application_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'originating enrollment_applications.id (nullable)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_doc_version` (`student_id`,`doc_type`,`version`),
  KEY `idx_sdocs_student` (`student_id`),
  KEY `idx_sdocs_type_status` (`doc_type`,`status`),
  CONSTRAINT `fk_sdocs_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sdocs_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- 2. student-no audit
CREATE TABLE IF NOT EXISTS `student_no_changes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `old_no` VARCHAR(20) DEFAULT NULL,
  `new_no` VARCHAR(20) NOT NULL,
  `changed_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (admin/registrar)',
  `reason` VARCHAR(500) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snc_student` (`student_id`),
  CONSTRAINT `fk_snc_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_snc_actor` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------- 3. clearance link (older installs may lack)
CALL `__edu_add_col`('enrollment_applications','clearance_id','BIGINT UNSIGNED DEFAULT NULL AFTER `preferred_campus_id`');

-- --------------------- 4. per-application doc review (accounting workflow)
CALL `__edu_add_col`('enrollment_documents','status','ENUM(\'submitted\',\'verified\',\'needs_resubmission\',\'rejected\') NOT NULL DEFAULT \'submitted\' AFTER `is_verified`');
CALL `__edu_add_col`('enrollment_documents','review_reason','VARCHAR(500) DEFAULT NULL AFTER `status`');
CALL `__edu_add_col`('enrollment_documents','reviewed_at','DATETIME DEFAULT NULL AFTER `review_reason`');
CALL `__edu_add_col`('enrollment_documents','student_document_id','BIGINT UNSIGNED DEFAULT NULL AFTER `reviewed_at`');

-- Backfill new status from legacy is_verified flag (verified wins).
UPDATE `enrollment_documents` SET `status`='verified', `verified_at`=COALESCE(`verified_at`, NOW())
  WHERE `is_verified`=1 AND `status`='submitted';

-- Wider doc vocabulary (valid ID, previous school record) for reuse workflow.
ALTER TABLE `enrollment_documents`
  MODIFY COLUMN `doc_type` ENUM('form137','form138_report_card','birth_certificate','good_moral','id_photo','valid_id','previous_school_record','others') NOT NULL;

-- --------------------------------------------- 5. configurable form content
INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('school_contact', 'datamex82@gmail.com', 'general'),
('enrollment_form_scholarship_rules', '1. The student shall enjoy the privilege of a Scholarship Grant of Free Tuition Fees.\n2. The student shall share the cost of school operating expenses to cover building rental, salaries, faculty development program, maintenance & electricity cost and other and referred to as Participation fee, and shall be paid five (5) equal monthly payments.', 'enrollment'),
('enrollment_form_notes', '1. All payments should be paid to the authorized Registrar/Cashier who will issue an Official Receipt.\n2. Delayed payments are subject to penalty in the amount of Two Hundred Pesos (200.00).\n3. Graduating students shall pay a Terminal fee/Graduation Fee in the amount of Four Thousand Five Hundred Pesos (4,500) and shall form part of the participation Fee that will include graduation expenses.\n4. All payments are NOT REFUNDABLE.\n5. The student shall pay the Miscellaneous/Other Fees during enrollment.\n6. School Events/Activities not included in the Miscellaneous Fee.', 'enrollment'),
('enrollment_form_misc_note', 'Miscellaneous/Other Fees during enrollment (per Accounting assessment). Amounts below come from the Accounting module — never hard-coded.', 'enrollment');

-- Minimum joined subjects (current term) before a Prelim exam permit can be
-- requested. Admin-overridable: UPDATE system_settings SET setting_value = '6'
-- WHERE setting_key = 'permit_min_subjects';
INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('permit_min_subjects', '8', 'enrollment');

DROP PROCEDURE IF EXISTS `__edu_add_col`;

SET FOREIGN_KEY_CHECKS=1;
