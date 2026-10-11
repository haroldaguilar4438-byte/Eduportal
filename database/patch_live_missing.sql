-- EduPortal Live Patch v2 - idempotent - safe to import multiple times
-- Import via phpMyAdmin > select u788443931_datamex > Import

SET FOREIGN_KEY_CHECKS=0;

-- 1. users.remember_token
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `remember_token` VARCHAR(255) DEFAULT NULL AFTER `last_activity_at`;

-- 2. chat_history
CREATE TABLE IF NOT EXISTS `chat_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `sender` ENUM('user','ai') NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_user` (`user_id`),
  CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. payments missing columns
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `enrollment_application_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`;
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `payrex_client_secret` VARCHAR(255) DEFAULT NULL AFTER `payrex_payment_intent_id`;

-- 4. enrollment_applications missing columns (student_id already exists in base schema, so use IF NOT EXISTS)
ALTER TABLE `enrollment_applications` ADD COLUMN IF NOT EXISTS `payment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`;
ALTER TABLE `enrollment_applications` ADD COLUMN IF NOT EXISTS `amount_due` DECIMAL(10,2) DEFAULT 1500.00 AFTER `payment_id`;
ALTER TABLE `enrollment_applications` MODIFY `status` ENUM('pending','accounting_approved','awaiting_payment','action_required','approved','rejected') NOT NULL DEFAULT 'pending';

SET FOREIGN_KEY_CHECKS=1;
