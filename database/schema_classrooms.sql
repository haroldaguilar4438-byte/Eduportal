-- =============================================================================
-- EduPortal : Google Classroom-style Subject Invite (Teacher → Student)
-- Datamex College of Saint Adeline
-- File     : database/schema_classrooms.sql
-- Version  : 1.0.0
-- Engine   : InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Requires : Parent schema.sql must be imported first
-- -----------------------------------------------------------------------------
-- PURPOSE:
--   Teachers create subject classrooms with an invite code (e.g., X7K9M2)
--   Students join via code, mimicking Google Classroom.
--   Non-destructive additive tables only.
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `classroom_members`;
DROP TABLE IF EXISTS `subject_classrooms`;

CREATE TABLE IF NOT EXISTS `subject_classrooms` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invite_code`      VARCHAR(10)  NOT NULL COMMENT 'e.g., X7K9M2 / AB12-CD34, 6-10 chars unique',
  `subject_code`     VARCHAR(30)  NOT NULL COMMENT 'e.g., BSIT-201, MATH-101',
  `subject_title`    VARCHAR(150) NOT NULL COMMENT 'e.g., Data Structures',
  `description`      TEXT         NULL,
  `teacher_id`       BIGINT UNSIGNED NOT NULL COMMENT 'FK → teachers.id',
  `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'FK → academic_years.id',
  `section_name`     VARCHAR(60)  NULL COMMENT 'e.g., BSIT-2A, display only',
  `max_students`     SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`invite_code`),
  KEY `idx_classroom_teacher` (`teacher_id`),
  KEY `idx_classroom_ay` (`academic_year_id`),
  CONSTRAINT `fk_classroom_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_classroom_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-created subject classrooms with invite code';

CREATE TABLE IF NOT EXISTS `classroom_members` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classroom_id` INT UNSIGNED    NOT NULL COMMENT 'FK → subject_classrooms.id',
  `student_id`   BIGINT UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `joined_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member` (`classroom_id`,`student_id`),
  KEY `idx_member_student` (`student_id`),
  CONSTRAINT `fk_member_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_member_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Google Classroom-style joins';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
