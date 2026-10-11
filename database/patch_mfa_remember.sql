-- =============================================================================
-- EduPortal : "Remember this device" MFA exemption (EXTEND only)
-- File : database/patch_mfa_remember.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Adds trusted_devices.mfa_exempt_until — until this timestamp, a TRUSTED
-- device may skip the TOTP challenge (password still required every login).
-- Exemption is opt-in, revocable, cleared on password change/reset.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_rem`$$
CREATE PROCEDURE `__edu_add_col_rem`(
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
DELIMITER ;

CALL `__edu_add_col_rem`('trusted_devices','mfa_exempt_until','DATETIME DEFAULT NULL AFTER `expires_at`');

DROP PROCEDURE IF EXISTS `__edu_add_col_rem`;

SET FOREIGN_KEY_CHECKS=1;
