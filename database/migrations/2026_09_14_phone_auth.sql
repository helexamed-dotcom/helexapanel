-- =====================================================================
--  Phone-first authentication: OTP sign-in, self-registration and SMS.
--  Additive and idempotent. No existing row is rewritten.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- users
-- A person who signs in with a texted code has no password until they
-- choose to set one, so the column can no longer be NOT NULL. Every read
-- path already treats it as a string to compare, and password_verify()
-- against NULL is never reached: the login path checks for a set password
-- first.
--
-- phone_verified_at is proof the number belongs to whoever is holding the
-- phone. NULL means unverified, which is the honest state for the accounts
-- an admin typed in by hand. It is a timestamp rather than a flag because
-- "when" is what support actually needs when a student disputes an account.
--
-- registration_source tells admin-created accounts apart from self-registered
-- ones. Inferring it from a NULL password would be wrong the moment a
-- self-registered student sets one.
ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN phone_verified_at   DATETIME NULL AFTER mobile,
    ADD COLUMN registration_source ENUM('admin','self_otp') NOT NULL DEFAULT 'admin' AFTER status;

-- ----------------------------------------------------------- otp_codes
-- One row per issued code. The code itself is never stored: only an HMAC of
-- it, keyed with the application key, so a database dump yields nothing that
-- can be replayed and an offline brute force of a six-digit space still
-- needs the key.
--
-- Rows are kept after use rather than deleted: the resend cooldown and the
-- hourly request ceiling are both counted from this table, and a deleted row
-- would reset them.
CREATE TABLE IF NOT EXISTS otp_codes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone        VARCHAR(20)  NOT NULL,          -- normalized 09xxxxxxxxx
    purpose      ENUM('login','register','reset','verify') NOT NULL DEFAULT 'login',
    code_hash    CHAR(64)     NOT NULL,          -- HMAC-SHA256(code), keyed with app_key
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    -- Set the moment the code is accepted, so a second presentation of the
    -- same code finds it spent rather than valid.
    used_at      DATETIME     NULL,
    -- Set when the code is retired without being accepted: too many wrong
    -- guesses, or a newer code superseding it.
    consumed_at  DATETIME     NULL,
    expires_at   DATETIME     NOT NULL,
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(512) NULL,
    -- Carries the verified number across the two-step reset flow without
    -- trusting the browser to say whose password it is changing.
    ticket_hash  CHAR(64)     NULL,
    ticket_expires_at DATETIME NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_otp_lookup (phone, purpose, used_at, expires_at),
    KEY idx_otp_recent (phone, created_at),
    KEY idx_otp_ticket (ticket_hash),
    KEY idx_otp_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ settings
-- Operational values the admin tunes from the panel. The MeliPayamak
-- password is stored encrypted under sms_api_key_enc and is deliberately
-- absent here: there is no safe default for a credential.
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('sms_enabled',              '0',   'bool',   0, NOW()),
    ('sms_username',             '',    'string', 0, NOW()),
    ('sms_from',                 '',    'string', 0, NOW()),
    ('otp_enabled',              '1',   'bool',   1, NOW()),
    ('otp_registration_enabled', '1',   'bool',   1, NOW()),
    ('otp_ttl_seconds',          '120', 'int',    1, NOW()),
    ('otp_resend_seconds',       '60',  'int',    1, NOW()),
    ('otp_max_attempts',         '5',   'int',    0, NOW()),
    ('otp_max_per_hour',         '5',   'int',    0, NOW()),
    ('otp_max_per_ip_per_hour',  '20',  'int',    0, NOW()),
    ('otp_message_template',     'کد ورود شما: {code}', 'string', 0, NOW());
