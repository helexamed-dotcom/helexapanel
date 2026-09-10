-- =====================================================================
--  PWA / Offline layer
--  Additive only. Nothing existing is altered destructively.
-- =====================================================================

-- Some material should never leave the server, even for offline study
-- (live question banks, for example). Default is on, so nothing changes
-- for content that already exists.
ALTER TABLE course_contents
    ADD COLUMN offline_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_printable;

-- Study sessions created from a synced offline event are tagged, so an
-- audit can always tell measured-online time from replayed-offline time.
ALTER TABLE study_sessions
    MODIFY COLUMN end_reason ENUM('closed','idle','timeout','logout','sweep','offline') NULL;

-- Idempotency ledger for the sync engine. The unique key is what makes a
-- repeated request harmless: the second copy of an event is rejected by the
-- database, not by application logic that could be bypassed.
CREATE TABLE IF NOT EXISTS offline_sync_events (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    event_id          CHAR(36)     NOT NULL,      -- client-generated UUID v4
    event_type        ENUM('study','status','position') NOT NULL,
    content_id        INT UNSIGNED NULL,
    accepted_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
    client_started_at DATETIME     NULL,
    client_ended_at   DATETIME     NULL,
    outcome           ENUM('accepted','duplicate','rejected') NOT NULL,
    reason            VARCHAR(64)  NULL,
    device_hash       CHAR(64)     NULL,
    received_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_user_event (user_id, event_id),
    KEY idx_sync_user_time (user_id, received_at),
    KEY idx_sync_content (content_id),
    CONSTRAINT fk_sync_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_sync_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('offline_enabled',            '1',     'bool',   1, NOW()),
    ('offline_lease_days',         '14',    'int',    0, NOW()),
    ('offline_max_event_seconds',  '7200',  'int',    0, NOW()),
    ('offline_max_daily_seconds',  '28800', 'int',    0, NOW()),
    ('offline_max_contents',       '60',    'int',    0, NOW());
