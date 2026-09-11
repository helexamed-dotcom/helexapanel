-- =====================================================================
--  Guest mode: a small, explicit shop window
--
--  Nothing becomes public by default. A lesson is visible to visitors only
--  when an admin ticks it, and only while the global switch is on.
-- =====================================================================

-- The client character set must be declared before any Persian literal
-- below. Without it a CLI whose default is latin1 stores the UTF-8 bytes
-- a second time over, and every seeded string arrives double-encoded.
SET NAMES utf8mb4;

ALTER TABLE course_contents
    ADD COLUMN guest_visible TINYINT(1) NOT NULL DEFAULT 0 AFTER offline_enabled,
    ADD KEY idx_contents_guest (guest_visible, status);

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('guest_mode_enabled',   '1', 'bool', 1, NOW()),
    ('guest_watermark_text', 'نسخه نمایشی — helexapanel.ir', 'string', 0, NOW());
