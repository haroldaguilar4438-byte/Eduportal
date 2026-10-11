-- =============================================================================
-- EduPortal : ECCD Checklist document type (EXTEND only)
-- File : database/patch_enrollment_doc_eccd.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Adds 'eccd_checklist' (Early Childhood Care and Development checklist /
-- daycare or preschool completion) to enrollment_documents.doc_type.
-- Required for Kindergarten applicants per DepEd enrollment requirements.
-- student_documents.doc_type is VARCHAR — no change needed there.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_enum_eccd`$$
CREATE PROCEDURE `__edu_enum_eccd`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_documents'
      AND COLUMN_NAME = 'doc_type' AND COLUMN_TYPE LIKE '%eccd_checklist%'
  ) THEN
    ALTER TABLE `enrollment_documents`
      MODIFY COLUMN `doc_type`
      ENUM('form137','form138_report_card','birth_certificate','good_moral','id_photo','valid_id','previous_school_record','eccd_checklist','others')
      NOT NULL;
  END IF;
END$$
DELIMITER ;

CALL `__edu_enum_eccd`();

DROP PROCEDURE IF EXISTS `__edu_enum_eccd`;

SET FOREIGN_KEY_CHECKS=1;
