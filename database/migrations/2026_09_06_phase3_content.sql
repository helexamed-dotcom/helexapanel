-- =====================================================================
--  Phase 3 — content pipeline additions
--  Safe to run on an existing Phase 1/2 installation.
-- =====================================================================

-- Result of the upload scanner: external hosts, storage APIs, size, checksum.
ALTER TABLE course_contents
    ADD COLUMN scan_report JSON NULL AFTER checksum,
    ADD COLUMN original_filename VARCHAR(191) NULL AFTER storage_path;

-- Per-student client state for lesson scripts (quiz best scores, etc.).
-- Replaces localStorage, which is unavailable inside an opaque-origin iframe.
ALTER TABLE student_content_status
    ADD COLUMN client_state JSON NULL AFTER total_seconds;

-- Viewer tokens are short-lived; a scheduled purge keeps the table small.
ALTER TABLE viewer_tokens
    ADD INDEX idx_vt_user_content (user_id, content_id, expires_at);


-- Runtime settings introduced with the content pipeline.
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('content_cache_mode',          'revalidate', 'string', 0, NOW()),
    ('content_max_upload_mb',       '25',         'int',    0, NOW()),
    ('viewer_allow_external_fonts', '1',          'bool',   0, NOW()),
    ('viewer_local_font',           '1',          'bool',   0, NOW());
