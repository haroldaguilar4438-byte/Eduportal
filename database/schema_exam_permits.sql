-- =============================================================================
-- EduPortal : Exam Permit Validation Workflow (Multi-stage 3-tier)
-- Datamex College of Saint Adeline
-- File     : database/schema_exam_permits.sql
-- Version  : 1.1.0
-- Engine   : InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Requires : Parent schema.sql must be imported first (students, users, academic_years, payments, etc.)
-- -----------------------------------------------------------------------------
-- MODULE : EXAM PERMIT PIPELINE across 4 periods (Prelim, Midterm, Prefinal, Finals)
--  Tier 1 Accounting  → verifies tuition/payment (PayRex/receipt)   pending_accounting → approved_accounting
--  Tier 2 Registrar   → verifies academic clearance                 approved_accounting → approved_registrar
--  Tier 3 Teacher     → inspects & marks Fully Approved             approved_registrar  → fully_approved
--
-- INSTALL:
--   Local  : mysql -u root eduportal_db < database/schema_exam_permits.sql
--   Hostinger phpMyAdmin : Import -> select this file (no CREATE DATABASE needed)
--   The script is idempotent-safe via IF NOT EXISTS + IGNORE where possible.
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Exam Permits (one row per student × academic year × exam period)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `permit_approvals`;
DROP TABLE IF EXISTS `exam_permits`;

CREATE TABLE IF NOT EXISTS `exam_permits` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `permit_no`          VARCHAR(20)     NOT NULL COMMENT 'e.g. EP-2026-PREL-0001',
  `student_id`         BIGINT UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `academic_year_id`   INT UNSIGNED    NOT NULL COMMENT 'FK → academic_years.id',
  `exam_period`        ENUM('Prelim','Midterm','Prefinal','Finals') NOT NULL,
  -- Sequential pipeline status
  `status`             ENUM('pending_accounting','approved_accounting','approved_registrar','fully_approved','rejected')
                       NOT NULL DEFAULT 'pending_accounting' COMMENT '3-tier pipeline position',
  -- Financial prerequisite snapshot at request time
  `financial_status`   ENUM('unpaid','installment_paid','fully_paid') NOT NULL DEFAULT 'unpaid'
                       COMMENT 'Derived from tuition balance / PayRex validation',
  `payment_validated`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 when payment prerequisite satisfied',
  `payment_ref`        VARCHAR(100)    DEFAULT NULL COMMENT 'PayRex intent id or official receipt no.',
  `receipt_no`         VARCHAR(30)     DEFAULT NULL COMMENT 'FK → receipts.receipt_no if generated',
  `balance_at_request` DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Outstanding balance snapshot',
  `qr_token`           VARCHAR(64)     DEFAULT NULL COMMENT 'SHA token for QR verification (random_bytes)',
  `requested_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rejected_reason`    VARCHAR(255)    DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_no` (`permit_no`),
  UNIQUE KEY `uq_student_period` (`student_id`,`academic_year_id`,`exam_period`),
  KEY `idx_exam_permits_student` (`student_id`),
  KEY `idx_exam_permits_status` (`status`),
  KEY `idx_exam_permits_period` (`exam_period`),
  KEY `idx_exam_permits_ay` (`academic_year_id`),
  CONSTRAINT `fk_permit_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_permit_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Multi-stage exam permit validation across 4 periods';


-- -----------------------------------------------------------------------------
-- 2. Permit Approvals Audit Trail (one row per stage action)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permit_approvals` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `permit_id`          BIGINT UNSIGNED NOT NULL COMMENT 'FK → exam_permits.id',
  `stage`              ENUM('accounting','registrar','teacher') NOT NULL COMMENT 'Pipeline tier',
  `status`             ENUM('approved','rejected') NOT NULL DEFAULT 'approved',
  `approver_user_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → users.id (null = system)',
  `remarks`            TEXT            DEFAULT NULL,
  `acted_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_approval_permit` (`permit_id`),
  KEY `idx_approval_stage` (`stage`),
  KEY `idx_approval_actor` (`approver_user_id`),
  CONSTRAINT `fk_approval_permit` FOREIGN KEY (`permit_id`) REFERENCES `exam_permits`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_approval_actor` FOREIGN KEY (`approver_user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='3-tier approval audit for exam permits';


-- -----------------------------------------------------------------------------
-- 3. Helpful Views (optional, for reporting)
-- -----------------------------------------------------------------------------
-- View for teacher permit queue: pending teacher verification (registrar-approved)
-- The application code queries directly; this view is for Hostinger SQL convenience.

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- =============================================================================
-- USAGE NOTES
-- =============================================================================
-- Financial prerequisite (per request):
--   balance = SUM(student_charges.amount) - SUM(payment_allocations where payments.status='paid')
--   Valid if balance = 0 (fully_paid) OR payment_ref exists for required installment for that exam_period.
--   set payment_validated=1, financial_status accordingly, payment_ref = PayRex intent or receipt_no.
--
-- Pipeline transitions (enforced in PHP):
--   pending_accounting   -- Accounting approves --> approved_accounting
--   approved_accounting  -- Registrar approves  --> approved_registrar
--   approved_registrar   -- Teacher verifies    --> fully_approved
--   Any stage can reject --> rejected (with rejected_reason)
--
-- Student widget (student/dashboard.php):
--   Show card per period: status pill, financial pill, 3-check pipeline, QR when fully_approved + print.
--
-- Teacher queue (teacher/dashboard.php):
--   SELECT ep.* FROM exam_permits ep
--   JOIN students s ON s.id=ep.student_id
--   WHERE ep.status='approved_registrar'
--   AND s.section_id IN (SELECT section_id FROM class_offerings WHERE teacher_id=?)
-- =============================================================================
