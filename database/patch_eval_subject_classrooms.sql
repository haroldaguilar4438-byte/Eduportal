-- =============================================================================
-- EduPortal : Teacher evaluations reference invite-code subjects
-- Datamex College of Saint Adeline
-- File : database/patch_eval_subject_classrooms.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable: procedure guards
-- make re-imports a no-op).
-- Purpose : teacher_evaluations.class_offering_id actually stores
--       subject_classrooms.id (invite-code subjects students join), but an old
--       FK forced it to match legacy class_offerings.id (empty on schools that
--       run on invite codes) — every evaluation failed with 1452. Drop that FK;
--       the UNIQUE(student, class_offering) key still prevents duplicates, and
--       teacher/academic-year FKs (real ids) are kept.
-- =============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_drop_eval_offering_fk`$$
CREATE PROCEDURE `__edu_drop_eval_offering_fk`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'teacher_evaluations'
      AND CONSTRAINT_NAME = 'fk_eval_offering'
  ) THEN
    ALTER TABLE `teacher_evaluations` DROP FOREIGN KEY `fk_eval_offering`;
  END IF;
END$$
CALL `__edu_drop_eval_offering_fk`()$$
DROP PROCEDURE IF EXISTS `__edu_drop_eval_offering_fk`$$
DELIMITER ;
