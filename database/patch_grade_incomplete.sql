-- =============================================================================
-- EduPortal : Incomplete (INC) grades — major vs minor assessment categories
-- Datamex College of Saint Adeline
-- File : database/patch_grade_incomplete.sql
-- Run once: mysql -u root eduportal_db < database/patch_grade_incomplete.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: guards make re-import safe (duplicate errors mean "applied").
-- Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : Grading policy — missing MINOR work (activities, quizzes,
--       assignments) counts as 0 and deducts from the weighted grade, while
--       missing MAJOR work (exams, projects) marks the student INCOMPLETE
--       (INC) for that period. INC flows to grade slips; the student completes
--       it (e.g. summer + payment) and the teacher grades it via the existing
--       registrar reopen -> resubmit -> finalize pipeline.
--       - assessment_categories gains is_major (1 = exams/projects by default)
--       - NO grade_sheet_rows change: INC is stored as grade NULL +
--         remarks 'INC' (grade column stays numeric-only).
--       - GradeEngine resolves major-ness from is_major when the column
--         exists, else falls back to exams/projects — safe before/after import.
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_inc`$$
CREATE PROCEDURE `__edu_add_col_inc`(
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
CALL `__edu_add_col_inc`('assessment_categories','is_major','TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1 = missing work forces INC (exams, projects)\' AFTER `is_active`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col_inc`$$
DELIMITER ;

-- Default majors (idempotent: only flips rows still at the 0 default).
UPDATE `assessment_categories` SET `is_major` = 1
 WHERE `code` IN ('exams','projects') AND `is_major` = 0;
