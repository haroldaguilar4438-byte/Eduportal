-- =============================================================================
-- EduPortal : Live data cleanup + schema alignment (SAFE, re-runnable)
-- Datamex College of Saint Adeline
-- File : database/patch_live_fixes_20260916.sql
-- Run once via Hostinger phpMyAdmin > Import. Re-imports are no-ops.
-- Fixes findings from the 2026-09-16 live dump audit:
--   1. grade_weights global defaults were inserted TWICE (MySQL treats NULLs
--      as distinct in UNIQUE keys) — keep the lowest id per category.
--   2. users.status lacks 'banned'/'on_hold' which the app writes on
--      hold/ban actions — expand the enum (existing rows untouched).
--   3. permit_approvals rows with remarks 'Clearance signed' are clearance
--      signatures misfiled as permit history — remove them (the events stay
--      in audit_logs as clearance.signed).
-- =============================================================================

-- 1. Dedupe global grade weights (classroom_id IS NULL), keep MIN(id).
DELETE gw FROM `grade_weights` gw
  JOIN (
    SELECT category_id, MIN(id) AS keep_id
      FROM `grade_weights`
     WHERE classroom_id IS NULL
     GROUP BY category_id
    HAVING COUNT(*) > 1
  ) d ON d.category_id = gw.category_id
 WHERE gw.classroom_id IS NULL AND gw.id <> d.keep_id;

-- 2. Allow hold/ban account states used by All Accounts management.
ALTER TABLE `users`
  MODIFY COLUMN `status`
  ENUM('pending_verification','active','suspended','locked','archived','banned','on_hold')
  NOT NULL DEFAULT 'pending_verification';

-- 3. Remove clearance signatures misfiled as exam-permit approvals.
DELETE FROM `permit_approvals` WHERE remarks = 'Clearance signed';
