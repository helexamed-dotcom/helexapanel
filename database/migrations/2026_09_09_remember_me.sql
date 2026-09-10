-- =====================================================================
--  Remember Me + password policy
--  Additive. No existing row is touched.
-- =====================================================================

-- Split-token design: the selector identifies the row, the validator proves
-- ownership. Only the validator's hash is stored, so a database read yields
-- nothing a thief could present at the login form.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    selector       CHAR(32)     NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    device_hash    CHAR(64)     NOT NULL,
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(512) NULL,
    created_at     DATETIME     NOT NULL,
    last_used_at   DATETIME     NULL,
    expires_at     DATETIME     NOT NULL,
    revoked_at     DATETIME     NULL,
    revoke_reason  ENUM('logout','password_change','admin','theft_suspected','expired','rotated') NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id, revoked_at),
    KEY idx_remember_expiry (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('remember_me_enabled', '1',  'bool', 1, NOW()),
    ('remember_me_days',    '30', 'int',  0, NOW());
