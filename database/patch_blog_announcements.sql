-- =============================================================
-- EduPortal — Blog / Announcements upgrade
-- File: database/patch_blog_announcements.sql
-- Safe on Hostinger (MySQL/MariaDB, PDO-compatible, non-destructive).
-- Run ONCE via phpMyAdmin > Import. Re-running is safe for
-- CREATE TABLE IF NOT EXISTS; ALTERs will report "duplicate column"
-- which can be ignored (or run each ALTER only if the column is missing).
-- Existing enrollment/payment/grade/student data is untouched.
-- =============================================================

-- ---------------- Blog categories (reused by admin dropdown + filter) --------
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blogcat_slug` (`slug`),
  UNIQUE KEY `uq_blogcat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories only when empty (no fake posts, just taxonomy)
INSERT INTO `blog_categories` (`name`, `slug`)
SELECT * FROM (SELECT 'General' AS name, 'general' AS slug) s
WHERE NOT EXISTS (SELECT 1 FROM `blog_categories`);
INSERT INTO `blog_categories` (`name`, `slug`)
SELECT * FROM (SELECT 'Admissions' AS name, 'admissions' AS slug) s
WHERE NOT EXISTS (SELECT 1 FROM `blog_categories` WHERE slug='admissions');
INSERT INTO `blog_categories` (`name`, `slug`)
SELECT * FROM (SELECT 'Academics' AS name, 'academics' AS slug) s
WHERE NOT EXISTS (SELECT 1 FROM `blog_categories` WHERE slug='academics');
INSERT INTO `blog_categories` (`name`, `slug`)
SELECT * FROM (SELECT 'Events' AS name, 'events' AS slug) s
WHERE NOT EXISTS (SELECT 1 FROM `blog_categories` WHERE slug='events');

-- ---------------- Blog posts -------------------------------------------------
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL,
  `summary` VARCHAR(500) DEFAULT NULL,
  `content` MEDIUMTEXT NOT NULL COMMENT 'sanitized allowlist HTML on write',
  `featured_image` VARCHAR(255) DEFAULT NULL COMMENT 'relative path under /uploads/blog',
  `author_id` BIGINT UNSIGNED DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `tags` VARCHAR(255) DEFAULT NULL COMMENT 'comma-separated, normalized lowercase',
  `status` ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `seo_title` VARCHAR(200) DEFAULT NULL,
  `seo_description` VARCHAR(300) DEFAULT NULL,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_slug` (`slug`),
  KEY `idx_blog_status_pub` (`status`,`published_at`),
  KEY `idx_blog_cat` (`category_id`),
  KEY `idx_blog_author` (`author_id`),
  CONSTRAINT `fk_blog_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blog_cat` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- Announcements upgrade (extend, never replace) --------------
-- Existing columns kept: id,title,content,audience,section_id,posted_by,
-- publish_at,expires_at,is_pinned,created_at,updated_at.
-- Add only what the spec needs and the current table lacks.

-- type/category of notice
ALTER TABLE `announcements` ADD COLUMN `type` VARCHAR(40) NOT NULL DEFAULT 'general'
  COMMENT 'general,enrollment,payment,academic,examination,campus,emergency' AFTER `content`;

-- workflow status (draft/scheduled/published/archived). Existing rows -> published.
ALTER TABLE `announcements` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'published'
  COMMENT 'draft,scheduled,published,archived' AFTER `type`;

-- optional targeting (all NULL = no extra restriction beyond audience)
ALTER TABLE `announcements` ADD COLUMN `campus_id` INT UNSIGNED DEFAULT NULL AFTER `section_id`;
ALTER TABLE `announcements` ADD COLUMN `grade_level_id` INT UNSIGNED DEFAULT NULL AFTER `campus_id`;
ALTER TABLE `announcements` ADD COLUMN `academic_year_id` INT UNSIGNED DEFAULT NULL AFTER `grade_level_id`;

-- optional file attachment (relative path under /uploads/announcements)
ALTER TABLE `announcements` ADD COLUMN `attachment_path` VARCHAR(255) DEFAULT NULL AFTER `is_pinned`;

-- SEO-free indexes for feed queries
ALTER TABLE `announcements` ADD INDEX `idx_ann_status_pub` (`status`,`publish_at`);
ALTER TABLE `announcements` ADD INDEX `idx_ann_type` (`type`);

-- Backfill: everything previously visible stays published
UPDATE `announcements` SET `status`='published' WHERE `status`='published';
UPDATE `announcements` SET `status`='scheduled' WHERE `publish_at` > NOW() AND `status`='published';

-- ---------------- Announcement reads (per-user read/unread, preserves history) --
CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `announcement_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`,`user_id`),
  KEY `idx_aread_user` (`user_id`,`read_at`),
  CONSTRAINT `fk_aread_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aread_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
