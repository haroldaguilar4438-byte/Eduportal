-- =============================================================================
-- EduPortal : Semester-to-semester re-enrollment cycle
-- Datamex College of Saint Adeline
-- File : database/patch_enrollment_cycle.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable: procedure guards
-- make re-imports a no-op). Works on MySQL and MariaDB.
-- Purpose : complete the clearance model for the enrollment cycle without
--       touching working flows:
--       - registrar academic review on clearances (teachers + accounting legs
--         already exist and keep working exactly as before)
--       - teacher return remarks (rejection reason, student-visible)
--       - explicit registrar/admin eligibility override (logged, reasoned)
--       Enrollment cycles reuse enrollment_applications rows (one per student
--       per AY/semester); students are NEVER duplicated.
-- =============================================================================

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
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
CREATE PROCEDURE `__edu_add_fk`(
  IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_clause TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name
  ) THEN
    SET @__sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` ', p_clause);
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
CALL `__edu_add_col`('clearances','registrar_status','ENUM(\'pending\',\'approved\',\'returned\') NOT NULL DEFAULT \'pending\' AFTER `financial_remarks`')$$
CALL `__edu_add_col`('clearances','registrar_reviewed_by','BIGINT UNSIGNED DEFAULT NULL AFTER `registrar_status`')$$
CALL `__edu_add_col`('clearances','registrar_reviewed_at','DATETIME DEFAULT NULL AFTER `registrar_reviewed_by`')$$
CALL `__edu_add_col`('clearances','registrar_remarks','VARCHAR(500) DEFAULT NULL AFTER `registrar_reviewed_at`')$$
CALL `__edu_add_col`('clearances','teacher_remarks','VARCHAR(500) DEFAULT NULL AFTER `registrar_remarks`')$$
CALL `__edu_add_col`('clearances','eligibility_override','TINYINT(1) NOT NULL DEFAULT 0 AFTER `teacher_remarks`')$$
CALL `__edu_add_col`('clearances','override_reason','VARCHAR(500) DEFAULT NULL AFTER `eligibility_override`')$$
CALL `__edu_add_col`('clearances','override_by','BIGINT UNSIGNED DEFAULT NULL AFTER `override_reason`')$$
CALL `__edu_add_col`('clearances','override_at','DATETIME DEFAULT NULL AFTER `override_by`')$$
CALL `__edu_add_fk`('clearances','fk_clearance_registrar','FOREIGN KEY (`registrar_reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
CALL `__edu_add_fk`('clearances','fk_clearance_override','FOREIGN KEY (`override_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
DROP PROCEDURE IF EXISTS `__edu_add_col`$$
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
DELIMITER ;
