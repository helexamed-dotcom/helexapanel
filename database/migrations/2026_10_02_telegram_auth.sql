-- ============================================================================
--  ورود و ثبت‌نام با ربات تلگرام
--
--  The student starts the bot and shares their number with Telegram's own
--  «ارسال شماره» button — the contact then belongs to their own Telegram
--  account, which Telegram verified by SMS, so it cannot be a number they
--  made up. The bot answers with a one-time link; on the site they choose a
--  username and a password, and from then on sign in with their mobile (or
--  username) and that password, like everyone else.
--
--  telegram_accounts  one row per Telegram user who shared a number: their
--                     chat, the verified number, the site account it belongs
--                     to once there is one.
--  telegram_tokens    the one-time links (only a hash is stored), for a new
--                     account or for choosing a new password.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE users
    MODIFY COLUMN registration_source ENUM('admin','self_otp','self','telegram') NOT NULL DEFAULT 'admin';

CREATE TABLE IF NOT EXISTS telegram_accounts (
    tg_user_id    BIGINT          NOT NULL,
    chat_id       BIGINT          NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    phone         VARCHAR(20)     NULL,
    tg_username   VARCHAR(64)     NULL,
    first_name    VARCHAR(128)    NULL,
    verified_at   DATETIME        NULL,
    last_link_at  DATETIME        NULL,
    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME        NULL,
    PRIMARY KEY (tg_user_id),
    UNIQUE KEY uq_telegram_accounts_user (user_id),
    KEY idx_telegram_accounts_phone (phone),
    CONSTRAINT fk_telegram_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash  CHAR(64)        NOT NULL,
    tg_user_id  BIGINT          NOT NULL,
    phone       VARCHAR(20)     NOT NULL,
    purpose     ENUM('register','reset') NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_telegram_tokens_hash (token_hash),
    KEY idx_telegram_tokens_tg (tg_user_id, created_at),
    CONSTRAINT fk_telegram_tokens_account FOREIGN KEY (tg_user_id) REFERENCES telegram_accounts(tg_user_id) ON DELETE CASCADE,
    CONSTRAINT fk_telegram_tokens_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
