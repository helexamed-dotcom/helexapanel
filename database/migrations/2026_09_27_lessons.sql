-- ============================================================================
--  «درسنامه‌ها» — text lessons written in the site's own editor
--
--  lessons                one درسنامه: rich HTML (sanitised on save), filed
--                         under a درس / زیردرس of the question bank's tree
--                         when it has one, optionally only for holders of a
--                         package.
--  lesson_tags            the SAME tags as the question bank (qb_tags): one
--                         tag joins a درسنامه, its questions and its figure
--                         game hotspots.
--  qb_question_lessons    a question pointing at the درسنامه that teaches it;
--                         a wrong answer then recommends exactly that one.
--  lesson_reads           one row per student and درسنامه: their personal
--                         highlights and text colours, reading position,
--                         when they finished it, whether it is bookmarked.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lessons (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    title           VARCHAR(191)    NOT NULL,
    summary         VARCHAR(500)    NULL,
    subject_id      INT UNSIGNED    NULL,
    package_id      INT UNSIGNED    NULL,
    color           VARCHAR(16)     NOT NULL DEFAULT 'indigo',
    cover_path      VARCHAR(191)    NULL,
    body_html       MEDIUMTEXT      NOT NULL,
    reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NULL,
    deleted_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lessons_uuid (uuid),
    KEY idx_lessons_subject (subject_id, status),
    KEY idx_lessons_status (status, deleted_at, sort_order),
    CONSTRAINT fk_lessons_subject FOREIGN KEY (subject_id) REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_lessons_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_lessons_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_tags (
    lesson_id INT UNSIGNED NOT NULL,
    tag_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (lesson_id, tag_id),
    KEY idx_lesson_tags_tag (tag_id),
    CONSTRAINT fk_lesson_tags_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_tags_tag    FOREIGN KEY (tag_id)    REFERENCES qb_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_question_lessons (
    question_id BIGINT UNSIGNED NOT NULL,
    lesson_id   INT UNSIGNED    NOT NULL,
    PRIMARY KEY (question_id, lesson_id),
    KEY idx_qql_lesson (lesson_id),
    CONSTRAINT fk_qql_question FOREIGN KEY (question_id) REFERENCES qb_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qql_lesson   FOREIGN KEY (lesson_id)   REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_reads (
    user_id     BIGINT UNSIGNED NOT NULL,
    lesson_id   INT UNSIGNED    NOT NULL,
    highlights  JSON            NULL,
    progress    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bookmarked  TINYINT(1)      NOT NULL DEFAULT 0,
    read_at     DATETIME        NULL,
    opened_at   DATETIME        NOT NULL,
    updated_at  DATETIME        NULL,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_lesson_reads_lesson (lesson_id),
    CONSTRAINT fk_lesson_reads_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_lesson_reads_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- «📌 باید بخونم» can point at a درسنامه too.
ALTER TABLE study_marks
    MODIFY COLUMN kind ENUM('content','library','qbank_topic','balin_lesson','course','custom','lesson','mindmap','figure') NOT NULL;

INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('lessons.manage', 'مدیریت درسنامه‌ها، نقشه‌های ذهنی و برچسب‌ها', 'lessons', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'lessons';
