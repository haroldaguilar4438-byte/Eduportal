-- =============================================================================
-- EduPortal : Enrollment document waivers (parent declarations)
-- Datamex College of Saint Adeline
-- File : database/patch_enrollment_doc_waivers.sql
-- Run once: mysql -u root eduportal_db < database/patch_enrollment_doc_waivers.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: guarded (procedure + INFORMATION_SCHEMA), safe to re-import.
-- Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : A child who never attended preschool/Kindergarten cannot produce
--       an ECCD checklist or Kindergarten report card. Parents may declare
--       this on the form instead of being hard-blocked:
--       - enrollment_applications gains doc_waivers (comma list, e.g.
--         'eccd_checklist,form138_report_card', NULL = none)
--       - Backend only honors waivers for G1-new (+ Kinder ECCD); reviewers
--         see the waiver badges in their queues and can still request the
--         papers later via the normal resubmission flow.
--       - Code is fail-open: works before AND after this import.
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_eawaiver`$$
CREATE PROCEDURE `__edu_add_col_eawaiver`(
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
CALL `__edu_add_col_eawaiver`('enrollment_applications','doc_waivers','VARCHAR(255) DEFAULT NULL COMMENT \'Parent-declared doc waivers, comma list of doc types\' AFTER `remarks`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col_eawaiver`$$
DELIMITER ;
