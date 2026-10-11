-- =============================================================================
-- EduPortal : Teacher file attachments on classroom tasks (EXTEND only)
-- File : database/patch_task_files.sql
-- Run : Hostinger phpMyAdmin > Import (re-runnable).
-- Lets teachers attach one file (exam paper, worksheet) to a task.
-- Students download it through a permit-checked route (never a guessable
-- direct link for gated exams).
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS `__edu_add_col_taskfile`$$
CREATE PROCEDURE `__edu_add_col_taskfile`(
  IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @__sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE __stmt FROM @__sql;
    EXECUTE __stmt;
    DEALLOCATE PREPARE __stmt;
  END IF;
END$$
DELIMITER ;

CALL `__edu_add_col_taskfile`('classroom_tasks','file_path','VARCHAR(255) DEFAULT NULL AFTER `due_at`');
CALL `__edu_add_col_taskfile`('classroom_tasks','original_filename','VARCHAR(191) DEFAULT NULL AFTER `file_path`');
CALL `__edu_add_col_taskfile`('classroom_tasks','mime_type','VARCHAR(100) DEFAULT NULL AFTER `original_filename`');
CALL `__edu_add_col_taskfile`('classroom_tasks','file_size_bytes','INT UNSIGNED DEFAULT NULL AFTER `mime_type`');

DROP PROCEDURE IF EXISTS `__edu_add_col_taskfile`;

SET FOREIGN_KEY_CHECKS=1;
