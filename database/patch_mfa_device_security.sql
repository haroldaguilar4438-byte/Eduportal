-- =============================================================================
-- EduPortal : MFA + New-Device Verification (EXTEND only — no rebuild, no data loss)
-- File : database/patch_mfa_device_security.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable). Local: mysql SOURCE.
-- Covers:
--   1. user_mfa              : TOTP secret (encrypted) + enabled flag per user
--   2. mfa_recovery_codes    : one-time recovery codes (hash only)
--   3. trusted_devices       : hashed device tokens, revocable, expiring
--   4. pending_logins        : password-ok-but-not-complete login attempts
--                              (device check -> MFA check -> full session)
--   5. user_sessions         : active session registry for revoke-all-others
-- SECURITY:
--   - Raw tokens / secrets / recovery codes are NEVER stored in plain text
--     long-term (device + email tokens are SHA-256 hashes; recovery codes
--     are password_hash()ed; TOTP secret is AES-256-CBC encrypted in PHP).
--   - All FKs cascade on user delete (see Admin remove flow).
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. Per-user MFA record (one row per user, created on setup start) ----------
CREATE TABLE IF NOT EXISTS `user_mfa` (
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `secret`           VARCHAR(255) NOT NULL COMMENT 'AES-256-CBC encrypted base32 TOTP secret (ENC:...), never logged',
  `is_enabled`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=setup pending, 1=active',
  `failed_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at`      DATETIME DEFAULT NULL COMMENT 'when MFA became active',
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_mfa_enabled` (`is_enabled`),
  CONSTRAINT `fk_mfa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. One-time recovery codes (hash only, single-use) --------------------------
CREATE TABLE IF NOT EXISTS `mfa_recovery_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL COMMENT 'password_hash() of the plain code',
  `used_at`    DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rec_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_rec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Trusted devices (hashed cookie tokens) -----------------------------------
CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 of the 64-hex device cookie',
  `device_name` VARCHAR(191) NOT NULL DEFAULT 'Unknown device',
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at`  DATETIME NOT NULL COMMENT 'rolling 180-day expiry',
  `revoked_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_token` (`token_hash`),
  KEY `idx_device_user` (`user_id`,`revoked_at`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Pending logins (password verified, awaiting device and/or MFA) -----------
CREATE TABLE IF NOT EXISTS `pending_logins` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `stage`        ENUM('device','mfa','setup') NOT NULL DEFAULT 'mfa' COMMENT 'current gate',
  `token_hash`   CHAR(64) DEFAULT NULL COMMENT 'SHA-256 of single-use email link token (device stage)',
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  `user_agent`   VARCHAR(255) DEFAULT NULL,
  `device_name`  VARCHAR(191) DEFAULT NULL,
  `trust_device` TINYINT(1) NOT NULL DEFAULT 1,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'MFA attempts on this challenge',
  `expires_at`   DATETIME NOT NULL,
  `used_at`      DATETIME DEFAULT NULL COMMENT 'consumed on full login',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pending_token` (`token_hash`),
  KEY `idx_pending_user` (`user_id`,`used_at`),
  KEY `idx_pending_expiry` (`expires_at`),
  CONSTRAINT `fk_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Active session registry (revocable sessions) ------------------------------
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `session_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 of PHP session_id()',
  `ip_address`    VARCHAR(45) DEFAULT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_hash` (`session_hash`),
  KEY `idx_usession_user` (`user_id`,`revoked_at`),
  CONSTRAINT `fk_usession_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
