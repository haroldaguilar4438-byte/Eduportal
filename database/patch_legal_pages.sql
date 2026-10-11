-- =============================================================================
-- EduPortal : legal pages support (privacy/terms text overrides)
-- Run once via Hostinger phpMyAdmin > Import (re-runnable).
-- Empty values = use the shipped truthful defaults in LegalController.
-- Authorized personnel may paste official policy text here later.
-- =============================================================================
INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('privacy_policy','','legal'),
('terms_text','','legal');
