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
-- HOSTINGER IMPORT: DB already selected in phpMyAdmin, so no CREATE DATABASE / USE
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
  `name`       VARCHAR(100) NOT NULL COMMENT 'Dasmariñas Main Campus',
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
-- Cavite cluster (project main) + NCR/Bulacan official branches + requested Parañaque
INSERT INTO `campuses` (`code`,`name`,`address`,`city`,`province`,`is_active`) VALUES
('DASM-MAIN','Dasmariñas Main Campus','Area C, Dasmariñas','Dasmariñas','Cavite',1),
('SILANG','Silang Campus','Silang Crossing East, Silang','Silang','Cavite',1),
('GENTRI','General Trias Campus','Brgy. San Juan, General Trias','General Trias','Cavite',1),
('IMUS','Imus Campus','NIA Road, Imus','Imus','Cavite',1),
('CARMONA','Carmona Campus','Bancal, Carmona','Carmona','Cavite',1),
('MANILA','Manila Extension','Legarda St., Sampaloc','Manila','Metro Manila',1),
('FAIRVIEW','Fairview Branch','85 Commonwealth Ave, Quezon City, 1118 Metro Manila','Quezon City','Metro Manila',1),
('VALENZUELA','Valenzuela Branch','2nd flr. Gotaco Bldg 2, 32 MacArthur Highway, Valenzuela, 1440 Metro Manila','Valenzuela','Metro Manila',1),
('CALOOCAN','Caloocan Branch','357 J. Teodoro St, Cor 10th Ave, Caloocan','Caloocan','Metro Manila',1),
('MEYCAUAYAN','Meycauayan Branch','85 Requino St, Saluysoy, Meycauayan, 3023 Bulacan','Meycauayan','Bulacan',1),
('PARANAQUE-SUCAT','San Dionisio (Sucat) Campus','San Dionisio, Sucat, Parañaque City, Metro Manila','Parañaque','Metro Manila',1),
('LAS-PINAS','Las Piñas Campus','Alabang-Zapote Road, Almanza Uno, Las Piñas City','Las Piñas','Metro Manila',1),
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
-- =============================================================================
-- EduPortal : Secure Teacher Registration (Invite Code System)
-- Datamex College of Saint Adeline
-- File     : database/schema_teacher_invites.sql
-- Version  : 1.0.0
-- Requires : schema.sql must be imported first
-- -----------------------------------------------------------------------------
-- PURPOSE:
--   Prevent open self-registration as teacher. Teacher accounts can ONLY be
--   created via (A) admin-generated single-use invite code or (B) direct
--   admin creation in /admin/teachers. No plaintext invite is stored.
-- SECURITY:
--   - code_hash = password_hash(plain_code) (bcrypt, unique per invite)
--   - code_preview = last 4 chars for admin UI (e.g., ...3M2Q)
--   - Single-use, 7-day expiry, audit + rate limiting
--   - Admin-only generation, CSRF + Auth::requireRole('admin')
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `teacher_invites`;

CREATE TABLE IF NOT EXISTS `teacher_invites` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`        VARCHAR(255)    NOT NULL COMMENT 'password_hash of plain invite code (e.g., TCH-XXXX-XXXX)',
  `code_preview`     VARCHAR(8)      NOT NULL COMMENT 'last 4 chars for admin display, e.g., 3M2Q',
  `email_hint`       VARCHAR(191)    DEFAULT NULL COMMENT 'optional: restrict to this email (exact match)',
  `department`       VARCHAR(100)    DEFAULT NULL COMMENT 'optional default department for teacher',
  `created_by`       BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ users.id (admin who generated)',
  `expires_at`       DATETIME        NOT NULL COMMENT 'invite expiry, default 7 days',
  `used_at`          DATETIME        DEFAULT NULL,
  `used_by`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK â†’ users.id (teacher who redeemed)',
  `is_used`          TINYINT(1)      NOT NULL DEFAULT 0,
  `attempts`         INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'failed verification attempts (rate limiting)',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invite_creator` (`created_by`),
  KEY `idx_invite_used_by` (`used_by`),
  KEY `idx_invite_expiry` (`expires_at`),
  KEY `idx_invite_used` (`is_used`),
  CONSTRAINT `fk_invite_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_used_by` FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Single-use hashed invite codes for secure teacher registration';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Optional seed: none. Admin generates via UI /admin/teachers/invite.
-- To manually seed a test code (for dev only), run:
--   INSERT INTO teacher_invites (code_hash, code_preview, created_by, expires_at) VALUES (password_hash('TCH-DEMO-CODE', PASSWORD_DEFAULT), 'CODE', 1, DATE_ADD(NOW(), INTERVAL 7 DAY));
-- =============================================================================
-- EduPortal : Google Classroom-style Subject Invite (Teacher â†’ Student)
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
  `teacher_id`       BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ teachers.id',
  `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'FK â†’ academic_years.id',
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
  `classroom_id` INT UNSIGNED    NOT NULL COMMENT 'FK â†’ subject_classrooms.id',
  `student_id`   BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ students.id',
  `joined_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member` (`classroom_id`,`student_id`),
  KEY `idx_member_student` (`student_id`),
  CONSTRAINT `fk_member_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_member_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Google Classroom-style joins';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
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
  `classroom_id` INT UNSIGNED    NOT NULL COMMENT 'FK â†’ subject_classrooms.id',
  `title`        VARCHAR(150)    NOT NULL COMMENT 'e.g., Prelim Project',
  `instructions` TEXT            NULL,
  `due_at`       DATETIME        NULL,
  `posted_by`    BIGINT UNSIGNED NULL COMMENT 'FK â†’ users.id (teacher)',
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
  `task_id`         BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ classroom_tasks.id',
  `student_id`      BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ students.id',
  `file_path`       VARCHAR(255)    NULL COMMENT 'stored path under /uploads/submissions',
  `original_filename` VARCHAR(191)  NULL,
  `mime_type`       VARCHAR(100)    NULL,
  `file_size_bytes` INT UNSIGNED    NULL,
  `note`            TEXT            NULL COMMENT 'student submission note',
  `status`          ENUM('submitted','late','graded','returned') NOT NULL DEFAULT 'submitted',
  `score`           DECIMAL(6,2)    NULL,
  `feedback`        TEXT            NULL,
  `graded_by`       BIGINT UNSIGNED NULL COMMENT 'FK â†’ users.id (teacher)',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Student uploads for Google Classroom tasks (click task â†’ upload)';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
-- =============================================================================
-- EduPortal : Exam Permit Validation Workflow (Multi-stage 3-tier)
-- Datamex College of Saint Adeline
-- File     : database/schema_exam_permits.sql
-- Version  : 1.1.0
-- Engine   : InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Requires : Parent schema.sql must be imported first (students, users, academic_years, payments, etc.)
-- -----------------------------------------------------------------------------
-- MODULE : EXAM PERMIT PIPELINE across 4 periods (Prelim, Midterm, Prefinal, Finals)
--  Tier 1 Accounting  â†’ verifies tuition/payment (PayRex/receipt)   pending_accounting â†’ approved_accounting
--  Tier 2 Registrar   â†’ verifies academic clearance                 approved_accounting â†’ approved_registrar
--  Tier 3 Teacher     â†’ inspects & marks Fully Approved             approved_registrar  â†’ fully_approved
--
-- INSTALL:
--   Local  : mysql -u root eduportal_db < database/schema_exam_permits.sql
--   Hostinger phpMyAdmin : Import -> select this file (no CREATE DATABASE needed)
--   The script is idempotent-safe via IF NOT EXISTS + IGNORE where possible.
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Exam Permits (one row per student Ã— academic year Ã— exam period)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `permit_approvals`;
DROP TABLE IF EXISTS `exam_permits`;

CREATE TABLE IF NOT EXISTS `exam_permits` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `permit_no`          VARCHAR(20)     NOT NULL COMMENT 'e.g. EP-2026-PREL-0001',
  `student_id`         BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ students.id',
  `academic_year_id`   INT UNSIGNED    NOT NULL COMMENT 'FK â†’ academic_years.id',
  `exam_period`        ENUM('Prelim','Midterm','Prefinal','Finals') NOT NULL,
  -- Sequential pipeline status
  `status`             ENUM('pending_accounting','approved_accounting','approved_registrar','fully_approved','rejected')
                       NOT NULL DEFAULT 'pending_accounting' COMMENT '3-tier pipeline position',
  -- Financial prerequisite snapshot at request time
  `financial_status`   ENUM('unpaid','installment_paid','fully_paid') NOT NULL DEFAULT 'unpaid'
                       COMMENT 'Derived from tuition balance / PayRex validation',
  `payment_validated`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 when payment prerequisite satisfied',
  `payment_ref`        VARCHAR(100)    DEFAULT NULL COMMENT 'PayRex intent id or official receipt no.',
  `receipt_no`         VARCHAR(30)     DEFAULT NULL COMMENT 'FK â†’ receipts.receipt_no if generated',
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
  `permit_id`          BIGINT UNSIGNED NOT NULL COMMENT 'FK â†’ exam_permits.id',
  `stage`              ENUM('accounting','registrar','teacher') NOT NULL COMMENT 'Pipeline tier',
  `status`             ENUM('approved','rejected') NOT NULL DEFAULT 'approved',
  `approver_user_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK â†’ users.id (null = system)',
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
-- EduPortal Live Patch - fixes missing columns/tables causing 500 on Hostinger
-- Import this AFTER the main FULL_u788443931_eduportal.sql if live already imported
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
-- EduPortal: Digital Clearance Cycle (Semester â†’ Exams â†’ Clearance â†’ Next Enrollment)
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
