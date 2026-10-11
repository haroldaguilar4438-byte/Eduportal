-- =============================================================================
-- EduPortal : Web-Based Integrated School Management System
-- Datamex College of Saint Adeline
-- -----------------------------------------------------------------------------
-- File     : database/schema.sql
-- Version  : 2.0.0 (consolidated — replaces v1.0.0 + all patch_*.sql files)
-- Engine   : InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Target   : Hostinger Shared Hosting (MySQL 8.x / MariaDB 10.x, phpMyAdmin)
-- Covers   : auth + 6 roles, academic structure, people, enrollment, scheduling,
--            term grade sheets (draft→finalized), weighted grade engine,
--            grade scales/conversions, finance (PayRex), permits (3-tier),
--            clearances (+financial), academic documents, notifications, audit.
--
-- INSTALL ON HOSTINGER
--   1. hPanel -> Websites -> Manage -> Databases -> create DB + user.
--   2. hPanel -> phpMyAdmin -> select the new DB -> Import -> this file.
--      (Skip the CREATE DATABASE / USE lines below when importing there.)
--   3. Visit /install/setup-admin.php once to create the real admin account
--      (proper password_hash + verified flag), then DELETE the /install folder.
--
-- SECURITY NOTES
--   - All money columns: DECIMAL(10,2). Point-grade columns: DECIMAL(4,2).
--   - Tokens are 64-char hex produced by bin2hex(random_bytes(32)).
--   - otp_code_hash stores password_hash() of the OTP, never the plain OTP.
--   - Never store PayRex secret keys in the DB; keep them in app/config.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. DATABASE (local-dev convenience; hPanel already creates it in production)
-- -----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `eduportal_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `eduportal_db`;

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `teachers`;
DROP TABLE IF EXISTS `teacher_invites`;
DROP TABLE IF EXISTS `teacher_evaluations`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `subject_classrooms`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `student_class_registrations`;
DROP TABLE IF EXISTS `student_charges`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `receipts`;
DROP TABLE IF EXISTS `programs`;
DROP TABLE IF EXISTS `permit_approvals`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `payment_webhook_logs`;
DROP TABLE IF EXISTS `payment_allocations`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `guardians`;
DROP TABLE IF EXISTS `guardian_students`;
DROP TABLE IF EXISTS `grades`;
DROP TABLE IF EXISTS `grade_weights`;
DROP TABLE IF EXISTS `grade_sheets`;
DROP TABLE IF EXISTS `grade_sheet_rows`;
DROP TABLE IF EXISTS `grade_sheet_logs`;
DROP TABLE IF EXISTS `grade_scales`;
DROP TABLE IF EXISTS `grade_reviews`;
DROP TABLE IF EXISTS `grade_periods`;
DROP TABLE IF EXISTS `grade_levels`;
DROP TABLE IF EXISTS `grade_conversions`;
DROP TABLE IF EXISTS `fee_types`;
DROP TABLE IF EXISTS `fee_structures`;
DROP TABLE IF EXISTS `exam_permits`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `evaluation_periods`;
DROP TABLE IF EXISTS `enrollment_documents`;
DROP TABLE IF EXISTS `enrollment_applications`;
DROP TABLE IF EXISTS `email_log`;
DROP TABLE IF EXISTS `clearances`;
DROP TABLE IF EXISTS `clearance_status_logs`;
DROP TABLE IF EXISTS `clearance_items`;
DROP TABLE IF EXISTS `classroom_tasks`;
DROP TABLE IF EXISTS `classroom_task_submissions`;
DROP TABLE IF EXISTS `class_join_requests`;
DROP TABLE IF EXISTS `classroom_members`;
DROP TABLE IF EXISTS `class_offerings`;
DROP TABLE IF EXISTS `class_offering_schedules`;
DROP TABLE IF EXISTS `chat_history`;
DROP TABLE IF EXISTS `campuses`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `at_risk_flags`;
DROP TABLE IF EXISTS `assignments`;
DROP TABLE IF EXISTS `assignment_submissions`;
DROP TABLE IF EXISTS `assessment_categories`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `academic_years`;
DROP TABLE IF EXISTS `academic_documents`;

-- ============================================================================
-- SECTION 1-9 : TABLE DEFINITIONS (58 tables, FKs inline)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `academic_periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `band` enum('basic','shs','college') NOT NULL COMMENT 'basic=K-G10, shs=G11-G12, college=1st-4th Year',
  `code` varchar(20) NOT NULL COMMENT 'Q1..Q4, T1..T3, 1st, 2nd, Summer, or custom',
  `name` varchar(40) NOT NULL COMMENT 'display label, e.g. Quarter 1',
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_band_code` (`band`,`code`),
  KEY `idx_band_active` (`band`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `academic_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doc_no` varchar(30) NOT NULL COMMENT 'e.g. DOC-2026-000123',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> students.id',
  `academic_year_id` int(10) unsigned DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL COMMENT 'effective academic period (semester / quarter / term)',
  `period_code` varchar(20) DEFAULT NULL COMMENT 'band-correct period (basic-ed quarters/terms)',
  `doc_type` enum('transcript','enrollment_cert','grades_cert','history','grade_slip','registration_form','grade_equivalency','other') NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','released','rejected','ready','cancelled') NOT NULL DEFAULT 'pending',
  `requested_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (student)',
  `processed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `released_at` datetime DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_no` (`doc_no`),
  KEY `idx_docs_student` (`student_id`),
  KEY `idx_docs_status` (`status`),
  KEY `idx_docs_type` (`doc_type`),
  KEY `fk_docs_requester` (`requested_by`),
  KEY `fk_docs_processor` (`processed_by`),
  CONSTRAINT `fk_docs_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_docs_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_docs_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `academic_years` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_year` varchar(9) NOT NULL COMMENT 'YYYY-YYYY',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'enforce exactly one via app logic',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_years_sy` (`school_year`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `content` mediumtext NOT NULL,
  `audience` enum('everyone','students','parents','teachers','staff','class') NOT NULL DEFAULT 'everyone',
  `section_id` int(10) unsigned DEFAULT NULL COMMENT 'required when audience = class',
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `publish_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ann_audience` (`audience`,`section_id`),
  KEY `fk_ann_section` (`section_id`),
  KEY `fk_ann_poster` (`posted_by`),
  CONSTRAINT `fk_ann_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ann_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `assessment_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL COMMENT 'activities, quizzes, assignments, projects, exams',
  `name` varchar(80) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assess_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `assignment_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `submission_note` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL COMMENT 'path under /uploads/submissions',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('submitted','late','missing','graded','returned') NOT NULL DEFAULT 'submitted',
  `score` decimal(6,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` bigint(20) unsigned DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_once` (`assignment_id`,`student_id`),
  KEY `fk_sub_grader` (`graded_by`),
  KEY `fk_sub_student` (`student_id`),
  CONSTRAINT `fk_sub_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_grader` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `instructions` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL COMMENT 'teacher reference file',
  `max_score` decimal(6,2) NOT NULL DEFAULT 100.00,
  `due_at` datetime DEFAULT NULL,
  `allow_late` tinyint(1) NOT NULL DEFAULT 1,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assignments_offering` (`class_offering_id`),
  KEY `fk_assignments_poster` (`posted_by`),
  CONSTRAINT `fk_assignments_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `at_risk_flags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `risk_level` enum('low','moderate','high') NOT NULL,
  `risk_score` decimal(5,2) DEFAULT NULL COMMENT '0-100 composite score',
  `contributing_factors_json` longtext DEFAULT NULL COMMENT 'e.g. {"avg_grade":72.5,"failing_subjects":2,"unpaid_balance":15000}',
  `recommendations` text DEFAULT NULL COMMENT 'advising / tutorial / payment plan',
  `detection_source` enum('rule_engine','ml_batch','manual') NOT NULL DEFAULT 'rule_engine',
  `flagged_by` bigint(20) unsigned DEFAULT NULL COMMENT 'set when detection_source = manual',
  `status` enum('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_risk_student_ay` (`student_id`,`academic_year_id`),
  KEY `idx_risk_status_level` (`status`,`risk_level`),
  KEY `fk_risk_ay` (`academic_year_id`),
  KEY `fk_risk_flagger` (`flagged_by`),
  KEY `fk_risk_resolver` (`resolved_by`),
  CONSTRAINT `fk_risk_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_flagger` FOREIGN KEY (`flagged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_risk_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL COMMENT 'login, approve_enrollment, post_grade...',
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_values_json` longtext DEFAULT NULL,
  `new_values_json` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor_time` (`actor_user_id`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `campuses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT 'DASM-MAIN, SILANG...',
  `name` varchar(100) NOT NULL COMMENT 'Dasmariñas Main Campus',
  `address` varchar(255) NOT NULL COMMENT 'Brgy., City, Province',
  `city` varchar(80) DEFAULT NULL,
  `province` varchar(80) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campuses_code` (`code`),
  KEY `idx_campuses_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `chat_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `sender` enum('user','ai') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;;;

CREATE TABLE IF NOT EXISTS `class_offering_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `day_of_week` enum('MON','TUE','WED','THU','FRI','SAT','SUN') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sched_offering` (`class_offering_id`),
  KEY `idx_sched_day_time` (`day_of_week`,`start_time`,`end_time`),
  CONSTRAINT `fk_sched_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_sched_time` CHECK (`end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `class_offerings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` int(10) unsigned NOT NULL,
  `section_id` int(10) unsigned NOT NULL,
  `teacher_id` bigint(20) unsigned DEFAULT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL COMMENT 'SHS/college terms',
  `room` varchar(50) DEFAULT NULL,
  `schedule_note` varchar(255) DEFAULT NULL COMMENT 'MWF 08:00-09:30 etc.',
  `max_students` smallint(5) unsigned NOT NULL DEFAULT 45,
  `status` enum('open','closed','completed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offering` (`subject_id`,`section_id`,`academic_year_id`,`semester`),
  KEY `idx_offering_teacher` (`teacher_id`),
  KEY `fk_offering_ay` (`academic_year_id`),
  KEY `fk_offering_section` (`section_id`),
  CONSTRAINT `fk_offering_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_offering_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `class_join_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `classroom_id` int(10) unsigned NOT NULL COMMENT 'FK -> subject_classrooms.id',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> students.id (the child joining)',
  `requested_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (student self or acting parent)',
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `teacher_remarks` varchar(500) DEFAULT NULL COMMENT 'decline reason, student-visible',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (teacher/admin decider)',
  `reviewed_at` datetime DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_join_request_once` (`classroom_id`,`student_id`),
  KEY `idx_join_req_status` (`status`),
  KEY `idx_join_req_student` (`student_id`),
  CONSTRAINT `fk_join_req_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_join_req_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_join_req_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_join_req_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-verified subject join requests (code-free joining)';;;

CREATE TABLE IF NOT EXISTS `classroom_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `classroom_id` int(10) unsigned NOT NULL COMMENT 'FK → subject_classrooms.id',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK → students.id',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member` (`classroom_id`,`student_id`),
  KEY `idx_member_student` (`student_id`),
  CONSTRAINT `fk_member_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_member_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Google Classroom-style joins';;;

CREATE TABLE IF NOT EXISTS `classroom_task_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint(20) unsigned NOT NULL COMMENT 'FK → classroom_tasks.id',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK → students.id',
  `file_path` varchar(255) DEFAULT NULL COMMENT 'stored path under /uploads/submissions',
  `original_filename` varchar(191) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` int(10) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL COMMENT 'student submission note',
  `status` enum('submitted','late','graded','returned') NOT NULL DEFAULT 'submitted',
  `review_status` enum('draft','submitted','reviewed','approved','returned') NOT NULL DEFAULT 'submitted',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(500) DEFAULT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → users.id (teacher)',
  `graded_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_student` (`task_id`,`student_id`),
  KEY `idx_submission_student` (`student_id`),
  KEY `idx_submission_task` (`task_id`),
  KEY `fk_cts_grader` (`graded_by`),
  KEY `idx_cts_review` (`review_status`),
  CONSTRAINT `fk_cts_grader` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cts_task` FOREIGN KEY (`task_id`) REFERENCES `classroom_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Student uploads for Google Classroom tasks (click task → upload)';;;

CREATE TABLE IF NOT EXISTS `classroom_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `classroom_id` int(10) unsigned NOT NULL COMMENT 'FK → subject_classrooms.id',
  `title` varchar(150) NOT NULL COMMENT 'e.g., Prelim Project',
  `instructions` text DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL COMMENT 'FK -> assessment_categories.id',
  `max_score` decimal(6,2) NOT NULL DEFAULT 100.00,
  `period_id` int(10) unsigned DEFAULT NULL COMMENT 'FK -> grade_periods.id',
  `due_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → users.id (teacher)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_classroom` (`classroom_id`),
  KEY `idx_task_poster` (`posted_by`),
  KEY `idx_task_due` (`due_at`),
  KEY `fk_task_category` (`category_id`),
  KEY `fk_task_period` (`period_id`),
  CONSTRAINT `fk_task_category` FOREIGN KEY (`category_id`) REFERENCES `assessment_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_task_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_period` FOREIGN KEY (`period_id`) REFERENCES `grade_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_task_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-posted activities for subject classrooms (Google Classroom)';;;

CREATE TABLE IF NOT EXISTS `clearance_items` (
  `id` bigint(20) unsigned NOT NULL,
  `clearance_id` bigint(20) unsigned NOT NULL,
  `class_offering_id` int(10) unsigned DEFAULT NULL COMMENT 'FK class_offerings.id (legacy; NULL when subject-classroom based)',
  `subject_classroom_id` int(10) unsigned DEFAULT NULL COMMENT 'FK -> subject_classrooms.id',
  `teacher_id` bigint(20) unsigned NOT NULL COMMENT 'FK teachers.id',
  `is_signed` tinyint(1) NOT NULL DEFAULT 0,
  `signed_at` datetime DEFAULT NULL,
  UNIQUE KEY `uq_item_subject` (`clearance_id`,`subject_classroom_id`),
  KEY `fk_item_subject_classroom` (`subject_classroom_id`),
  CONSTRAINT `fk_item_subject_classroom` FOREIGN KEY (`subject_classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-subject clearance sign';;;

CREATE TABLE IF NOT EXISTS `clearance_status_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clearance_id` bigint(20) unsigned NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_csl_clearance` (`clearance_id`),
  KEY `fk_csl_actor` (`actor_user_id`),
  CONSTRAINT `fk_csl_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `clearances` (
  `id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester` varchar(20) NOT NULL DEFAULT '1st' COMMENT 'effective academic period (semester / quarter / term)',
  `status` enum('pending','signed','issued','used') NOT NULL DEFAULT 'pending',
  `financial_status` enum('pending','for_review','cleared','not_cleared','on_hold') NOT NULL DEFAULT 'pending',
  `financial_reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `financial_reviewed_at` datetime DEFAULT NULL,
  `financial_remarks` varchar(500) DEFAULT NULL,
  `qr_token` varchar(64) DEFAULT NULL COMMENT '64-hex for QR verify',
  `pdf_path` varchar(255) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_clearance_financial` (`financial_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Digital clearance per semester';;;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_email` varchar(191) NOT NULL,
  `subject` varchar(191) NOT NULL,
  `template` varchar(60) DEFAULT NULL COMMENT 'verify_account, reset_password, receipt...',
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `error_message` varchar(500) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_recipient` (`recipient_email`),
  KEY `fk_email_user` (`user_id`),
  CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `enrollment_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_no` varchar(20) NOT NULL COMMENT 'e.g. EN-2026-000123',
  `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'applicant portal account',
  `student_id` bigint(20) unsigned DEFAULT NULL COMMENT 'linked after approval',
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `amount_due` decimal(10,2) NOT NULL DEFAULT 1500.00,
  `academic_year_id` int(10) unsigned NOT NULL,
  `grade_level_applied_id` smallint(5) unsigned NOT NULL,
  `program_id` int(10) unsigned DEFAULT NULL COMMENT 'college applicants only',
  `semester` enum('1st','2nd','Summer') DEFAULT NULL,
  `period_code` varchar(20) DEFAULT NULL COMMENT 'band-correct period (basic-ed quarters/terms; NULL = use semester)',
  `new_or_returning` enum('new','returning','transferee') NOT NULL DEFAULT 'new',
  `returning_student_no` varchar(20) DEFAULT NULL COMMENT 'required when new_or_returning=returning, e.g. 2023022',
  `preferred_campus_id` int(10) unsigned DEFAULT NULL COMMENT 'chosen school location / campus',
  `previous_school` varchar(150) DEFAULT NULL,
  `last_grade_completed` varchar(50) DEFAULT NULL,
  `status` enum('pending','accounting_approved','awaiting_payment','approved','rejected','action_required') NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL COMMENT 'reason for action_required/rejected',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enroll_app_no` (`application_no`),
  KEY `idx_enroll_status` (`status`),
  KEY `idx_enroll_user` (`user_id`),
  KEY `idx_enroll_campus` (`preferred_campus_id`),
  KEY `fk_enroll_program` (`program_id`),
  KEY `fk_enroll_reviewer` (`reviewed_by`),
  KEY `fk_enroll_student` (`student_id`),
  KEY `fk_enroll_ay` (`academic_year_id`),
  KEY `fk_enroll_level` (`grade_level_applied_id`),
  KEY `idx_enroll_payment` (`payment_id`),
  CONSTRAINT `fk_enroll_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_campus` FOREIGN KEY (`preferred_campus_id`) REFERENCES `campuses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_level` FOREIGN KEY (`grade_level_applied_id`) REFERENCES `grade_levels` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `enrollment_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `doc_type` enum('form137','form138_report_card','birth_certificate','good_moral','id_photo','others') NOT NULL,
  `original_filename` varchar(191) NOT NULL,
  `stored_path` varchar(255) NOT NULL COMMENT 'relative under /uploads/documents',
  `mime_type` varchar(100) NOT NULL,
  `file_size_bytes` int(10) unsigned NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_edocs_application` (`application_id`),
  KEY `fk_edocs_verifier` (`verified_by`),
  CONSTRAINT `fk_edocs_application` FOREIGN KEY (`application_id`) REFERENCES `enrollment_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_edocs_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `evaluation_periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(10) unsigned NOT NULL,
  `term` enum('1st','2nd','Summer') NOT NULL DEFAULT '1st',
  `period` enum('Prelim','Midterm','Prefinal','Finals') NOT NULL DEFAULT 'Midterm',
  `status` enum('open','closed') NOT NULL DEFAULT 'closed',
  `opens_at` datetime DEFAULT NULL,
  `closes_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eval_period` (`academic_year_id`,`term`,`period`),
  KEY `idx_eval_period_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `venue` varchar(150) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `audience` enum('everyone','students','parents','teachers','staff') NOT NULL DEFAULT 'everyone',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_events_start` (`start_at`),
  KEY `fk_events_creator` (`created_by`),
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `exam_permits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permit_no` varchar(20) NOT NULL COMMENT 'e.g. EP-2026-PREL-0001',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK → students.id',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'FK → academic_years.id',
  `exam_period` enum('Prelim','Midterm','Prefinal','Finals') NOT NULL,
  `status` enum('pending_accounting','approved_accounting','approved_registrar','fully_approved','rejected') NOT NULL DEFAULT 'pending_accounting' COMMENT '3-tier pipeline position',
  `financial_status` enum('unpaid','installment_paid','fully_paid') NOT NULL DEFAULT 'unpaid' COMMENT 'Derived from tuition balance / PayRex validation',
  `payment_validated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 when payment prerequisite satisfied',
  `payment_ref` varchar(100) DEFAULT NULL COMMENT 'PayRex intent id or official receipt no.',
  `receipt_no` varchar(30) DEFAULT NULL COMMENT 'FK → receipts.receipt_no if generated',
  `balance_at_request` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Outstanding balance snapshot',
  `qr_token` varchar(64) DEFAULT NULL COMMENT 'SHA token for QR verification (random_bytes)',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rejected_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_no` (`permit_no`),
  UNIQUE KEY `uq_student_period` (`student_id`,`academic_year_id`,`exam_period`),
  KEY `idx_exam_permits_student` (`student_id`),
  KEY `idx_exam_permits_status` (`status`),
  KEY `idx_exam_permits_period` (`exam_period`),
  KEY `idx_exam_permits_ay` (`academic_year_id`),
  CONSTRAINT `fk_permit_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_permit_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-stage exam permit validation across 4 periods';;;

CREATE TABLE IF NOT EXISTS `fee_structures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fee_type_id` smallint(5) unsigned NOT NULL,
  `grade_level_id` smallint(5) unsigned DEFAULT NULL,
  `program_id` int(10) unsigned DEFAULT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_frequency` enum('one_time','per_semester','quarterly','monthly') NOT NULL DEFAULT 'per_semester',
  `effective_from` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_fstruct_level` (`grade_level_id`,`academic_year_id`),
  KEY `fk_fstruct_program` (`program_id`),
  KEY `fk_fstruct_type` (`fee_type_id`),
  KEY `fk_fstruct_ay` (`academic_year_id`),
  CONSTRAINT `fk_fstruct_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fstruct_type` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `fee_types` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Tuition, Miscellaneous, ...',
  `description` varchar(255) DEFAULT NULL,
  `is_refundable` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fee_types_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_conversions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `min_pct` decimal(5,2) NOT NULL,
  `max_pct` decimal(5,2) NOT NULL,
  `point` decimal(4,2) NOT NULL COMMENT 'Point grade, e.g. 1.25',
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_levels` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `category` enum('kindergarten','elementary','junior_high','senior_high','college') NOT NULL,
  `code` varchar(10) NOT NULL COMMENT 'K, G1..G12, C1..C4',
  `name` varchar(60) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_levels_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT 'Prelim, Midterm, Prefinal, Finals',
  `name` varchar(60) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_period_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> classroom_task_submissions.id',
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `action` varchar(40) NOT NULL COMMENT 'submitted|reviewed|approved|returned|correction',
  `remarks` varchar(500) DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gr_submission` (`submission_id`),
  KEY `idx_gr_actor` (`actor_user_id`),
  CONSTRAINT `fk_gr_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gr_submission` FOREIGN KEY (`submission_id`) REFERENCES `classroom_task_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_scales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `min_grade` decimal(4,2) NOT NULL,
  `max_grade` decimal(4,2) NOT NULL,
  `equivalent` varchar(20) NOT NULL COMMENT 'Label shown on slip, e.g. Excellent',
  `remarks` varchar(60) NOT NULL DEFAULT 'PASSED',
  `is_passing` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_sheet_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sheet_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> grade_sheets.id',
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `action` varchar(40) NOT NULL COMMENT 'created|saved|submitted|under_review|returned|approved|finalized|resubmitted|correction',
  `remarks` varchar(500) DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gsl_sheet` (`sheet_id`),
  KEY `fk_gsl_actor` (`actor_user_id`),
  CONSTRAINT `fk_gsl_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gsl_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_sheet_rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sheet_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> grade_sheets.id',
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> students.id',
  `grade` decimal(4,2) DEFAULT NULL COMMENT 'Term grade, school point scale (e.g. 1.00-5.00)',
  `computed_grade` decimal(4,2) DEFAULT NULL COMMENT 'Engine-computed point grade snapshot',
  `source` enum('manual','auto') NOT NULL DEFAULT 'manual',
  `remarks` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sheet_student` (`sheet_id`,`student_id`),
  KEY `idx_gsr_student` (`student_id`),
  CONSTRAINT `fk_gsr_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gsr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_sheets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `classroom_id` int(10) unsigned NOT NULL COMMENT 'FK -> subject_classrooms.id',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'FK -> academic_years.id',
  `semester` varchar(20) DEFAULT NULL COMMENT 'effective academic period (semester / quarter / term)',
  `period_id` int(10) unsigned NOT NULL COMMENT 'FK -> grade_periods.id',
  `status` enum('draft','submitted','under_review','returned','approved','finalized') NOT NULL DEFAULT 'draft' COMMENT 'Teacher draft → registrar pipeline → locked record',
  `submitted_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (teacher)',
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(500) DEFAULT NULL,
  `finalized_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (registrar)',
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sheet_class_period` (`classroom_id`,`period_id`),
  KEY `idx_sheet_status` (`status`),
  KEY `idx_sheet_teacher` (`submitted_by`),
  KEY `fk_sheet_ay` (`academic_year_id`),
  KEY `fk_sheet_period` (`period_id`),
  KEY `fk_sheet_reviewer` (`reviewed_by`),
  KEY `fk_sheet_finalizer` (`finalized_by`),
  CONSTRAINT `fk_sheet_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_finalizer` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_period` FOREIGN KEY (`period_id`) REFERENCES `grade_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sheet_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grade_weights` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `classroom_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = global default for all subjects',
  `category_id` int(10) unsigned NOT NULL COMMENT 'FK -> assessment_categories.id',
  `weight_pct` decimal(5,2) NOT NULL COMMENT 'e.g. 20.00 = 20%',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weight_scope` (`classroom_id`,`category_id`),
  KEY `fk_weight_category` (`category_id`),
  CONSTRAINT `fk_weight_category` FOREIGN KEY (`category_id`) REFERENCES `assessment_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_weight_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `grades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `class_offering_id` int(10) unsigned NOT NULL,
  `grading_period` enum('Q1','Q2','Q3','Q4','MIDTERM','FINAL') NOT NULL,
  `grade` decimal(5,2) DEFAULT NULL COMMENT 'raw period grade',
  `final_grade` decimal(5,2) DEFAULT NULL COMMENT 'term average once all periods encoded',
  `gpa_points` decimal(4,2) DEFAULT NULL COMMENT 'college GPA equivalent',
  `is_released` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'release gate for student/parent view',
  `remarks` varchar(255) DEFAULT NULL,
  `encoded_by` bigint(20) unsigned DEFAULT NULL,
  `released_by` bigint(20) unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_row` (`student_id`,`class_offering_id`,`grading_period`),
  KEY `idx_grades_offering` (`class_offering_id`),
  KEY `idx_grades_release` (`is_released`),
  KEY `fk_grades_releaser` (`released_by`),
  KEY `fk_grades_encoder` (`encoded_by`),
  CONSTRAINT `fk_grades_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_releaser` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `guardian_students` (
  `guardian_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `relationship` enum('mother','father','legal_guardian','spouse','other') NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`guardian_id`,`student_id`),
  KEY `idx_gs_student` (`student_id`),
  CONSTRAINT `fk_gs_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gs_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `guardians` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'linked parent portal login',
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_no` varchar(30) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `employer` varchar(150) DEFAULT NULL,
  `is_emergency_contact` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guardians_user` (`user_id`),
  CONSTRAINT `fk_guardians_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email_attempted` varchar(191) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `user_agent` varchar(255) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email_attempted`,`attempted_at`),
  KEY `idx_attempts_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'info' COMMENT 'info|success|warning|danger|payment|grade|task',
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `charge_id` bigint(20) unsigned NOT NULL,
  `amount_applied` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alloc_once` (`payment_id`,`charge_id`),
  KEY `fk_alloc_charge` (`charge_id`),
  CONSTRAINT `fk_alloc_charge` FOREIGN KEY (`charge_id`) REFERENCES `student_charges` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `payment_webhook_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(20) NOT NULL DEFAULT 'payrex',
  `event_id` varchar(100) DEFAULT NULL COMMENT 'we_... dedupe key',
  `event_type` varchar(60) DEFAULT NULL COMMENT 'payment_intent.succeeded etc.',
  `payload_json` longtext DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `processing_error` varchar(500) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`,`event_id`),
  KEY `idx_webhook_processed` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_ref` varchar(40) NOT NULL COMMENT 'internal ref e.g. PAY-2026-000123',
  `student_id` bigint(20) unsigned DEFAULT NULL,
  `enrollment_application_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `payment_method` varchar(30) NOT NULL COMMENT 'gcash|maya|card|qrph|grab_pay|cash|bank',
  `channel` enum('online','counter') NOT NULL DEFAULT 'online',
  `status` enum('pending','processing','paid','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending',
  `payrex_payment_intent_id` varchar(100) DEFAULT NULL COMMENT 'pi_... from PayRex API',
  `payrex_client_secret` varchar(255) DEFAULT NULL,
  `payrex_resource_ref` varchar(100) DEFAULT NULL COMMENT 'checkout/link resource id',
  `payrex_last_event_id` varchar(100) DEFAULT NULL COMMENT 'last webhook event applied (idempotency)',
  `paid_at` datetime DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL COMMENT 'raw provider payload snapshot',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_ref` (`transaction_ref`),
  KEY `idx_payments_student` (`student_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_intent` (`payrex_payment_intent_id`),
  KEY `idx_payments_paid_at` (`paid_at`),
  KEY `fk_payments_initiator` (`initiated_by`),
  KEY `idx_pay_enroll_app` (`enrollment_application_id`),
  CONSTRAINT `fk_pay_enroll_app` FOREIGN KEY (`enrollment_application_id`) REFERENCES `enrollment_applications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_initiator` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `permit_approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permit_id` bigint(20) unsigned NOT NULL COMMENT 'FK → exam_permits.id',
  `stage` enum('accounting','registrar','teacher') NOT NULL COMMENT 'Pipeline tier',
  `status` enum('approved','rejected') NOT NULL DEFAULT 'approved',
  `approver_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → users.id (null = system)',
  `remarks` text DEFAULT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_approval_permit` (`permit_id`),
  KEY `idx_approval_stage` (`stage`),
  KEY `idx_approval_actor` (`approver_user_id`),
  CONSTRAINT `fk_approval_actor` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_approval_permit` FOREIGN KEY (`permit_id`) REFERENCES `exam_permits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='3-tier approval audit for exam permits';;;

CREATE TABLE IF NOT EXISTS `programs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT 'BSIT, BSED...',
  `name` varchar(150) NOT NULL,
  `degree_type` enum('bachelor','associate','diploma','certificate') NOT NULL DEFAULT 'bachelor',
  `department` varchar(100) DEFAULT NULL,
  `duration_years` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_programs_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `receipt_no` varchar(30) NOT NULL COMMENT 'e.g. RC-2026-000456',
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `emailed_to` varchar(191) DEFAULT NULL,
  `emailed_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipts_no` (`receipt_no`),
  UNIQUE KEY `uq_receipts_payment` (`payment_id`),
  CONSTRAINT `fk_receipts_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL COMMENT 'admin | teacher | student | parent',
  `description` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `grade_level_id` smallint(5) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `adviser_id` bigint(20) unsigned DEFAULT NULL COMMENT 'teachers.id',
  `room` varchar(50) DEFAULT NULL,
  `capacity` smallint(5) unsigned NOT NULL DEFAULT 40,
  `status` enum('open','closed','archived') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_per_level_ay` (`name`,`grade_level_id`,`academic_year_id`),
  KEY `idx_sections_level` (`grade_level_id`),
  KEY `fk_sections_ay` (`academic_year_id`),
  KEY `fk_sections_adviser` (`adviser_id`),
  CONSTRAINT `fk_sections_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sections_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sections_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `student_charges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `fee_structure_id` int(10) unsigned DEFAULT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `description` varchar(191) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `charge_date` date NOT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_charges_student` (`student_id`,`academic_year_id`),
  KEY `fk_charges_fstruct` (`fee_structure_id`),
  KEY `fk_charges_poster` (`posted_by`),
  KEY `fk_charges_ay` (`academic_year_id`),
  CONSTRAINT `fk_charges_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_fstruct` FOREIGN KEY (`fee_structure_id`) REFERENCES `fee_structures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_charges_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `student_class_registrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL COMMENT 'FK -> students.id (server-side resolved, never from browser)',
  `class_offering_id` int(10) unsigned NOT NULL COMMENT 'FK -> class_offerings.id',
  `status` enum('registered','dropped','cancelled','completed') NOT NULL DEFAULT 'registered' COMMENT 'registered=active seat, others=history',
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dropped_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_class_registration` (`student_id`,`class_offering_id`),
  KEY `idx_registration_student` (`student_id`),
  KEY `idx_registration_offering` (`class_offering_id`),
  KEY `idx_registration_status` (`status`),
  CONSTRAINT `fk_registration_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_registration_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `students` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL until portal account linked',
  `student_no` varchar(20) DEFAULT NULL COMMENT 'e.g. 2026-00001',
  `lrn` varchar(12) DEFAULT NULL COMMENT 'DepEd Learner Reference Number',
  `program_id` int(10) unsigned DEFAULT NULL COMMENT 'college only',
  `grade_level_id` smallint(5) unsigned DEFAULT NULL,
  `section_id` int(10) unsigned DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated') DEFAULT NULL,
  `nationality` varchar(60) NOT NULL DEFAULT 'Filipino',
  `religion` varchar(60) DEFAULT NULL,
  `address_line1` varchar(150) DEFAULT NULL,
  `address_city` varchar(80) DEFAULT NULL,
  `address_province` varchar(80) DEFAULT NULL,
  `address_zip` varchar(10) DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `status` enum('applicant','enrolled','on_hold','graduated','transferred','dropped') NOT NULL DEFAULT 'applicant',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_student_no` (`student_no`),
  UNIQUE KEY `uq_students_user` (`user_id`),
  KEY `idx_students_lrn` (`lrn`),
  KEY `idx_students_level_section` (`grade_level_id`,`section_id`),
  KEY `fk_students_program` (`program_id`),
  KEY `fk_students_section` (`section_id`),
  CONSTRAINT `fk_students_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `subject_classrooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invite_code` varchar(10) NOT NULL COMMENT 'e.g., X7K9M2 / AB12-CD34, 6-10 chars unique',
  `subject_code` varchar(30) NOT NULL COMMENT 'e.g., BSIT-201, MATH-101',
  `subject_title` varchar(150) NOT NULL COMMENT 'e.g., Data Structures',
  `subject_id` int(10) unsigned DEFAULT NULL COMMENT 'FK -> subjects.id (canonical curriculum record)',
  `grade_level_id` smallint(5) unsigned DEFAULT NULL COMMENT 'FK -> grade_levels.id (C1-C4 year level target)',
  `program_id` int(10) unsigned DEFAULT NULL COMMENT 'FK -> programs.id (NULL = open to all programs)',
  `description` text DEFAULT NULL,
  `teacher_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → teachers.id (NULL = draft, awaiting assignment)',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'FK → academic_years.id',
  `section_name` varchar(60) DEFAULT NULL COMMENT 'e.g., BSIT-2A, display only',
  `units` decimal(3,1) NOT NULL DEFAULT 3.0,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL COMMENT 'semester/term',
  `period_code` varchar(20) DEFAULT NULL COMMENT 'band-correct period (basic-ed quarters/terms; NULL = use semester)',
  `schedule` varchar(255) DEFAULT NULL COMMENT 'e.g., MWF 08:00-09:30',
  `room` varchar(50) DEFAULT NULL COMMENT 'room/location',
  `max_students` smallint(5) unsigned NOT NULL DEFAULT 40,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('open','closed','archived') NOT NULL DEFAULT 'open' COMMENT 'admin-controlled lifecycle',
  `created_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK -> users.id (admin creator)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`invite_code`),
  KEY `idx_classroom_teacher` (`teacher_id`),
  KEY `idx_classroom_ay` (`academic_year_id`),
  KEY `idx_classroom_status` (`status`),
  KEY `idx_classroom_code` (`subject_code`),
  KEY `idx_sc_eligibility` (`academic_year_id`,`semester`,`grade_level_id`,`program_id`),
  CONSTRAINT `fk_classroom_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_classroom_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-created subject classrooms with invite code';;;

CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `units` decimal(3,1) NOT NULL DEFAULT 3.0,
  `grade_level_id` smallint(5) unsigned NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL,
  `period_code` varchar(20) DEFAULT NULL COMMENT 'band-correct period for canonical basic-ed subjects',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code_level` (`code`,`grade_level_id`),
  KEY `fk_subjects_level` (`grade_level_id`),
  CONSTRAINT `fk_subjects_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` mediumtext DEFAULT NULL,
  `setting_group` varchar(40) NOT NULL DEFAULT 'general' COMMENT 'general|smtp|payrex|enrollment',
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `teacher_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `class_offering_id` int(10) unsigned NOT NULL,
  `teacher_id` bigint(20) unsigned DEFAULT NULL,
  `academic_year_id` int(10) unsigned DEFAULT NULL,
  `evaluation_period_id` int(10) unsigned DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL COMMENT '1-5',
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_once` (`student_id`,`class_offering_id`),
  KEY `idx_eval_teacher` (`teacher_id`),
  KEY `idx_eval_offering` (`class_offering_id`),
  KEY `fk_eval_ay` (`academic_year_id`),
  CONSTRAINT `fk_eval_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_eval_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `teacher_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` varchar(255) NOT NULL COMMENT 'password_hash of plain invite code (e.g., TCH-XXXX-XXXX)',
  `code_preview` varchar(8) NOT NULL COMMENT 'last 4 chars for admin display, e.g., 3M2Q',
  `code_encrypted` text DEFAULT NULL COMMENT 'AES-256-CBC encrypted plain code for secure reveal (base64 iv+ct)',
  `email_hint` varchar(191) DEFAULT NULL COMMENT 'optional: restrict to this email (exact match)',
  `department` varchar(100) DEFAULT NULL COMMENT 'optional default department for teacher',
  `created_by` bigint(20) unsigned NOT NULL COMMENT 'FK → users.id (admin who generated)',
  `expires_at` datetime NOT NULL COMMENT 'invite expiry, default 7 days',
  `used_at` datetime DEFAULT NULL,
  `used_by` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → users.id (teacher who redeemed)',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'failed verification attempts (rate limiting)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invite_creator` (`created_by`),
  KEY `idx_invite_used_by` (`used_by`),
  KEY `idx_invite_expiry` (`expires_at`),
  KEY `idx_invite_used` (`is_used`),
  CONSTRAINT `fk_invite_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_used_by` FOREIGN KEY (`used_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Single-use hashed invite codes for secure teacher registration';;;

CREATE TABLE IF NOT EXISTS `teachers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `employee_no` varchar(30) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(80) DEFAULT NULL,
  `specialization` varchar(150) DEFAULT NULL,
  `employment_status` enum('full_time','part_time','substitute') NOT NULL DEFAULT 'full_time',
  `date_hired` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teachers_user` (`user_id`),
  UNIQUE KEY `uq_teachers_employee_no` (`employee_no`),
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` tinyint(3) unsigned NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL COMMENT 'password_hash() output',
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `is_email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` datetime DEFAULT NULL,
  `verification_token` varchar(64) DEFAULT NULL COMMENT 'link token (64-hex)',
  `token_expiry` datetime DEFAULT NULL COMMENT 'verification token expiry',
  `otp_code_hash` varchar(255) DEFAULT NULL COMMENT 'hashed 6-digit OTP',
  `otp_expiry` datetime DEFAULT NULL,
  `otp_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `status` enum('pending_verification','active','suspended','locked','archived','banned','on_hold') NOT NULL DEFAULT 'pending_verification',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL COMMENT 'drives idle auto-timeout',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_verification_token` (`verification_token`),
  KEY `idx_users_reset_token` (`reset_token`),
  KEY `idx_users_status` (`status`),
  KEY `idx_remember_token` (`remember_token`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;;

-- =============================================================================
-- SECTION 10 : SEED DATA
-- =============================================================================

-- 10.1 Roles (RBAC)
INSERT INTO `roles` (`id`,`name`,`description`) VALUES
(1,'admin',   'System Administrator - full control'),
(2,'teacher', 'Faculty Member - classes, grades, tasks'),
(3,'student', 'Enrolled Student - portal access'),
(4,'parent',  'Parent / Guardian - child monitoring'),
(5,'registrar','Registrar - academic records, grades verification, permits, documents'),
(6,'accounting','Accounting - enrollment finance, payments, balances, financial clearance');

-- 10.1b Academic periods per education band (Admin-configurable lists).
INSERT INTO `academic_periods` (`band`,`code`,`name`,`sort_order`,`is_active`) VALUES
('basic','Q1','Quarter 1',1,1),
('basic','Q2','Quarter 2',2,1),
('basic','Q3','Quarter 3',3,1),
('basic','Q4','Quarter 4',4,1),
('basic','T1','Term 1',11,0),
('basic','T2','Term 2',12,0),
('basic','T3','Term 3',13,0),
('shs','1st','1st Semester',1,1),
('shs','2nd','2nd Semester',2,1),
('college','1st','1st Semester',1,1),
('college','2nd','2nd Semester',2,1),
('college','Summer','Summer',3,1);

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
('privacy_policy','','legal'),
('terms_text','','legal'),
('basic_structure','quarter','academic'),
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

-- 10.9 Grading periods (configurable; Prelim -> Finals)
INSERT INTO `grade_periods` (`code`,`name`,`sort_order`,`is_active`) VALUES
('Prelim','Prelim',1,1),
('Midterm','Midterm',2,1),
('Prefinal','Pre-Finals',3,1),
('Finals','Finals',4,1);

-- 10.10 Assessment categories + global default weights (registrar-editable)
INSERT INTO `assessment_categories` (`code`,`name`,`sort_order`,`is_active`) VALUES
('activities','Activities',1,1),
('quizzes','Quizzes',2,1),
('assignments','Assignments',3,1),
('projects','Projects',4,1),
('exams','Exams',5,1);

INSERT INTO `grade_weights` (`classroom_id`,`category_id`,`weight_pct`)
SELECT NULL, ac.id,
  CASE ac.code WHEN 'activities' THEN 20.00 WHEN 'quizzes' THEN 20.00 WHEN 'projects' THEN 20.00 WHEN 'exams' THEN 40.00 ELSE 0.00 END
  FROM `assessment_categories` ac WHERE ac.code IN ('activities','quizzes','assignments','projects','exams');

-- 10.11 Percent -> point conversion (registrar-editable)
INSERT INTO `grade_conversions` (`min_pct`,`max_pct`,`point`,`sort_order`) VALUES
(97.00,100.00,1.00,1),
(94.00,96.99,1.25,2),
(91.00,93.99,1.50,3),
(88.00,90.99,1.75,4),
(85.00,87.99,2.00,5),
(82.00,84.99,2.25,6),
(79.00,81.99,2.50,7),
(76.00,78.99,2.75,8),
(75.00,75.99,3.00,9),
(0.00,74.99,5.00,10);

-- 10.12 Point-grade scale bands (registrar-editable)
INSERT INTO `grade_scales` (`min_grade`,`max_grade`,`equivalent`,`remarks`,`is_passing`,`sort_order`) VALUES
(1.00,1.25,'Excellent','PASSED',1,1),
(1.26,1.50,'Very Good','PASSED',1,2),
(1.51,1.75,'Good','PASSED',1,3),
(1.76,2.25,'Satisfactory','PASSED',1,4),
(2.26,2.75,'Fair','PASSED',1,5),
(2.76,3.00,'Passed','PASSED',1,6),
(3.01,4.00,'Conditional','CONDITIONAL',0,7),
(4.01,5.00,'Failed','FAILED',0,8);

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- =============================================================================
-- NOTE : The default administrator account is created by install/setup-admin.php
--        (writes a proper password_hash() and is_email_verified = 1).
--        Delete the /install directory immediately after running it.
-- =============================================================================
