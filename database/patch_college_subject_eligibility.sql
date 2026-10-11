-- =============================================================================
-- EduPortal : College subject-joining eligibility (enrollment-aware)
-- File : database/patch_college_subject_eligibility.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable: guards make
-- re-imports a no-op). Works on MySQL and MariaDB.
-- Purpose : give every official subject_classrooms row a DIRECT academic
--   target (canonical subject + college year level + program) so eligibility
--   never depends on parsing section names like "BSIT-1A".
--   - subject_classrooms.subject_id      -> subjects.id (canonical curriculum)
--   - subject_classrooms.grade_level_id  -> grade_levels.id (C1-C4 year level)
--   - subject_classrooms.program_id      -> programs.id (NULL = open to all)
--   Backfills grade_level_id/subject_id from subjects.code = subject_code
--   where the admin has not set them explicitly. History, memberships,
--   enrollments, grades are untouched.
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
DROP PROCEDURE IF EXISTS `__edu_add_idx`$$
CREATE PROCEDURE `__edu_add_idx`(
  IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_cols TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_name
  ) THEN
    SET @__sql = CONCAT('CREATE INDEX `', p_name, '` ON `', p_table, '` (', p_cols, ')');
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
CALL `__edu_add_col`('subject_classrooms','subject_id','INT UNSIGNED DEFAULT NULL COMMENT \'FK -> subjects.id (canonical curriculum record)\' AFTER `subject_title`')$$
CALL `__edu_add_col`('subject_classrooms','grade_level_id','SMALLINT UNSIGNED DEFAULT NULL COMMENT \'FK -> grade_levels.id (C1-C4 year level target)\' AFTER `subject_id`')$$
CALL `__edu_add_col`('subject_classrooms','program_id','INT UNSIGNED DEFAULT NULL COMMENT \'FK -> programs.id (NULL = open to all programs)\' AFTER `grade_level_id`')$$
CALL `__edu_add_fk`('subject_classrooms','fk_sc_subject','FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
CALL `__edu_add_fk`('subject_classrooms','fk_sc_grade_level','FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
CALL `__edu_add_fk`('subject_classrooms','fk_sc_program','FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
CALL `__edu_add_idx`('subject_classrooms','idx_sc_eligibility','`academic_year_id`, `semester`, `grade_level_id`, `program_id`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col`$$
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
DROP PROCEDURE IF EXISTS `__edu_add_idx`$$
DELIMITER ;

-- Backfill: link offerings to canonical subjects + year levels by subject code.
-- Only fills NULL cells; never overwrites admin-set values.
UPDATE `subject_classrooms` sc
  JOIN `subjects` s ON s.`code` = sc.`subject_code`
   SET sc.`subject_id` = s.`id`
 WHERE sc.`subject_id` IS NULL;

UPDATE `subject_classrooms` sc
  JOIN `subjects` s ON s.`id` = sc.`subject_id`
   SET sc.`grade_level_id` = s.`grade_level_id`
 WHERE sc.`grade_level_id` IS NULL AND s.`grade_level_id` IS NOT NULL;

-- Second pass for rows whose subject_id is still NULL (code match directly).
UPDATE `subject_classrooms` sc
  JOIN `subjects` s ON s.`code` = sc.`subject_code`
   SET sc.`grade_level_id` = s.`grade_level_id`
 WHERE sc.`grade_level_id` IS NULL AND s.`grade_level_id` IS NOT NULL;
