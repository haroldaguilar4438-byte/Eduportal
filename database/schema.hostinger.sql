-- =============================================================================
-- EduPortal : Web-Based Integrated School Management System
-- Datamex College of Saint Adeline
-- -----------------------------------------------------------------------------
-- File     : database/schema.sql
-- Version  : 1.0.0
-- Engine   : InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Target   : Hostinger Shared Hosting (MySQL 8.x / MariaDB 10.x, phpMyAdmin)
--
-- INSTALL ON HOSTINGER
--   1. hPanel -> Websites -> Manage -> Databases -> create DB + user.
--   2. hPanel -> phpMyAdmin -> select the new DB -> Import -> this file.
--      (Skip the CREATE DATABASE / USE lines below when importing there.)
--   3. Visit /install/setup-admin.php once to create the real admin account
--      (proper password_hash + verified flag), then DELETE the /install folder.
--
-- SECURITY NOTES
--   - All money columns: DECIMAL(10,2). All grade columns: DECIMAL(5,2).
--   - Tokens are 64-char hex produced by bin2hex(random_bytes(32)).
--   - otp_code_hash stores password_hash() of the OTP, never the plain OTP.
--   - Never store PayRex secret keys in the DB; keep them in app/config.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. DATABASE (local-dev convenience; hPanel already creates it in production)
-- -----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `u788443931_eduportal`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `u788443931_eduportal`;

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `at_risk_flags`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `email_log`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `receipts`;
DROP TABLE IF EXISTS `payment_webhook_logs`;
DROP TABLE IF EXISTS `payment_allocations`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `student_charges`;
DROP TABLE IF EXISTS `fee_structures`;
DROP TABLE IF EXISTS `fee_types`;
DROP TABLE IF EXISTS `assignment_submissions`;
DROP TABLE IF EXISTS `assignments`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `grades`;
DROP TABLE IF EXISTS `enrollment_documents`;
DROP TABLE IF EXISTS `enrollment_applications`;
DROP TABLE IF EXISTS `campuses`;
DROP TABLE IF EXISTS `class_offerings`;
DROP TABLE IF EXISTS `guardian_students`;
DROP TABLE IF EXISTS `guardians`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `teachers`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `programs`;
DROP TABLE IF EXISTS `grade_levels`;
DROP TABLE IF EXISTS `academic_years`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

-- =============================================================================
-- MODULE 1 : AUTHENTICATION, ROLES & EMAIL VERIFICATION
-- =============================================================================

-- 1.1 Roles (RBAC dictionary)
CREATE TABLE `roles` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(30)  NOT NULL COMMENT 'admin | teacher | student | parent',
  `description` VARCHAR(120) DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.2 Users (single auth identity for every role)
CREATE TABLE `users` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`               TINYINT UNSIGNED NOT NULL,
  `email`                 VARCHAR(191) NOT NULL,
  `password_hash`         VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
  `first_name`            VARCHAR(100) NOT NULL,
  `middle_name`           VARCHAR(100) DEFAULT NULL,
  `last_name`             VARCHAR(100) NOT NULL,
  `suffix`                VARCHAR(20)  DEFAULT NULL,
  `phone`                 VARCHAR(30)  DEFAULT NULL,
  `avatar_path`           VARCHAR(255) DEFAULT NULL,
  -- email verification -------------------------------------------------------
  `is_email_verified`     TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`           DATETIME     DEFAULT NULL,
  `verification_token`    VARCHAR(64)  DEFAULT NULL COMMENT 'link token (64-hex)',
  `token_expiry`          DATETIME     DEFAULT NULL COMMENT 'verification token expiry',
  `otp_code_hash`         VARCHAR(255) DEFAULT NULL COMMENT 'hashed 6-digit OTP',
  `otp_expiry`            DATETIME     DEFAULT NULL,
  `otp_attempts`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- password reset ------------------------------------------------------------
  `reset_token`           VARCHAR(64)  DEFAULT NULL,
  `reset_token_expiry`    DATETIME     DEFAULT NULL,
  -- lifecycle & session timeout -----------------------------------------------
  `status` ENUM('pending_verification','active','suspended','locked','archived')
                          NOT NULL DEFAULT 'pending_verification',
  `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`          DATETIME     DEFAULT NULL,
  `last_login_at`         DATETIME     DEFAULT NULL,
  `last_login_ip`         VARCHAR(45)  DEFAULT NULL,
  `last_activity_at`      DATETIME     DEFAULT NULL COMMENT 'drives idle auto-timeout',
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_verification_token` (`verification_token`),
  KEY `idx_users_reset_token` (`reset_token`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 2 : ACADEMIC STRUCTURE
-- =============================================================================

-- 2.1 Academic years ('2026-2027'); college semesters live on child tables
CREATE TABLE `academic_years` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` VARCHAR(9)  NOT NULL COMMENT 'YYYY-YYYY',
  `start_date`  DATE        DEFAULT NULL,
  `end_date`    DATE        DEFAULT NULL,
  `is_current`  TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'enforce exactly one via app logic',
  `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_years_sy` (`school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 Grade levels: Kindergarten -> Grade 12 -> College year levels
CREATE TABLE `grade_levels` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`   ENUM('kindergarten','elementary','junior_high','senior_high','college') NOT NULL,
  `code`       VARCHAR(10)  NOT NULL COMMENT 'K, G1..G12, C1..C4',
  `name`       VARCHAR(60)  NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_levels_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 College programs (nullable everywhere else => basic-ed unaffected)
CREATE TABLE `programs` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(20) NOT NULL COMMENT 'BSIT, BSED...',
  `name`           VARCHAR(150) NOT NULL,
  `degree_type`    ENUM('bachelor','associate','diploma','certificate') NOT NULL DEFAULT 'bachelor',
  `department`     VARCHAR(100) DEFAULT NULL,
  `duration_years` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `is_active`      TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_programs_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.4 Campuses / School Locations (for applicant proximity choice)
CREATE TABLE `campuses` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL COMMENT 'DASM-MAIN, SILANG...',
  `name`       VARCHAR(100) NOT NULL COMMENT 'Dasmari�as Main Campus',
  `address`    VARCHAR(255) NOT NULL COMMENT 'Brgy., City, Province',
  `city`       VARCHAR(80)  DEFAULT NULL,
  `province`   VARCHAR(80)  DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campuses_code` (`code`),
  KEY `idx_campuses_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.5 Sections per grade level per academic year
CREATE TABLE `sections` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(60) NOT NULL,
  `grade_level_id`   SMALLINT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `adviser_id`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'teachers.id',
  `room`             VARCHAR(50) DEFAULT NULL,
  `capacity`         SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `status`           ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_per_level_ay` (`name`,`grade_level_id`,`academic_year_id`),
  KEY `idx_sections_level` (`grade_level_id`),
  CONSTRAINT `fk_sections_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sections_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `teachers`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sections_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.6 Subjects (per level; semester only for SHS/college)
CREATE TABLE `subjects` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(30) NOT NULL,
  `title`          VARCHAR(150) NOT NULL,
  `description`    VARCHAR(500) DEFAULT NULL,
  `units`          DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  `grade_level_id` SMALLINT UNSIGNED NOT NULL,
  `semester`       ENUM('1st','2nd','Summer') DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code_level` (`code`,`grade_level_id`),
  CONSTRAINT `fk_subjects_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 3 : PEOPLE (TEACHERS, STUDENTS, GUARDIANS)
-- =============================================================================

-- 3.1 Teachers
CREATE TABLE `teachers` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `employee_no`       VARCHAR(30) NOT NULL,
  `department`        VARCHAR(100) DEFAULT NULL,
  `position`          VARCHAR(80)  DEFAULT NULL,
  `specialization`    VARCHAR(150) DEFAULT NULL,
  `employment_status` ENUM('full_time','part_time','substitute') NOT NULL DEFAULT 'full_time',
  `date_hired`        DATE DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teachers_user` (`user_id`),
  UNIQUE KEY `uq_teachers_employee_no` (`employee_no`),
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 Students (profile extends users; created/approved via enrollment)
CREATE TABLE `students` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL until portal account linked',
  `student_no`       VARCHAR(20) DEFAULT NULL COMMENT 'e.g. 2026-00001',
  `lrn`              VARCHAR(12) DEFAULT NULL COMMENT 'DepEd Learner Reference Number',
  `program_id`       INT UNSIGNED DEFAULT NULL COMMENT 'college only',
  `grade_level_id`   SMALLINT UNSIGNED DEFAULT NULL,
  `section_id`       INT UNSIGNED DEFAULT NULL,
  `date_of_birth`    DATE DEFAULT NULL,
  `gender`           ENUM('male','female','other') DEFAULT NULL,
  `civil_status`     ENUM('single','married','widowed','separated') DEFAULT NULL,
  `nationality`      VARCHAR(60) NOT NULL DEFAULT 'Filipino',
  `religion`         VARCHAR(60) DEFAULT NULL,
  `address_line1`    VARCHAR(150) DEFAULT NULL,
  `address_city`     VARCHAR(80)  DEFAULT NULL,
  `address_province` VARCHAR(80)  DEFAULT NULL,
  `address_zip`      VARCHAR(10)  DEFAULT NULL,
  `admission_date`   DATE DEFAULT NULL,
  `status` ENUM('applicant','enrolled','on_hold','graduated','transferred','dropped')
                     NOT NULL DEFAULT 'applicant',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_student_no` (`student_no`),
  UNIQUE KEY `uq_students_user` (`user_id`),
  KEY `idx_students_lrn` (`lrn`),
  KEY `idx_students_level_section` (`grade_level_id`,`section_id`),
  CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 Guardians / parents
CREATE TABLE `guardians` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              BIGINT UNSIGNED DEFAULT NULL COMMENT 'linked parent portal login',
  `first_name`           VARCHAR(100) NOT NULL,
  `middle_name`          VARCHAR(100) DEFAULT NULL,
  `last_name`            VARCHAR(100) NOT NULL,
  `contact_no`           VARCHAR(30)  DEFAULT NULL,
  `email`                VARCHAR(191) DEFAULT NULL,
  `occupation`           VARCHAR(100) DEFAULT NULL,
  `employer`             VARCHAR(150) DEFAULT NULL,
  `is_emergency_contact` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guardians_user` (`user_id`),
  CONSTRAINT `fk_guardians_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.4 Guardian <-> Student pivot
CREATE TABLE `guardian_students` (
  `guardian_id`  BIGINT UNSIGNED NOT NULL,
  `student_id`   BIGINT UNSIGNED NOT NULL,
  `relationship` ENUM('mother','father','legal_guardian','spouse','other') NOT NULL,
  `is_primary`   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`guardian_id`,`student_id`),
  KEY `idx_gs_student` (`student_id`),
  CONSTRAINT `fk_gs_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `guardians`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gs_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MODULE 2 : ENROLLMENT SYSTEM (Kinder to College)
-- =============================================================================

-- 4.1 Enrollment applications with status tracking
CREATE TABLE `enrollment_applications` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_no`         VARCHAR(20) NOT NULL COMMENT 'e.g. EN-2026-000123',
  `user_id`                BIGINT UNSIGNED DEFAULT NULL COMMENT 'applicant portal account',
  `student_id`             BIGINT UNSIGNED DEFAULT NULL COMMENT 'linked after approval',
  `academic_year_id`       INT UNSIGNED NOT NULL,
  `grade_level_applied_id` SMALLINT UNSIGNED NOT NULL,
  `program_id`             INT UNSIGNED DEFAULT NULL COMMENT 'college applicants only',
  `semester`               ENUM('1st','2nd','Summer') DEFAULT NULL,
  `new_or_returning`       ENUM('new','returning','transferee') NOT NULL DEFAULT 'new',
  `returning_student_no`   VARCHAR(20) DEFAULT NULL COMMENT 'required when new_or_returning=returning, e.g. 2023022',
  `preferred_campus_id`    INT UNSIGNED DEFAULT NULL COMMENT 'chosen school location / campus',
  `previous_school`        VARCHAR(150) DEFAULT NULL,
  `last_grade_completed`   VARCHAR(50)  DEFAULT NULL,
  `status` ENUM('pending','action_required','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`            BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at`            DATETIME DEFAULT NULL,
  `remarks`                TEXT DEFAULT NULL COMMENT 'reason for action_required/rejected',
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enroll_app_no` (`application_no`),
  KEY `idx_enroll_status` (`status`),
  KEY `idx_enroll_user` (`user_id`),
  KEY `idx_enroll_campus` (`preferred_campus_id`),
  CONSTRAINT `fk_enroll_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_level` FOREIGN KEY (`grade_level_applied_id`) REFERENCES `grade_levels`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_campus` FOREIGN KEY (`preferred_campus_id`) REFERENCES `campuses`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.2 Uploaded requirement documents (Form 137/138, PSA BC, Good Moral, photo)
CREATE TABLE `enrollment_documents` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id`    BIGINT UNSIGNED NOT NULL,
  `doc_type` ENUM('form137','form138_report_card','birth_certificate',
                   'good_moral','id_photo','others') NOT NULL,
  `original_filename` VARCHAR(191) NOT NULL,
  `stored_path`       VARCHAR(255) NOT NULL COMMENT 'relative under /uploads/documents',
  `mime_type`         VARCHAR(100) NOT NULL,
  `file_size_bytes`   INT UNSIGNED NOT NULL,
  `is_verified`       TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_by`       BIGINT UNSIGNED DEFAULT NULL,
  `verified_at`       DATETIME DEFAULT NULL,
  `uploaded_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_edocs_application` (`application_id`),
  CONSTRAINT `fk_edocs_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_edocs_application` FOREIGN KEY (`application_id`) REFERENCES `enrollment_applications`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 5 : SCHEDULING & GRADING
-- =============================================================================

-- 5.1 Class offerings = subject taught by a teacher in a section per term
CREATE TABLE `class_offerings` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_id`       INT UNSIGNED NOT NULL,
  `section_id`       INT UNSIGNED NOT NULL,
  `teacher_id`       BIGINT UNSIGNED DEFAULT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `semester`         ENUM('1st','2nd','Summer') DEFAULT NULL COMMENT 'SHS/college terms',
  `room`             VARCHAR(50) DEFAULT NULL,
  `schedule_note`    VARCHAR(255) DEFAULT NULL COMMENT 'MWF 08:00-09:30 etc.',
  `max_students`     SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  `status`           ENUM('open','closed','completed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offering` (`subject_id`,`section_id`,`academic_year_id`,`semester`),
  KEY `idx_offering_teacher` (`teacher_id`),
  CONSTRAINT `fk_offering_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5.2 Grades (one row per student x offering x grading period)
CREATE TABLE `grades` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`         BIGINT UNSIGNED NOT NULL,
  `class_offering_id`  INT UNSIGNED NOT NULL,
  `grading_period`     ENUM('Q1','Q2','Q3','Q4','MIDTERM','FINAL') NOT NULL,
  `grade`              DECIMAL(5,2) DEFAULT NULL COMMENT 'raw period grade',
  `final_grade`        DECIMAL(5,2) DEFAULT NULL COMMENT 'term average once all periods encoded',
  `gpa_points`         DECIMAL(4,2) DEFAULT NULL COMMENT 'college GPA equivalent',
  `is_released`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'release gate for student/parent view',
  `remarks`            VARCHAR(255) DEFAULT NULL,
  `encoded_by`         BIGINT UNSIGNED DEFAULT NULL,
  `released_by`        BIGINT UNSIGNED DEFAULT NULL,
  `released_at`        DATETIME DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_row` (`student_id`,`class_offering_id`,`grading_period`),
  KEY `idx_grades_offering` (`class_offering_id`),
  KEY `idx_grades_release` (`is_released`),
  CONSTRAINT `fk_grades_releaser` FOREIGN KEY (`released_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MODULE 5 : TASKS, ANNOUNCEMENTS & EVENTS
-- =============================================================================

-- 6.1 Teacher-posted assignments/tasks per class offering
CREATE TABLE `assignments` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_offering_id` INT UNSIGNED NOT NULL,
  `title`             VARCHAR(150) NOT NULL,
  `instructions`      TEXT DEFAULT NULL,
  `attachment_path`   VARCHAR(255) DEFAULT NULL COMMENT 'teacher reference file',
  `max_score`         DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  `due_at`            DATETIME DEFAULT NULL,
  `allow_late`        TINYINT(1)   NOT NULL DEFAULT 1,
  `posted_by`         BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assignments_offering` (`class_offering_id`),
  CONSTRAINT `fk_assignments_poster` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6.2 Student submissions + grading status
CREATE TABLE `assignment_submissions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id`   BIGINT UNSIGNED NOT NULL,
  `student_id`      BIGINT UNSIGNED NOT NULL,
  `submission_note` TEXT DEFAULT NULL,
  `file_path`       VARCHAR(255) DEFAULT NULL COMMENT 'path under /uploads/submissions',
  `submitted_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('submitted','late','missing','graded','returned') NOT NULL DEFAULT 'submitted',
  `score`           DECIMAL(6,2) DEFAULT NULL,
  `feedback`        TEXT DEFAULT NULL,
  `graded_by`       BIGINT UNSIGNED DEFAULT NULL,
  `graded_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_once` (`assignment_id`,`student_id`),
  CONSTRAINT `fk_sub_grader` FOREIGN KEY (`graded_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7.1 Announcements (school-wide or class-specific)
CREATE TABLE `announcements` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(150) NOT NULL,
  `content`     MEDIUMTEXT NOT NULL,
  `audience`    ENUM('everyone','students','parents','teachers','staff','class') NOT NULL DEFAULT 'everyone',
  `section_id`  INT UNSIGNED DEFAULT NULL COMMENT 'required when audience = class',
  `posted_by`   BIGINT UNSIGNED DEFAULT NULL,
  `publish_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME DEFAULT NULL,
  `is_pinned`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_audience` (`audience`,`section_id`),
  CONSTRAINT `fk_ann_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ann_poster` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7.2 Events calendar
CREATE TABLE `events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `venue`       VARCHAR(150) DEFAULT NULL,
  `start_at`    DATETIME NOT NULL,
  `end_at`      DATETIME DEFAULT NULL,
  `all_day`     TINYINT(1) NOT NULL DEFAULT 0,
  `audience`    ENUM('everyone','students','parents','teachers','staff') NOT NULL DEFAULT 'everyone',
  `created_by`  BIGINT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_start` (`start_at`),
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- MODULE 6 : FINANCE - FEES, PAYMENTS (PayRex), RECEIPTS, WEBHOOKS
-- =============================================================================

-- 8.1 Fee type dictionary
CREATE TABLE `fee_types` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL COMMENT 'Tuition, Miscellaneous, ...',
  `description`   VARCHAR(255) DEFAULT NULL,
  `is_refundable` TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fee_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.2 Fee structures per level / program / academic year
CREATE TABLE `fee_structures` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fee_type_id`       SMALLINT UNSIGNED NOT NULL,
  `grade_level_id`    SMALLINT UNSIGNED DEFAULT NULL,
  `program_id`        INT UNSIGNED DEFAULT NULL,
  `academic_year_id`  INT UNSIGNED NOT NULL,
  `semester`          ENUM('1st','2nd','Summer') DEFAULT NULL,
  `amount`            DECIMAL(10,2) NOT NULL,
  `payment_frequency` ENUM('one_time','per_semester','quarterly','monthly') NOT NULL DEFAULT 'per_semester',
  `effective_from`    DATE DEFAULT NULL,
  `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_fstruct_level` (`grade_level_id`,`academic_year_id`),
  CONSTRAINT `fk_fstruct_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_type` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.3 Ledger of charges billed to each student (assessment of fees)
CREATE TABLE `student_charges` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`       BIGINT UNSIGNED NOT NULL,
  `fee_structure_id` INT UNSIGNED DEFAULT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `description`      VARCHAR(191) NOT NULL,
  `amount`           DECIMAL(10,2) NOT NULL,
  `charge_date`      DATE NOT NULL,
  `posted_by`        BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_charges_student` (`student_id`,`academic_year_id`),
  CONSTRAINT `fk_charges_fstruct` FOREIGN KEY (`fee_structure_id`) REFERENCES `fee_structures`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_poster` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.4 Payments (PayRex online + over-the-counter)
CREATE TABLE `payments` (
  `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_ref`          VARCHAR(40) NOT NULL COMMENT 'internal ref e.g. PAY-2026-000123',
  `student_id`               BIGINT UNSIGNED NOT NULL,
  `amount`                   DECIMAL(10,2) NOT NULL,
  `currency`                 CHAR(3) NOT NULL DEFAULT 'PHP',
  `payment_method`           VARCHAR(30) NOT NULL COMMENT 'gcash|maya|card|qrph|grab_pay|cash|bank',
  `channel`                  ENUM('online','counter') NOT NULL DEFAULT 'online',
  `status` ENUM('pending','processing','paid','failed','cancelled','expired','refunded')
                             NOT NULL DEFAULT 'pending',
  -- PayRex identifiers ----------------------------------------------------------
  `payrex_payment_intent_id` VARCHAR(100) DEFAULT NULL COMMENT 'pi_... from PayRex API',
  `payrex_resource_ref`      VARCHAR(100) DEFAULT NULL COMMENT 'checkout/link resource id',
  `payrex_last_event_id`     VARCHAR(100) DEFAULT NULL COMMENT 'last webhook event applied (idempotency)',
  `paid_at`                  DATETIME DEFAULT NULL,
  `failure_reason`           VARCHAR(255) DEFAULT NULL,
  `initiated_by`             BIGINT UNSIGNED DEFAULT NULL,
  `ip_address`               VARCHAR(45) DEFAULT NULL,
  `meta_json`                LONGTEXT DEFAULT NULL COMMENT 'raw provider payload snapshot',
  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_ref` (`transaction_ref`),
  KEY `idx_payments_student` (`student_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_intent` (`payrex_payment_intent_id`),
  KEY `idx_payments_paid_at` (`paid_at`),
  CONSTRAINT `fk_payments_initiator` FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.5 Payment allocations (which charges a payment settles)
CREATE TABLE `payment_allocations` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`  BIGINT UNSIGNED NOT NULL,
  `charge_id`   BIGINT UNSIGNED NOT NULL,
  `amount_applied` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alloc_once` (`payment_id`,`charge_id`),
  CONSTRAINT `fk_alloc_charge` FOREIGN KEY (`charge_id`) REFERENCES `student_charges`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.6 PayRex webhook event log (audit + idempotent replay)
CREATE TABLE `payment_webhook_logs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`         VARCHAR(20)  NOT NULL DEFAULT 'payrex',
  `event_id`         VARCHAR(100) DEFAULT NULL COMMENT 'we_... dedupe key',
  `event_type`       VARCHAR(60)  DEFAULT NULL COMMENT 'payment_intent.succeeded etc.',
  `payload_json`     LONGTEXT     DEFAULT NULL,
  `signature_valid`  TINYINT(1)   NOT NULL DEFAULT 0,
  `processed`        TINYINT(1)   NOT NULL DEFAULT 0,
  `processing_error` VARCHAR(500) DEFAULT NULL,
  `received_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`,`event_id`),
  KEY `idx_webhook_processed` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.7 Electronic receipts (emailed via PHPMailer)
CREATE TABLE `receipts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`  BIGINT UNSIGNED NOT NULL,
  `receipt_no`  VARCHAR(30) NOT NULL COMMENT 'e.g. RC-2026-000456',
  `issued_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailed_to`  VARCHAR(191) DEFAULT NULL,
  `emailed_at`  DATETIME DEFAULT NULL,
  `pdf_path`    VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipts_no` (`receipt_no`),
  UNIQUE KEY `uq_receipts_payment` (`payment_id`),
  CONSTRAINT `fk_receipts_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- SECTION 9 : NOTIFICATIONS, LOGGING & SYSTEM TABLES
-- =============================================================================

-- 9.1 In-app notifications
CREATE TABLE `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `title`      VARCHAR(150) NOT NULL,
  `message`    TEXT DEFAULT NULL,
  `type`       VARCHAR(30) NOT NULL DEFAULT 'info' COMMENT 'info|success|warning|danger|payment|grade|task',
  `link_url`   VARCHAR(255) DEFAULT NULL,
  `is_read`    TINYINT(1)  NOT NULL DEFAULT 0,
  `read_at`    DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.2 Audit trail (who did what)
CREATE TABLE `audit_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `action`         VARCHAR(60)  NOT NULL COMMENT 'login, approve_enrollment, post_grade...',
  `entity_type`    VARCHAR(40)  DEFAULT NULL,
  `entity_id`      BIGINT UNSIGNED DEFAULT NULL,
  `old_values_json` LONGTEXT DEFAULT NULL,
  `new_values_json` LONGTEXT DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor_time` (`actor_user_id`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.3 Brute-force protection log
CREATE TABLE `login_attempts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_attempted` VARCHAR(191) NOT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `success`         TINYINT(1)   NOT NULL DEFAULT 0,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `attempted_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email_attempted`,`attempted_at`),
  KEY `idx_attempts_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.4 Outbound email log (PHPMailer results)
CREATE TABLE `email_log` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL,
  `recipient_email`  VARCHAR(191) NOT NULL,
  `subject`          VARCHAR(191) NOT NULL,
  `template`         VARCHAR(60)  DEFAULT NULL COMMENT 'verify_account, reset_password, receipt...',
  `status`           ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `error_message`    VARCHAR(500) DEFAULT NULL,
  `sent_at`          DATETIME DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_recipient` (`recipient_email`),
  CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.5 Key-value system settings (admin-editable)
CREATE TABLE `system_settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` MEDIUMTEXT   DEFAULT NULL,
  `setting_group` VARCHAR(40)  NOT NULL DEFAULT 'general' COMMENT 'general|smtp|payrex|enrollment',
  `updated_by`    BIGINT UNSIGNED DEFAULT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MODULE 7 : ANALYTICS - AT-RISK STUDENT FLAGS (rule engine / ML output sink)
-- =============================================================================

CREATE TABLE `at_risk_flags` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`        BIGINT UNSIGNED NOT NULL,
  `academic_year_id`  INT UNSIGNED NOT NULL,
  `risk_level`        ENUM('low','moderate','high') NOT NULL,
  `risk_score`        DECIMAL(5,2) DEFAULT NULL COMMENT '0-100 composite score',
  `contributing_factors_json` LONGTEXT DEFAULT NULL
                      COMMENT 'e.g. {"avg_grade":72.5,"failing_subjects":2,"unpaid_balance":15000}',
  `recommendations`   TEXT DEFAULT NULL COMMENT 'advising / tutorial / payment plan',
  `detection_source`  ENUM('rule_engine','ml_batch','manual') NOT NULL DEFAULT 'rule_engine',
  `flagged_by`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'set when detection_source = manual',
  `status`            ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  `resolved_by`       BIGINT UNSIGNED DEFAULT NULL,
  `resolved_at`       DATETIME DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_risk_student_ay` (`student_id`,`academic_year_id`),
  KEY `idx_risk_status_level` (`status`,`risk_level`),
  CONSTRAINT `fk_risk_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_flagger` FOREIGN KEY (`flagged_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- =============================================================================
-- SECTION 10 : SEED DATA
-- =============================================================================

-- 10.1 Roles (RBAC)
INSERT INTO `roles` (`id`,`name`,`description`) VALUES
(1,'admin',   'System Administrator - full control'),
(2,'teacher', 'Faculty Member - classes, grades, tasks'),
(3,'student', 'Enrolled Student - portal access'),
(4,'parent',  'Parent / Guardian - child monitoring');

-- 10.2 Grade levels: Kindergarten -> College
INSERT INTO `grade_levels` (`category`,`code`,`name`,`sort_order`) VALUES
('kindergarten','K',   'Kindergarten',              1),
('elementary',  'G1',  'Grade 1',                   2),
('elementary',  'G2',  'Grade 2',                   3),
('elementary',  'G3',  'Grade 3',                   4),
('elementary',  'G4',  'Grade 4',                   5),
('elementary',  'G5',  'Grade 5',                   6),
('elementary',  'G6',  'Grade 6',                   7),
('junior_high', 'G7',  'Grade 7',                   8),
('junior_high', 'G8',  'Grade 8',                   9),
('junior_high', 'G9',  'Grade 9',                  10),
('junior_high', 'G10', 'Grade 10',                 11),
('senior_high', 'G11', 'Grade 11 (SHS)',           12),
('senior_high', 'G12', 'Grade 12 (SHS)',           13),
('college',     'C1',  'College - First Year',     14),
('college',     'C2',  'College - Second Year',    15),
('college',     'C3',  'College - Third Year',     16),
('college',     'C4',  'College - Fourth Year',    17);

-- 10.3 College programs (sample - adjust to actual Datamex offerings)
INSERT INTO `programs` (`code`,`name`,`degree_type`,`department`,`duration_years`) VALUES
('BSIT','BS Information Technology','bachelor','Information Technology',4),
('BSBA','BS Business Administration','bachelor','Business Administration',4),
('BEED','Bachelor of Elementary Education','bachelor','Teacher Education',4),
('BSED','Bachelor of Secondary Education','bachelor','Teacher Education',4);

-- 10.4 Campuses / School Locations (applicant proximity choice)
-- Cavite cluster (project main) + NCR/Bulacan official branches + requested Para�aque
INSERT INTO `campuses` (`code`,`name`,`address`,`city`,`province`,`is_active`) VALUES
('DASM-MAIN','Dasmari�as Main Campus','Area C, Dasmari�as','Dasmari�as','Cavite',1),
('SILANG','Silang Campus','Silang Crossing East, Silang','Silang','Cavite',1),
('GENTRI','General Trias Campus','Brgy. San Juan, General Trias','General Trias','Cavite',1),
('IMUS','Imus Campus','NIA Road, Imus','Imus','Cavite',1),
('CARMONA','Carmona Campus','Bancal, Carmona','Carmona','Cavite',1),
('MANILA','Manila Extension','Legarda St., Sampaloc','Manila','Metro Manila',1),
('FAIRVIEW','Fairview Branch','85 Commonwealth Ave, Quezon City, 1118 Metro Manila','Quezon City','Metro Manila',1),
('VALENZUELA','Valenzuela Branch','2nd flr. Gotaco Bldg 2, 32 MacArthur Highway, Valenzuela, 1440 Metro Manila','Valenzuela','Metro Manila',1),
('CALOOCAN','Caloocan Branch','357 J. Teodoro St, Cor 10th Ave, Caloocan','Caloocan','Metro Manila',1),
('MEYCAUAYAN','Meycauayan Branch','85 Requino St, Saluysoy, Meycauayan, 3023 Bulacan','Meycauayan','Bulacan',1),
('PARANAQUE-SUCAT','San Dionisio (Sucat) Campus','San Dionisio, Sucat, Para�aque City, Metro Manila','Para�aque','Metro Manila',1),
('LAS-PINAS','Las Pi�as Campus','Alabang-Zapote Road, Almanza Uno, Las Pi�as City','Las Pi�as','Metro Manila',1),
('BACOOR','Bacoor Campus','Molino Road, Bacoor','Bacoor','Cavite',1);

-- 10.5 Current academic year
INSERT INTO `academic_years` (`school_year`,`start_date`,`end_date`,`is_current`) VALUES
('2026-2027','2026-06-08','2027-05-31',1);

-- 10.6 Fee types
INSERT INTO `fee_types` (`name`,`description`,`is_refundable`) VALUES
('Tuition Fee','Per-unit / per-semester tuition charge',0),
('Registration Fee','One-time enrollment registration charge',0),
('Miscellaneous Fee','Library, athletic, cultural, medical, etc.',0),
('Laboratory Fee','Computer / science lab usage',0),
('ID Fee','Student ID production',0),
('Books & Materials','Instructional materials',0),
('Graduation Fee','Graduation ceremonies and documents',0);

-- 10.7 Sample fee structure rows (SHS tuition, current SY) - adjust amounts
INSERT INTO `fee_structures`
  (`fee_type_id`,`grade_level_id`,`academic_year_id`,`semester`,`amount`,`payment_frequency`,`is_active`)
SELECT
  (SELECT `id` FROM `fee_types` WHERE `name`='Tuition Fee'),
  gl.`id`,
  (SELECT `id` FROM `academic_years` WHERE `is_current`=1),
  NULL,
  18500.00,
  'per_semester',
  1
FROM `grade_levels` gl WHERE gl.`code` IN ('G11','G12');

INSERT INTO `fee_structures`
  (`fee_type_id`,`grade_level_id`,`academic_year_id`,`semester`,`amount`,`payment_frequency`,`is_active`)
SELECT
  (SELECT `id` FROM `fee_types` WHERE `name`='Registration Fee'),
  gl.`id`,
  (SELECT `id` FROM `academic_years` WHERE `is_current`=1),
  NULL,
  500.00,
  'one_time',
  1
FROM `grade_levels` gl;

-- 10.8 System settings (SMTP + PayRex placeholders; fill via Admin panel or config)
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('school_name','Datamex College of Saint Adeline','general'),
('school_address','Dasmarinas, Cavite, Philippines','general'),
('registrar_email','registrar@datamex.edu.ph','general'),
('support_email','support@datamex.edu.ph','general'),
('base_url','https://eduportal.example.com','general'),
('timezone','Asia/Manila','general'),
('currency','PHP','general'),
('enrollment_open','1','enrollment'),
('late_payment_penalty_pct','5','finance'),
('session_idle_timeout_minutes','30','security'),
('smtp_host','','smtp'),
('smtp_port','587','smtp'),
('smtp_encryption','tls','smtp'),
('smtp_username','','smtp'),
('smtp_password','','smtp'),
('mail_from_name','EduPortal - Datamex College','smtp'),
('payrex_mode','test','payrex'),
('payrex_public_key','','payrex'),
('payrex_secret_key','','payrex'),
('payrex_webhook_secret','','payrex');

-- =============================================================================
-- NOTE : The default administrator account is created by install/setup-admin.php
--        (writes a proper password_hash() and is_email_verified = 1).
--        Delete the /install directory immediately after running it.
-- =============================================================================
