-- =============================================================================
-- EduPortal : Per-assessment passing score (teacher-decided)
-- Datamex College of Saint Adeline
-- File : database/patch_task_passing_score.sql
-- Run once: mysql -u root eduportal_db < database/patch_task_passing_score.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: guarded (procedure + INFORMATION_SCHEMA), safe to re-import.
-- Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : Teachers decide the passing raw score per assessment
--       (e.g. exam total 50, passing 30). The system auto-marks each
--       student's raw score PASSED / NOT PASSED from that threshold.
--       - classroom_tasks gains passing_score (NULL = no pass/fail judgment)
--       - NULL keeps old behavior (raw + % only, no badge)
--       - Code is fail-open: works before AND after this import.
--       - This is per-assessment passing, NOT the final subject grade
--         (subject grades still come from GradeEngine weights + conversions).
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_pass`$$
CREATE PROCEDURE `__edu_add_col_pass`(
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
CALL `__edu_add_col_pass`('classroom_tasks','passing_score','DECIMAL(6,2) DEFAULT NULL COMMENT \'Teacher-set passing raw score (NULL = no pass/fail)\' AFTER `max_score`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col_pass`$$
DELIMITER ;
