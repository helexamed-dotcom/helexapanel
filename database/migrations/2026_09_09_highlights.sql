-- =====================================================================
--  Highlights on lesson content
-- =====================================================================

CREATE TABLE IF NOT EXISTS content_highlights (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    content_id      INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    kind            ENUM('text','area') NOT NULL DEFAULT 'text',
    color           VARCHAR(16)  NOT NULL DEFAULT 'yellow',
    -- Where it sits: a node path plus offsets for text, or a node path plus a
    -- percentage rectangle for a region of an image.
    anchor          JSON         NOT NULL,
    -- The words that were highlighted. Kept so a highlight can be re-found by
    -- search when the DOM path no longer resolves, and so the list is readable.
    quote           VARCHAR(500) NULL,
    note            VARCHAR(500) NULL,
    -- The checksum of the lesson file at the time. When the file is replaced,
    -- old highlights are shown as "needs checking" instead of landing somewhere
    -- arbitrary in the new text.
    content_version CHAR(64)     NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_highlight_uuid (uuid),
    KEY idx_highlight_owner (user_id, content_id, created_at),
    KEY idx_highlight_content (content_id),
    CONSTRAINT fk_highlight_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_highlight_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE,
    CONSTRAINT fk_highlight_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Highlights made while offline travel through the same sync ledger.
ALTER TABLE offline_sync_events
    MODIFY COLUMN event_type ENUM('study','status','position','highlight') NOT NULL;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('highlight_enabled',          '1',   'bool', 1, NOW()),
    ('highlight_max_per_content',  '300', 'int',  0, NOW());
