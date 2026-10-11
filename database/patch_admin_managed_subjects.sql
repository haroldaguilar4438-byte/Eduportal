-- =============================================================================
-- EduPortal : Admin-managed Subjects / Class Offerings (replaces teacher self-create)
-- File     : database/patch_admin_managed_subjects.sql
-- Run once : phpMyAdmin -> Import, or: mysql eduportal_db < patch_admin_managed_subjects.sql
-- Idempotent: safe to run twice (uses INFORMATION_SCHEMA checks via procedure)
-- -----------------------------------------------------------------------------
-- ADMIN creates official subject_classrooms row + assigns registered teacher.
-- Teachers get assigned-only access. Students join via unique student invite code.
-- =============================================================================

SET @OLD_FK = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DELIMITER $$

CREATE PROCEDURE `__edu_add_col`(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

-- Official subject metadata (admin-controlled)
CALL `__edu_add_col`('subject_classrooms', 'semester', "`semester` ENUM('1st','2nd','Summer') DEFAULT NULL COMMENT 'semester/term' AFTER `section_name`");
CALL `__edu_add_col`('subject_classrooms', 'schedule', "`schedule` VARCHAR(255) DEFAULT NULL COMMENT 'e.g., MWF 08:00-09:30' AFTER `semester`");
CALL `__edu_add_col`('subject_classrooms', 'room', "`room` VARCHAR(50) DEFAULT NULL COMMENT 'room/location' AFTER `schedule`");
CALL `__edu_add_col`('subject_classrooms', 'status', "`status` ENUM('open','closed','archived') NOT NULL DEFAULT 'open' COMMENT 'admin-controlled lifecycle' AFTER `is_active`");
CALL `__edu_add_col`('subject_classrooms', 'created_by', "`created_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id (admin creator)' AFTER `status`");

DROP PROCEDURE IF EXISTS `__edu_add_col`;

-- Helpful indexes (ignore "duplicate key" errors on re-run)
CREATE INDEX `idx_classroom_status` ON `subject_classrooms` (`status`);
CREATE INDEX `idx_classroom_code` ON `subject_classrooms` (`subject_code`);

SET FOREIGN_KEY_CHECKS = @OLD_FK;
