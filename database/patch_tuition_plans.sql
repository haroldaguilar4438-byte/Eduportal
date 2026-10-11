-- =============================================================================
-- EduPortal : Tuition payment plans (Full vs Monthly) — EXTEND only
-- File : database/patch_tuition_plans.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
--   tuition_plans            : admin-configured tuition (one active row)
--   enrollment_payment_plans : per-application plan snapshot + state
--   plan_installments        : monthly schedule rows (generated on activation)
--   payments += charge_kind + plan_installment_id (each charge pays exact due)
-- Existing rows: untouched. Historical amounts are never rewritten.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `tuition_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL DEFAULT 'Default tuition',
  `total_tuition` decimal(10,2) NOT NULL DEFAULT 10000.00,
  `downpayment_amount` decimal(10,2) NOT NULL DEFAULT 3000.00,
  `installment_months` tinyint(3) unsigned NOT NULL DEFAULT 7,
  `monthly_amount` decimal(10,2) DEFAULT NULL COMMENT 'NULL = auto (remaining/months, last adjusts)',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tuition_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tuition_plans` (`name`, `total_tuition`, `downpayment_amount`, `installment_months`, `monthly_amount`, `is_active`)
SELECT 'Default tuition 2026-2027', 10000.00, 3000.00, 7, NULL, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tuition_plans`);

CREATE TABLE IF NOT EXISTS `enrollment_payment_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `plan_type` enum('full','monthly') DEFAULT NULL COMMENT 'chosen at checkout',
  `total_tuition` decimal(10,2) NOT NULL,
  `downpayment_amount` decimal(10,2) NOT NULL,
  `installment_months` tinyint(3) unsigned NOT NULL,
  `monthly_amount` decimal(10,2) DEFAULT NULL,
  `downpayment_status` enum('required','pending','paid','failed') NOT NULL DEFAULT 'required',
  `downpayment_payment_id` bigint(20) unsigned DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL COMMENT 'monthly schedule start (downpayment confirmed)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epp_application` (`application_id`),
  KEY `fk_epp_plan` (`plan_id`),
  CONSTRAINT `fk_epp_application` FOREIGN KEY (`application_id`) REFERENCES `enrollment_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_epp_plan` FOREIGN KEY (`plan_id`) REFERENCES `tuition_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_installments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `epp_id` bigint(20) unsigned NOT NULL,
  `n` tinyint(3) unsigned NOT NULL COMMENT 'installment number 1..N',
  `amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('upcoming','due','pending','paid','failed','cancelled') NOT NULL DEFAULT 'upcoming',
  `paid_at` datetime DEFAULT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_installment_no` (`epp_id`,`n`),
  UNIQUE KEY `uq_installment_payment` (`payment_id`),
  KEY `idx_inst_epp_status` (`epp_id`,`status`),
  CONSTRAINT `fk_inst_epp` FOREIGN KEY (`epp_id`) REFERENCES `enrollment_payment_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_payplan`$$
CREATE PROCEDURE `__edu_add_col_payplan`(
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

CALL `__edu_add_col_payplan`('payments','charge_kind','ENUM(\'full\',\'downpayment\',\'installment\') DEFAULT NULL AFTER `payment_method`');
CALL `__edu_add_col_payplan`('payments','plan_installment_id','BIGINT UNSIGNED DEFAULT NULL AFTER `charge_kind`');

DROP PROCEDURE IF EXISTS `__edu_add_col_payplan`;

SET FOREIGN_KEY_CHECKS=1;
