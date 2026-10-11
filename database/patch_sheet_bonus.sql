-- =============================================================================
-- EduPortal : Per-student bonus on grade sheets (teacher-recorded adjustment)
-- Datamex College of Saint Adeline
-- File : database/patch_sheet_bonus.sql
-- Run once: mysql -u root eduportal_db < database/patch_sheet_bonus.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: guarded (procedure + INFORMATION_SCHEMA), safe to re-import.
-- Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : Teachers can enter an optional per-student BONUS (extra %
--       points, e.g. face-to-face bonus) directly on the grade sheet.
--       Grading rule (backend, GradeEngine::finalFor):
--       - final weighted % = min(100, engine weighted % + bonus),
--       - final point grade = grade_conversions lookup on the final %,
--       - NULL/empty bonus = no adjustment (pure engine result),
--       - bonus never rescues INC or missing data (no computable base).
--       - `computed_grade` keeps the pure engine point (audit trail);
--         `grade` holds the final submitted value (manual or auto+bonus).
--       - Code is fail-open: works before AND after this import.
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_sheetbonus`$$
CREATE PROCEDURE `__edu_add_col_sheetbonus`(
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
CALL `__edu_add_col_sheetbonus`('grade_sheet_rows','bonus_pct','DECIMAL(5,2) DEFAULT NULL COMMENT \'Teacher bonus extra % points (NULL = none)\' AFTER `computed_grade`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col_sheetbonus`$$
DELIMITER ;
