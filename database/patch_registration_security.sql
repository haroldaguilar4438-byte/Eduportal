-- =============================================================================
-- EduPortal : Registration hardening (EXTEND only — no rebuild, no data loss)
-- File : database/patch_registration_security.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable). Local: SOURCE via mysql.
-- Purpose:
--   1. registration_attempts : throttle repeated sign-ups per IP / email.
--   2. users.terms_accepted_at : record Data Privacy / Terms acceptance.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

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
DELIMITER ;

-- 1. Attempt log for registration throttling (never blocks permanently).
CREATE TABLE IF NOT EXISTS `registration_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reg_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_reg_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Terms-acceptance timestamp (NULL for accounts created before this patch).
CALL `__edu_add_col`('users','terms_accepted_at','DATETIME DEFAULT NULL AFTER `is_active`');

DROP PROCEDURE IF EXISTS `__edu_add_col`;

SET FOREIGN_KEY_CHECKS=1;
