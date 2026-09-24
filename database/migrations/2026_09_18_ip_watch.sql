-- ============================================================================
--  IP watch: flag a student who signs in from several networks in one day
--
--  The evidence is already in `sessions` — one row per sign-in with its IP.
--  This table only records the conclusion and what the admin did about it.
--  Safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

-- One row per student per day. The unique key makes detection idempotent:
-- the fifth sign-in of the day updates the same row the third one created.
--
-- `networks` counts distinct /24 (IPv4) or /48 (IPv6) prefixes, not raw
-- addresses. Mobile carriers hand out a new address inside the same range on
-- almost every reconnect; counting raw addresses would flag every student who
-- studies on the bus.
CREATE TABLE IF NOT EXISTS security_flags (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    flag_type    VARCHAR(32)  NOT NULL DEFAULT 'multi_ip',
    flag_day     DATE         NOT NULL,
    networks     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    sign_ins     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ip_list      JSON         NULL,
    status       ENUM('open','warned','dismissed','suspended') NOT NULL DEFAULT 'open',
    reviewed_by  BIGINT UNSIGNED NULL,
    reviewed_at  DATETIME     NULL,
    note         VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flag_user_day (user_id, flag_type, flag_day),
    KEY idx_flag_status (status, flag_day),
    CONSTRAINT fk_flag_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_flag_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The sign-in history a student sees and the detector reads are both
-- "this user's sessions, newest first".
-- (idx_sessions_user_active already leads with user_id; no new index needed.)

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    -- How many different networks in one day raise a flag.
    ('ip_watch_threshold', '3', 'int',  0, NOW()),
    -- Off switch, for an installation behind a proxy that has not enabled
    -- trust_proxy: there every sign-in shows the proxy's address.
    ('ip_watch_enabled',   '1', 'bool', 0, NOW());
