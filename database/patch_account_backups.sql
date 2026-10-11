-- =============================================================================
-- EduPortal : Account safety backups (EXTEND only)
-- File : database/patch_account_backups.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- account_backups stores a JSON snapshot of an account (and, for removals,
-- its academic/finance records) BEFORE an admin reset or hard delete, plus
-- the staged upload directory. Restore replays the snapshot with id-remap.
-- No FK to users: the subject row may be gone; integrity is enforced in code.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `account_backups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'subject account id at backup time (row may be gone)',
  `email` varchar(191) NOT NULL DEFAULT '',
  `display_name` varchar(210) NOT NULL DEFAULT '',
  `role_name` varchar(30) NOT NULL DEFAULT '',
  `action` enum('reset','remove') NOT NULL COMMENT 'which admin action triggered this backup',
  `summary_json` longtext DEFAULT NULL COMMENT 'per-table row counts + stashed file count',
  `snapshot_json` longtext NOT NULL COMMENT 'table => rows (never secrets beyond hashes already stored)',
  `file_dir` varchar(255) DEFAULT NULL COMMENT 'relative stash dir under storage path, remove-action only',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_backup_user` (`user_id`),
  KEY `idx_backup_action_time` (`action`,`created_at`),
  CONSTRAINT `fk_backup_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
