-- =============================================================================
-- EduPortal : TOTP replay protection (EXTEND only)
-- File : database/patch_mfa_replay.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Adds user_mfa.last_used_counter — the time-step of the last accepted code.
-- A code whose step is <= this value is rejected as already used, so every
-- 6-digit code works exactly once even inside its 30s validity window.
-- The PHP code tolerates a missing column (fail-open read, best-effort
-- write), but import this so the protection is active.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_replay`$$
CREATE PROCEDURE `__edu_add_col_replay`(
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

CALL `__edu_add_col_replay`('user_mfa','last_used_counter','BIGINT NOT NULL DEFAULT 0 AFTER `locked_until`');

DROP PROCEDURE IF EXISTS `__edu_add_col_replay`;

SET FOREIGN_KEY_CHECKS=1;
