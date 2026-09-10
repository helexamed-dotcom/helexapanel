-- =====================================================================
--  Phase 7 — hardening
--  Safe to run on an existing installation.
-- =====================================================================

-- Generic request throttle. One row per (bucket, identifier, time window),
-- so a burst is counted without keeping a row per request.
CREATE TABLE IF NOT EXISTS rate_limits (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket       VARCHAR(64)  NOT NULL,   -- login, beat, upload, ...
    identifier   CHAR(64)     NOT NULL,   -- HMAC of the user id or IP, never the raw value
    window_start DATETIME     NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_window (bucket, identifier, window_start),
    KEY idx_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('log_retention_days',   '180', 'int',    0, NOW()),
    ('attempt_retention_days','30', 'int',    0, NOW()),
    ('maintenance_last_run', '0',   'int',    0, NOW());
