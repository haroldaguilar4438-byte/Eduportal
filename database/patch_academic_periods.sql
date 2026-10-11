-- =============================================================================
-- EduPortal : Centralized academic periods per education band
-- File : database/patch_academic_periods.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable: guards make
-- re-imports a no-op). Works on MySQL and MariaDB.
-- Purpose : different education levels use different academic structures —
--   Basic Ed (K–G10) : Quarter / Trimester-Term per Admin configuration
--   SHS (G11–G12)    : 1st / 2nd Semester (existing workflow preserved)
--   College          : 1st / 2nd / Summer (existing workflow preserved)
--   - new `academic_periods` config table (Admin-manageable period lists)
--   - new `basic_structure` setting (quarter | trimester)
--   - new `period_code` on enrollments / offerings / subjects / documents
--     (basic-ed periods; SHS/College keep legacy `semester` columns)
--   - widen `semester` on clearances + grade_sheets to VARCHAR so quarter/
--     term codes fit without reinterpretation
--   History is preserved: legacy rows are backfilled only where the band is
--   unambiguous (SHS/College semester rows); basic legacy rows stay NULL.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `academic_periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `band` enum('basic','shs','college') NOT NULL COMMENT 'basic=K-G10, shs=G11-G12, college=1st-4th Year',
  `code` varchar(20) NOT NULL COMMENT 'Q1..Q4, T1..T3, 1st, 2nd, Summer, or custom',
  `name` varchar(40) NOT NULL COMMENT 'display label, e.g. Quarter 1',
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_band_code` (`band`,`code`),
  KEY `idx_band_active` (`band`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `academic_periods` (`band`,`code`,`name`,`sort_order`,`is_active`) VALUES
('basic','Q1','Quarter 1',1,1),
('basic','Q2','Quarter 2',2,1),
('basic','Q3','Quarter 3',3,1),
('basic','Q4','Quarter 4',4,1),
('basic','T1','Term 1',11,0),
('basic','T2','Term 2',12,0),
('basic','T3','Term 3',13,0),
('shs','1st','1st Semester',1,1),
('shs','2nd','2nd Semester',2,1),
('college','1st','1st Semester',1,1),
('college','2nd','2nd Semester',2,1),
('college','Summer','Summer',3,1);

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('basic_structure','quarter','academic');

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
CALL `__edu_add_col`('enrollment_applications','period_code','VARCHAR(20) DEFAULT NULL COMMENT \'band-correct period (basic-ed quarters/terms; NULL = use semester)\' AFTER `semester`')$$
CALL `__edu_add_col`('subject_classrooms','period_code','VARCHAR(20) DEFAULT NULL COMMENT \'band-correct period (basic-ed quarters/terms; NULL = use semester)\' AFTER `semester`')$$
CALL `__edu_add_col`('subjects','period_code','VARCHAR(20) DEFAULT NULL COMMENT \'band-correct period for canonical basic-ed subjects\' AFTER `semester`')$$
CALL `__edu_add_col`('academic_documents','period_code','VARCHAR(20) DEFAULT NULL COMMENT \'band-correct period (basic-ed quarters/terms)\' AFTER `semester`')$$
DROP PROCEDURE IF EXISTS `__edu_add_col`$$
DELIMITER ;

-- Widen period carriers (values preserved; enums become free text).
ALTER TABLE `clearances`
  MODIFY COLUMN `semester` VARCHAR(20) NOT NULL DEFAULT '1st' COMMENT 'effective academic period (semester / quarter / term)';
ALTER TABLE `grade_sheets`
  MODIFY COLUMN `semester` VARCHAR(20) DEFAULT NULL COMMENT 'effective academic period (semester / quarter / term)';
ALTER TABLE `academic_documents`
  MODIFY COLUMN `semester` VARCHAR(20) DEFAULT NULL COMMENT 'effective academic period (semester / quarter / term)';

-- Backfill effective periods only where the band is unambiguous (SHS/College).
-- Basic-ed legacy rows keep period_code NULL (never reinterpreted).
UPDATE `enrollment_applications` ea
  JOIN `grade_levels` gl ON gl.id = ea.grade_level_applied_id
   SET ea.`period_code` = ea.`semester`
 WHERE ea.`period_code` IS NULL
   AND ea.`semester` IN ('1st','2nd','Summer')
   AND gl.`category` IN ('senior_high','college');

UPDATE `subject_classrooms` sc
  JOIN `grade_levels` gl ON gl.id = sc.grade_level_id
   SET sc.`period_code` = sc.`semester`
 WHERE sc.`period_code` IS NULL
   AND sc.`semester` IN ('1st','2nd','Summer')
   AND gl.`category` IN ('senior_high','college');
