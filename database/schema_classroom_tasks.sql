-- =============================================================================
-- EduPortal : Classroom Tasks (Google Classroom activities tied to subject_classrooms)
-- File     : database/schema_classroom_tasks.sql
-- Requires : Parent schema.sql + schema_classrooms.sql must be imported first
-- =============================================================================
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `classroom_tasks`;

CREATE TABLE IF NOT EXISTS `classroom_tasks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classroom_id` INT UNSIGNED    NOT NULL COMMENT 'FK → subject_classrooms.id',
  `title`        VARCHAR(150)    NOT NULL COMMENT 'e.g., Prelim Project',
  `instructions` TEXT            NULL,
  `due_at`       DATETIME        NULL,
  `posted_by`    BIGINT UNSIGNED NULL COMMENT 'FK → users.id (teacher)',
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_classroom` (`classroom_id`),
  KEY `idx_task_poster` (`posted_by`),
  KEY `idx_task_due` (`due_at`),
  CONSTRAINT `fk_task_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_poster` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-posted activities for subject classrooms (Google Classroom)';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
