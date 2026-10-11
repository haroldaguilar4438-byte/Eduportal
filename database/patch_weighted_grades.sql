-- =============================================================================
-- EduPortal : Weighted Grade Calculation Engine
-- Datamex College of Saint Adeline
-- File : database/patch_weighted_grades.sql
-- Run once: mysql -u root eduportal_db < database/patch_weighted_grades.sql
--       or Hostinger phpMyAdmin > Import.
-- Re-runnable: every change is guarded (procedures + IF NOT EXISTS + IGNORE),
--       so re-import errors/state can be ignored — they mean "already applied".
--       Works on both MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS used).
-- Purpose : Auto-calculation of term grades from activities/exams/assessments.
--       - assessment_categories: configurable graded component types
--       - grade_weights: category weights, global default (classroom_id NULL)
--         or per-subject override by the assigned teacher
--       - grade_conversions: percentage (0-100) -> point grade (1.00-5.00),
--         registrar-managed
--       - classroom_tasks gains category, max_score, grading period
--       - grade_sheet_rows gains computed snapshot (auto vs manual source)
--       Reuses classroom_tasks, classroom_task_submissions, grade_periods,
--       subject_classrooms, grade_sheets. Nothing dropped.
-- =============================================================================

-- 1. Assessment categories (school-configurable component types)
CREATE TABLE IF NOT EXISTS `assessment_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL COMMENT 'activities, quizzes, assignments, projects, exams',
  `name` VARCHAR(80) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assess_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `assessment_categories` (`code`,`name`,`sort_order`,`is_active`) VALUES
('activities','Activities',1,1),
('quizzes','Quizzes',2,1),
('assignments','Assignments',3,1),
('projects','Projects',4,1),
('exams','Exams',5,1);

-- 2. Category weights: global default (classroom_id NULL) or per-subject override
CREATE TABLE IF NOT EXISTS `grade_weights` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classroom_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = global default for all subjects',
  `category_id` INT UNSIGNED NOT NULL COMMENT 'FK -> assessment_categories.id',
  `weight_pct` DECIMAL(5,2) NOT NULL COMMENT 'e.g. 20.00 = 20%',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weight_scope` (`classroom_id`,`category_id`),
  CONSTRAINT `fk_weight_category` FOREIGN KEY (`category_id`) REFERENCES `assessment_categories`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_weight_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global default weights (used unless a subject has its own override).
-- Guarded against duplicates on re-import (MySQL treats NULLs as distinct).
INSERT INTO `grade_weights` (`classroom_id`,`category_id`,`weight_pct`)
SELECT NULL, ac.id,
  CASE ac.code WHEN 'activities' THEN 20.00 WHEN 'quizzes' THEN 20.00 WHEN 'assignments' THEN 10.00 WHEN 'projects' THEN 20.00 WHEN 'exams' THEN 30.00 ELSE 0.00 END
  FROM `assessment_categories` ac
  LEFT JOIN `grade_weights` gw ON gw.classroom_id IS NULL AND gw.category_id = ac.id
 WHERE ac.code IN ('activities','quizzes','assignments','projects','exams')
   AND gw.id IS NULL;

-- 3. Percentage -> point-grade conversion (registrar-managed)
CREATE TABLE IF NOT EXISTS `grade_conversions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `min_pct` DECIMAL(5,2) NOT NULL,
  `max_pct` DECIMAL(5,2) NOT NULL,
  `point` DECIMAL(4,2) NOT NULL COMMENT 'Point grade, e.g. 1.25',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `grade_conversions` (`id`,`min_pct`,`max_pct`,`point`,`sort_order`) VALUES
(1,97.00,100.00,1.00,1),
(2,94.00,96.99,1.25,2),
(3,91.00,93.99,1.50,3),
(4,88.00,90.99,1.75,4),
(5,85.00,87.99,2.00,5),
(6,82.00,84.99,2.25,6),
(7,79.00,81.99,2.50,7),
(8,76.00,78.99,2.75,8),
(9,75.00,75.99,3.00,9),
(10,0.00,74.99,5.00,10);

-- 4. Tasks gain category + max score + grading period (guarded helpers).
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
CALL `__edu_add_col`('classroom_tasks','category_id','INT UNSIGNED DEFAULT NULL COMMENT \'FK -> assessment_categories.id\' AFTER `instructions`')$$
CALL `__edu_add_col`('classroom_tasks','max_score','DECIMAL(6,2) NOT NULL DEFAULT 100.00 AFTER `category_id`')$$
CALL `__edu_add_col`('classroom_tasks','period_id','INT UNSIGNED DEFAULT NULL COMMENT \'FK -> grade_periods.id\' AFTER `max_score`')$$
CALL `__edu_add_fk`('classroom_tasks','fk_task_category','FOREIGN KEY (`category_id`) REFERENCES `assessment_categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
CALL `__edu_add_fk`('classroom_tasks','fk_task_period','FOREIGN KEY (`period_id`) REFERENCES `grade_periods`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
-- 5. Sheet rows keep computed snapshot + source for audit.
CALL `__edu_add_col`('grade_sheet_rows','computed_grade','DECIMAL(4,2) DEFAULT NULL COMMENT \'Engine-computed point grade snapshot\' AFTER `grade`')$$
CALL `__edu_add_col`('grade_sheet_rows','source','ENUM(\'manual\',\'auto\') NOT NULL DEFAULT \'manual\' AFTER `computed_grade`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col`$$
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
DELIMITER ;
