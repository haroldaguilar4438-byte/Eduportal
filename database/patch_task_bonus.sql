-- =============================================================================
-- EduPortal : Bonus / extra-credit assessments (teacher-recorded)
-- Datamex College of Saint Adeline
-- File : database/patch_task_bonus.sql
-- Run once: mysql -u root eduportal_db < database/patch_task_bonus.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: guarded (procedure + INFORMATION_SCHEMA), safe to re-import.
-- Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : Teachers can mark an assessment as BONUS (extra credit), e.g. a
--       face-to-face bonus recorded in the system.
--       Grading rule (backend, GradeEngine):
--       - bonus earned points count toward the category NUMERATOR only,
--         never the denominator (missing bonus work is ignored entirely:
--         no zero-deduction, no INC, no missing count),
--       - category percentage is capped at 100%,
--       - a category with ONLY bonus work and no regular work yields no
--         percentage (bonus alone cannot create a grade).
--       - Code is fail-open: works before AND after this import.
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_bonus`$$
CREATE PROCEDURE `__edu_add_col_bonus`(
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
CALL `__edu_add_col_bonus`('classroom_tasks','is_bonus','TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1 = bonus/extra credit: earned counts, no denominator, never INC\' AFTER `passing_score`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col_bonus`$$
DELIMITER ;
