-- =============================================================================
-- EduPortal : Clearance per-subject signing for invite-code subjects
-- Datamex College of Saint Adeline
-- File : database/patch_clearance_subjects.sql
-- Run once: mysql -u root eduportal_db < database/patch_clearance_subjects.sql
--       or Hostinger phpMyAdmin > Import (errors on re-run are safe to ignore:
--       they only mean the column/index already exists)
-- Purpose : clearance_items used to reference ONLY legacy class_offerings, but
--       students enroll via subject_classrooms (invite codes), so clearances
--       showed "No subjects assigned". This links items to subject_classrooms
--       too and allows legacy class_offering_id to be NULL.
-- =============================================================================

ALTER TABLE `clearance_items`
  ADD COLUMN IF NOT EXISTS `subject_classroom_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK -> subject_classrooms.id (invite-code subjects)' AFTER `class_offering_id`,
  MODIFY COLUMN `class_offering_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK class_offerings.id (legacy; NULL when subject-classroom based)';

CREATE UNIQUE INDEX IF NOT EXISTS `uq_item_subject` ON `clearance_items` (`clearance_id`, `subject_classroom_id`);

ALTER TABLE `clearance_items`
  ADD CONSTRAINT `fk_item_subject_classroom` FOREIGN KEY (`subject_classroom_id`)
  REFERENCES `subject_classrooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
