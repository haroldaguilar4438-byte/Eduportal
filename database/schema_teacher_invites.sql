-- =============================================================================
-- EduPortal : Secure Teacher Registration (Invite Code System)
-- Datamex College of Saint Adeline
-- File     : database/schema_teacher_invites.sql
-- Version  : 1.0.0
-- Requires : schema.sql must be imported first
-- -----------------------------------------------------------------------------
-- PURPOSE:
--   Prevent open self-registration as teacher. Teacher accounts can ONLY be
--   created via (A) admin-generated single-use invite code or (B) direct
--   admin creation in /admin/teachers. No plaintext invite is stored.
-- SECURITY:
--   - code_hash = password_hash(plain_code) (bcrypt, unique per invite)
--   - code_preview = last 4 chars for admin UI (e.g., ...3M2Q)
--   - Single-use, 7-day expiry, audit + rate limiting
--   - Admin-only generation, CSRF + Auth::requireRole('admin')
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `teacher_invites`;

CREATE TABLE IF NOT EXISTS `teacher_invites` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`        VARCHAR(255)    NOT NULL COMMENT 'password_hash of plain invite code (e.g., TCH-XXXX-XXXX)',
  `code_preview`     VARCHAR(8)      NOT NULL COMMENT 'last 4 chars for admin display, e.g., 3M2Q',
  `code_encrypted`   TEXT            DEFAULT NULL COMMENT 'AES-256-CBC encrypted plain code for secure reveal (base64 iv+ct)',
  `email_hint`       VARCHAR(191)    DEFAULT NULL COMMENT 'optional: restrict to this email (exact match)',
  `department`       VARCHAR(100)    DEFAULT NULL COMMENT 'optional default department for teacher',
  `created_by`       BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.id (admin who generated)',
  `expires_at`       DATETIME        NOT NULL COMMENT 'invite expiry, default 7 days',
  `used_at`          DATETIME        DEFAULT NULL,
  `used_by`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → users.id (teacher who redeemed)',
  `is_used`          TINYINT(1)      NOT NULL DEFAULT 0,
  `attempts`         INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'failed verification attempts (rate limiting)',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invite_creator` (`created_by`),
  KEY `idx_invite_used_by` (`used_by`),
  KEY `idx_invite_expiry` (`expires_at`),
  KEY `idx_invite_used` (`is_used`),
  CONSTRAINT `fk_invite_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_used_by` FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Single-use hashed invite codes for secure teacher registration';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Optional seed: none. Admin generates via UI /admin/teachers/invite.
-- To manually seed a test code (for dev only), run:
--   INSERT INTO teacher_invites (code_hash, code_preview, created_by, expires_at) VALUES (password_hash('TCH-DEMO-CODE', PASSWORD_DEFAULT), 'CODE', 1, DATE_ADD(NOW(), INTERVAL 7 DAY));
