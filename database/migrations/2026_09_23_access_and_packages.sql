-- ============================================================================
--  Access: packages that carry more than courses, and Balin lessons that are
--  closed until someone is given them.
--
--  package_items        the question bank subjects, Balin lessons and
--                       flashcard courses a package hands over. Courses keep
--                       their own table (package_courses) — enrolments are a
--                       different thing from a grant and always were.
--
--  packages.is_full_access
--                       the "full option" package: everything that exists,
--                       including whatever is added later.
--
--  balin_lessons.access_mode
--                       'open'    — every student who has the island sees it
--                                   (this is what every existing lesson is)
--                       'granted' — only students in balin_lesson_grants
--
--  balin_lesson_grants  who has been given a 'granted' lesson.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS package_items (
    package_id INT UNSIGNED NOT NULL,
    item_type  ENUM('qbank_subject','balin_lesson','flashcard_course') NOT NULL,
    item_id    INT UNSIGNED NOT NULL,
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    added_at   DATETIME     NOT NULL,
    PRIMARY KEY (package_id, item_type, item_id),
    KEY idx_package_items_item (item_type, item_id),
    CONSTRAINT fk_package_items_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No foreign key on item_id: the three tables it points at belong to modules
-- that are installed separately, and a package must survive one of them being
-- absent. Rows whose target is gone are skipped when the package is granted.

ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS is_full_access TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_grant_new_courses;

ALTER TABLE balin_lessons
    ADD COLUMN IF NOT EXISTS access_mode ENUM('open','granted') NOT NULL DEFAULT 'open' AFTER status;

CREATE TABLE IF NOT EXISTS balin_lesson_grants (
    user_id    BIGINT UNSIGNED NOT NULL,
    lesson_id  INT UNSIGNED    NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_balin_grant_lesson (lesson_id),
    CONSTRAINT fk_balin_grant_user   FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_balin_grant_lesson FOREIGN KEY (lesson_id)  REFERENCES balin_lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_grant_admin  FOREIGN KEY (granted_by) REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
