-- =============================================================================
-- EduPortal : Classroom Task Submissions (Student uploads for Google Classroom tasks)
-- File     : database/schema_classroom_task_submissions.sql
-- Requires : schema_classrooms.sql + schema_classroom_tasks.sql must be imported first
-- =============================================================================
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `classroom_task_submissions`;

CREATE TABLE IF NOT EXISTS `classroom_task_submissions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`         BIGINT UNSIGNED NOT NULL COMMENT 'FK → classroom_tasks.id',
  `student_id`      BIGINT UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `file_path`       VARCHAR(255)    NULL COMMENT 'stored path under /uploads/submissions',
  `original_filename` VARCHAR(191)  NULL,
  `mime_type`       VARCHAR(100)    NULL,
  `file_size_bytes` INT UNSIGNED    NULL,
  `note`            TEXT            NULL COMMENT 'student submission note',
  `status`          ENUM('submitted','late','graded','returned') NOT NULL DEFAULT 'submitted',
  `score`           DECIMAL(6,2)    NULL,
  `feedback`        TEXT            NULL,
  `graded_by`       BIGINT UNSIGNED NULL COMMENT 'FK → users.id (teacher)',
  `graded_at`       DATETIME        NULL,
  `submitted_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_student` (`task_id`,`student_id`),
  KEY `idx_submission_student` (`student_id`),
  KEY `idx_submission_task` (`task_id`),
  CONSTRAINT `fk_cts_task` FOREIGN KEY (`task_id`) REFERENCES `classroom_tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cts_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cts_grader` FOREIGN KEY (`graded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Student uploads for Google Classroom tasks (click task → upload)';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
