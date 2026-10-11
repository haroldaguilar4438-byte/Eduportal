-- =============================================================================
-- EduPortal : Per-child enrollment filing for parent accounts (EXTEND only)
-- File : database/patch_enrollment_applicant_child.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Adds enrollment_applications.applicant_child_id — the linked student a
-- parent filed the application for (NULL for self-filings and legacy rows).
-- Lets a parent hold one open application PER CHILD instead of one per
-- account, and lets duplicate/period guards tell siblings apart.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_appchild`$$
CREATE PROCEDURE `__edu_add_col_appchild`(
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

CALL `__edu_add_col_appchild`('enrollment_applications','applicant_child_id','BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`');

DROP PROCEDURE IF EXISTS `__edu_add_col_appchild`;

SET FOREIGN_KEY_CHECKS=1;
