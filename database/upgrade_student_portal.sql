-- =============================================================================
-- EduPortal : Student Portal Service Hub - SAFE UPGRADE MIGRATION
-- Datamex College of Saint Adeline
-- -----------------------------------------------------------------------------
-- File    : database/upgrade_student_portal.sql
-- Purpose : Add ONLY the missing structures for Class Offerings / Subject
--           Registration + structured schedules + teacher evaluations.
--           Reuses ALL existing tables (users, students, class_offerings,
--           subjects, sections, notifications, audit_logs, grades, payments...).
--
-- SAFETY  :
--   - Uses CREATE TABLE IF NOT EXISTS (no data loss, re-runnable)
--   - No DROP, no ALTER of existing tables, no data reset
--   - InnoDB + utf8mb4_unicode_ci to match existing schema
--   - Run via phpMyAdmin (Hostinger) or: mysql -u root eduportal_db < file.sql
--   - Rollback: DROP TABLE IF EXISTS teacher_evaluations,
--               class_offering_schedules, student_class_registrations;
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. student_class_registrations : STUDENT + CLASS_OFFERING (official academic
--    registration). DISTINCT from classroom_members (Google-Classroom-style
--    subject invite) which remains untouched.
--    - status 'registered' counts toward capacity; others are history.
--    - UNIQUE(student_id, class_offering_id) prevents double registration
--      at the DATABASE level (race-condition safe, with app-level check).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_class_registrations` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`        BIGINT UNSIGNED NOT NULL COMMENT 'FK -> students.id (server-side resolved, never from browser)',
  `class_offering_id` INT UNSIGNED NOT NULL COMMENT 'FK -> class_offerings.id',
  `status` ENUM('registered','dropped','cancelled','completed')
                      NOT NULL DEFAULT 'registered'
                      COMMENT 'registered=active seat, others=history',
  `registered_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dropped_at`        DATETIME DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_class_registration` (`student_id`,`class_offering_id`),
  KEY `idx_registration_student` (`student_id`),
  KEY `idx_registration_offering` (`class_offering_id`),
  KEY `idx_registration_status` (`status`),
  CONSTRAINT `fk_registration_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_registration_offering`
    FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. class_offering_schedules : STRUCTURED schedule for conflict detection.
--    The legacy `class_offerings.schedule_note` (free text, e.g.
--    'MWF 08:00-09:30') is PRESERVED and remains the display source.
--    This table is the AUTHORITATIVE source for overlap checks when rows
--    exist; otherwise the app best-effort parses schedule_note.
--    - day_of_week uses 3-letter ISO (MON..SUN).
--    - Overlapping check = same day AND overlapping [start_time, end_time).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `class_offering_schedules` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_offering_id` INT UNSIGNED NOT NULL,
  `day_of_week`      ENUM('MON','TUE','WED','THU','FRI','SAT','SUN') NOT NULL,
  `start_time`       TIME NOT NULL,
  `end_time`         TIME NOT NULL,
  `room`             VARCHAR(50) DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sched_offering` (`class_offering_id`),
  KEY `idx_sched_day_time` (`day_of_week`,`start_time`,`end_time`),
  CONSTRAINT `fk_sched_offering`
    FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_sched_time` CHECK (`end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. teacher_evaluations : student evaluates teacher per class offering.
--    - Only the registered student may evaluate (enforced in app + UNIQUE).
--    - rating 1-5, optional comments.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teacher_evaluations` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`        BIGINT UNSIGNED NOT NULL,
  `class_offering_id` INT UNSIGNED NOT NULL,
  `teacher_id`        BIGINT UNSIGNED DEFAULT NULL,
  `academic_year_id`  INT UNSIGNED DEFAULT NULL,
  `rating`            TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  `comments`          TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_once` (`student_id`,`class_offering_id`),
  KEY `idx_eval_teacher` (`teacher_id`),
  KEY `idx_eval_offering` (`class_offering_id`),
  CONSTRAINT `fk_eval_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_offering`
    FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_ay`
    FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_eval_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
