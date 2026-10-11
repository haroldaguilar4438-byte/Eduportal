-- =============================================================================
-- EduPortal : factory-reset backup trigger (EXTEND only)
-- File : database/patch_account_backups_factory.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Allows account_backups.action = 'factory' (reset-to-fresh-account).
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_enum_factory`$$
CREATE PROCEDURE `__edu_enum_factory`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_backups'
      AND COLUMN_NAME = 'action' AND COLUMN_TYPE LIKE '%factory%'
  ) THEN
    ALTER TABLE `account_backups`
      MODIFY COLUMN `action` ENUM('reset','remove','factory') NOT NULL;
  END IF;
END$$
DELIMITER ;

CALL `__edu_enum_factory`();

DROP PROCEDURE IF EXISTS `__edu_enum_factory`;

SET FOREIGN_KEY_CHECKS=1;
