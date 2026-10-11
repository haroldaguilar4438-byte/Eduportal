-- EduPortal: Digital Clearance Cycle (Semester → Exams → Clearance → Next Enrollment)
-- Datamex College: 2 semesters / SY. Professor signs after Prelim/Midterm/PreFinal/Final done.

SET FOREIGN_KEY_CHECKS=0;

-- 1. Semester-aware clearances (one per student per AY+semester)
CREATE TABLE IF NOT EXISTS `clearances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `semester` ENUM('1st','2nd','Summer') NOT NULL DEFAULT '1st',
  `status` ENUM('pending','signed','issued','used') NOT NULL DEFAULT 'pending',
  `qr_token` VARCHAR(64) DEFAULT NULL COMMENT '64-hex for QR verify',
  `pdf_path` VARCHAR(255) DEFAULT NULL,
  `requested_at` DATETIME DEFAULT NULL,
  `signed_at` DATETIME DEFAULT NULL,
  `issued_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clearance_student_sem` (`student_id`,`academic_year_id`,`semester`),
  KEY `idx_clearance_status` (`status`),
  KEY `idx_clearance_qr` (`qr_token`),
  CONSTRAINT `fk_clearance_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_clearance_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Digital clearance per semester';

-- 2. Per-subject professor signatures (all must be signed to issue)
CREATE TABLE IF NOT EXISTS `clearance_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clearance_id` BIGINT UNSIGNED NOT NULL,
  `class_offering_id` INT UNSIGNED NOT NULL COMMENT 'FK class_offerings.id',
  `teacher_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK teachers.id',
  `is_signed` TINYINT(1) NOT NULL DEFAULT 0,
  `signed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item` (`clearance_id`,`class_offering_id`),
  KEY `idx_item_teacher` (`teacher_id`),
  CONSTRAINT `fk_item_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearances`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_item_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_item_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-subject clearance sign';

-- 3. Link next enrollment to the clearance it consumed
ALTER TABLE `enrollment_applications` ADD COLUMN IF NOT EXISTS `clearance_id` BIGINT UNSIGNED DEFAULT NULL AFTER `preferred_campus_id`;
CREATE INDEX IF NOT EXISTS `idx_enroll_clearance` ON `enrollment_applications` (`clearance_id`);

-- 4. Ensure grades/report can be marked as clearance prerequisite (no schema change needed; grades + exam_permits already cover 4 periods)

SET FOREIGN_KEY_CHECKS=1;
