-- =====================================================================
--  Support tickets: a student opens a ticket, an admin answers from the
--  panel, and the reply reaches the student through the same queue every
--  other notification already uses.
-- =====================================================================

CREATE TABLE IF NOT EXISTS support_tickets (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)     NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    -- open     = the student is waiting on an admin
    -- answered = an admin replied, waiting to see if the student writes back
    -- closed   = resolved; a new message from the student opens a fresh ticket
    status           ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    last_message_at  DATETIME     NOT NULL,
    created_at       DATETIME     NOT NULL,
    closed_at        DATETIME     NULL,
    closed_by        BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_uuid (uuid),
    KEY idx_ticket_user (user_id, status),
    KEY idx_ticket_status (status, last_message_at),
    CONSTRAINT fk_ticket_user      FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)     NOT NULL,
    ticket_id        BIGINT UNSIGNED NOT NULL,
    sender_type      ENUM('student','admin') NOT NULL,
    sender_id        BIGINT UNSIGNED NOT NULL,
    body             TEXT         NULL,
    attachment_path  VARCHAR(255) NULL,
    created_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_message_uuid (uuid),
    KEY idx_support_message_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_message_sender FOREIGN KEY (sender_id) REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A reply notification gets its own type so it reads students' existing
-- notify_support toggle instead of the general one.
ALTER TABLE notifications
    MODIFY COLUMN notif_type ENUM('content','schedule','exam','course','package','message','support','system')
        NOT NULL DEFAULT 'system';
