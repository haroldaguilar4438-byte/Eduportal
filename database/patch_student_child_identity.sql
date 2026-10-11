-- =============================================================================
-- EduPortal : Child identity on student records (EXTEND only)
-- File : database/patch_student_child_identity.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Adds nullable name/birthplace columns to students so parent-filed children
-- (which have NO portal login, user_id IS NULL) carry their own identity.
-- Existing rows are untouched (NULL = fall back to linked users row).
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_childid`$$
CREATE PROCEDURE `__edu_add_col_childid`(
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

CALL `__edu_add_col_childid`('students','first_name','VARCHAR(100) DEFAULT NULL AFTER `user_id`');
CALL `__edu_add_col_childid`('students','middle_name','VARCHAR(100) DEFAULT NULL AFTER `first_name`');
CALL `__edu_add_col_childid`('students','last_name','VARCHAR(100) DEFAULT NULL AFTER `middle_name`');
CALL `__edu_add_col_childid`('students','suffix','VARCHAR(20) DEFAULT NULL AFTER `last_name`');
CALL `__edu_add_col_childid`('students','birthplace','VARCHAR(150) DEFAULT NULL AFTER `date_of_birth`');

DROP PROCEDURE IF EXISTS `__edu_add_col_childid`;

SET FOREIGN_KEY_CHECKS=1;
