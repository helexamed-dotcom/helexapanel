-- ============================================================================
--  Handwriting and notes
--
--  lesson_ink          what a student wrote with the pen directly on a lesson
--  student_notes       sticky notes (pinned on a lesson, or standalone) with
--                      typed text and a handwriting page
--  student_note_files  PDFs / images attached to a note, each with its own
--                      handwriting layer per page
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_ink (
    user_id      BIGINT UNSIGNED NOT NULL,
    content_id   INT UNSIGNED    NOT NULL,
    strokes      MEDIUMTEXT      NOT NULL,          -- JSON array, see assets/js/ink.js
    stroke_count INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at   DATETIME        NOT NULL,
    PRIMARY KEY (user_id, content_id),
    KEY idx_lesson_ink_content (content_id),
    CONSTRAINT fk_lesson_ink_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_lesson_ink_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_notes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    content_id  INT UNSIGNED    NULL,               -- NULL: a standalone note
    pos_x       INT             NULL,               -- pin position on the lesson, in px …
    pos_y       INT             NULL,
    pos_w       INT             NULL,               -- … at this page width
    color       VARCHAR(16)     NOT NULL DEFAULT 'yellow',
    title       VARCHAR(191)    NULL,
    body        MEDIUMTEXT      NULL,               -- typed text
    ink         MEDIUMTEXT      NULL,               -- handwriting page, JSON
    ink_ratio   DECIMAL(6,3)    NOT NULL DEFAULT 1.400,  -- page height / width
    ink_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NULL,
    deleted_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_notes_uuid (uuid),
    KEY idx_student_notes_user (user_id, deleted_at, updated_at),
    KEY idx_student_notes_content (user_id, content_id),
    CONSTRAINT fk_student_notes_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_student_notes_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_note_files (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    note_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    file_path   VARCHAR(191)    NOT NULL,           -- generated name, never a path
    file_name   VARCHAR(191)    NOT NULL,
    file_mime   VARCHAR(64)     NOT NULL,
    file_size   INT UNSIGNED    NOT NULL,
    ink         MEDIUMTEXT      NULL,               -- {"1":[strokes…], "2":[…]} per page
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_note_files_uuid (uuid),
    KEY idx_student_note_files_note (note_id),
    CONSTRAINT fk_note_files_note FOREIGN KEY (note_id) REFERENCES student_notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_files_user FOREIGN KEY (user_id) REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('ink_enabled',        '1',  'bool', 0, NOW()),
    ('notes_enabled',      '1',  'bool', 0, NOW()),
    ('notes_file_max_mb',  '25', 'int',  0, NOW()),
    ('notes_max_files',    '20', 'int',  0, NOW());