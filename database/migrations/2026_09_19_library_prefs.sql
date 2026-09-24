-- ============================================================================
--  Content library, and per-user interface preferences (theme, language)
--  Safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  library_items — videos, articles, images, files and links
--
--  One table for five kinds, because they share every column that matters
--  for listing and searching (title, summary, cover, status, audience) and
--  differ only in where the body lives:
--    video / image / file  → file_path (private storage)
--    article               → body (plain text, escaped on output)
--    link                  → url (http/https only)
--
--  course_id NULL means every active student; set, it means only students
--  whose enrolment in that course is active right now.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS library_items (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    kind        ENUM('video','article','image','file','link') NOT NULL,
    title       VARCHAR(191) NOT NULL,
    summary     VARCHAR(500) NULL,
    body        MEDIUMTEXT   NULL,
    url         VARCHAR(500) NULL,
    file_path   VARCHAR(191) NULL,
    file_mime   VARCHAR(64)  NULL,
    file_size   BIGINT UNSIGNED NULL,
    file_name   VARCHAR(191) NULL,
    cover_path  VARCHAR(191) NULL,
    course_id   INT UNSIGNED NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    view_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    deleted_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_library_uuid (uuid),
    KEY idx_library_live (deleted_at, status, sort_order),
    KEY idx_library_course (course_id),
    CONSTRAINT fk_library_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_library_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  user_preferences — one row per user, created on first save
--
--  A side table rather than new columns on `users`: adding columns needs
--  ALTER TABLE … IF NOT EXISTS, which MySQL (unlike MariaDB) does not accept,
--  and a preference is not a fact about the account anyway.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id    BIGINT UNSIGNED NOT NULL,
    accent     VARCHAR(16)  NOT NULL DEFAULT 'blueberry',
    mode       ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    lang       ENUM('fa','en') NOT NULL DEFAULT 'fa',
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    -- Largest file an admin may put in the library, in megabytes. The real
    -- ceiling is still PHP's upload_max_filesize; this is the app's own.
    ('library_max_mb', '200', 'int', 0, NOW());
