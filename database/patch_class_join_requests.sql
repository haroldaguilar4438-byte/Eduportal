-- =============================================================================
-- EduPortal : Teacher-verified subject join requests (no invite code needed)
-- File : database/patch_class_join_requests.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable).
-- Purpose : let students (or parents on their behalf) tap "Request to Join"
--   on an eligible subject without knowing any invite code. The system
--   auto-checks enrollment eligibility at request time; the assigned teacher
--   verifies (Approve/Decline) before the student becomes a member.
--   Final membership still lives in classroom_members (no duplicate roster).
--   - class_join_requests: one row per (classroom, student); re-requests
--     reactivate the row (transitions audit-logged, never silently dropped).
-- =============================================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Teacher-verified subject join requests (code-free joining)';
