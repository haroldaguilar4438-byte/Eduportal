-- ============================================================================
-- EduPortal : Admin-controlled Online Teachers Evaluation - SAFE UPGRADE
-- Datamex College of Saint Adeline
-- ----------------------------------------------------------------------------
-- Purpose : Let ADMIN decide when the online teacher evaluation is OPEN.
--           Students see NUIS-style status:
--           "Online Teachers Evaluation for AY 2026-2027 1st Term
--            (Midterm Period) is already closed." when closed, and the
--           evaluation forms only when open.
--
-- SAFETY  : CREATE TABLE IF NOT EXISTS, no DROP/ALTER of existing tables
--           except ADD COLUMN IF NOT EXISTS style guard for
--           teacher_evaluations.evaluation_period_id.
--           Re-runnable on Hostinger via phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `evaluation_periods` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'FK -> academic_years.id',
  `term`             ENUM('1st','2nd','Summer') NOT NULL DEFAULT '1st' COMMENT '1st Term / 2nd Term / Summer',
  `period`           ENUM('Prelim','Midterm','Prefinal','Finals') NOT NULL DEFAULT 'Midterm' COMMENT 'Midterm Period etc.',
  `status`           ENUM('open','closed') NOT NULL DEFAULT 'closed',
  `opens_at`         DATETIME DEFAULT NULL,
  `closes_at`        DATETIME DEFAULT NULL,
  `created_by`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'admin users.id',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eval_period` (`academic_year_id`,`term`,`period`),
  KEY `idx_eval_period_status` (`status`),
  CONSTRAINT `fk_eval_period_ay`
    FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Admin-controlled online teacher evaluation windows';

-- Track which window an evaluation belongs to (nullable = legacy rows).
-- MySQL has no ADD COLUMN IF NOT EXISTS, so use the guarded procedure pattern.
DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_eval_period_col`$$
CREATE PROCEDURE `__edu_add_eval_period_col`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'teacher_evaluations'
      AND COLUMN_NAME = 'evaluation_period_id'
  ) THEN
    ALTER TABLE `teacher_evaluations`
      ADD COLUMN `evaluation_period_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'FK -> evaluation_periods.id (NULL = legacy / always-open install)'
      AFTER `academic_year_id`;
  END IF;
END$$
CALL `__edu_add_eval_period_col`()$$
DROP PROCEDURE IF EXISTS `__edu_add_eval_period_col`$$
DELIMITER ;
