-- =============================================================================
-- EduPortal : Optional teacher on official subjects (draft-then-assign)
-- File : database/patch_subject_optional_teacher.sql
-- Run once via Hostinger phpMyAdmin > Import (re-runnable: guards make
-- re-imports a no-op). Works on MySQL and MariaDB.
-- Purpose : admin can create a subject WITHOUT assigning a teacher yet
--   (e.g. still finding a suitable teacher), then assign one later via
--   Admin > Subjects > Assign teacher.
--   - subject_classrooms.teacher_id becomes NULLABLE
--   - FK becomes ON DELETE SET NULL (deleting a teacher unassigns instead
--     of deleting the subject and its student memberships/tasks)
--   Teacherless subjects stay hidden from student offerings/preview/join
--   until a teacher is assigned (enforced in app code, not just the UI).
-- =============================================================================

-- NULLABILITY FIRST: SET NULL requires a nullable column before the FK is made.
ALTER TABLE `subject_classrooms`
  MODIFY COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> teachers.id (NULL = draft, awaiting assignment)';

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_drop_fk`$$
CREATE PROCEDURE `__edu_drop_fk`(
  IN p_table VARCHAR(64), IN p_name VARCHAR(64)
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name
  ) THEN
    SET @__sql = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', p_name, '`');
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
CREATE PROCEDURE `__edu_add_fk`(
  IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_clause TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name
  ) THEN
    SET @__sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` ', p_clause);
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
CALL `__edu_drop_fk`('subject_classrooms','fk_classroom_teacher')$$
CALL `__edu_add_fk`('subject_classrooms','fk_classroom_teacher','FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE SET NULL ON UPDATE CASCADE')$$
DROP PROCEDURE IF EXISTS `__edu_drop_fk`$$
DROP PROCEDURE IF EXISTS `__edu_add_fk`$$
DELIMITER ;
