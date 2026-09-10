-- =====================================================================
--  Telegram — account linking, conversation state, and the delivery queue
--  a NotificationService channel drains.
-- =====================================================================

-- One row per linked Telegram account. telegram_user_id is Telegram's own
-- numeric id and is never trusted for authorization by itself — every query
-- elsewhere joins through user_id, which is only set once a real password
-- has been verified.
CREATE TABLE IF NOT EXISTS telegram_accounts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NOT NULL,
    telegram_user_id BIGINT          NOT NULL,
    chat_id          BIGINT          NOT NULL,
    telegram_username VARCHAR(64)    NULL,
    first_name       VARCHAR(191)    NULL,
    phone_shared      VARCHAR(20)    NULL,
    notify_general    TINYINT(1)     NOT NULL DEFAULT 1,
    notify_schedule   TINYINT(1)     NOT NULL DEFAULT 1,
    notify_exams      TINYINT(1)     NOT NULL DEFAULT 1,
    notify_announcements TINYINT(1)  NOT NULL DEFAULT 1,
    notify_support    TINYINT(1)     NOT NULL DEFAULT 1,
    linked_at        DATETIME        NOT NULL,
    last_seen_at     DATETIME        NULL,
    unlinked_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_telegram_user (telegram_user_id),
    KEY idx_telegram_account_user (user_id, unlinked_at),
    CONSTRAINT fk_telegram_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The bot has no HTTP session; each webhook call is a fresh, stateless
-- request. This table is that missing session: "what step is this chat in,
-- and what has it collected so far". Rows expire on their own.
CREATE TABLE IF NOT EXISTS telegram_states (
    telegram_user_id BIGINT       NOT NULL,
    step             VARCHAR(48)  NOT NULL,
    payload          JSON         NULL,
    updated_at       DATETIME     NOT NULL,
    expires_at       DATETIME     NOT NULL,
    PRIMARY KEY (telegram_user_id),
    KEY idx_telegram_state_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound deliveries for any notification channel. Telegram is the first
-- consumer, but the shape is channel-agnostic on purpose (see
-- NotificationService::registerChannel) so email/push can reuse it later.
CREATE TABLE IF NOT EXISTS notification_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    channel         VARCHAR(32)  NOT NULL,
    status          ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(255) NULL,
    next_attempt_at DATETIME     NOT NULL,
    created_at      DATETIME     NOT NULL,
    sent_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_once (notification_id, user_id, channel),
    KEY idx_delivery_due (status, next_attempt_at),
    CONSTRAINT fk_delivery_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('telegram_bot_enabled',   '0', 'bool',   0, NOW()),
    ('telegram_bot_token',     '',  'string', 0, NOW()),
    ('telegram_webhook_secret','',  'string', 0, NOW()),
    ('telegram_bot_username',  '',  'string', 1, NOW()),
    ('telegram_queue_last_drain', '0', 'int',   0, NOW());
