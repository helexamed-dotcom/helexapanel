-- ============================================================================
--  Per-student access: Balin lessons one by one
--
--  Student tiers (برنزی / نقره‌ای / طلایی) need no table: they are derived from
--  what the student holds, every time, so a tier can never disagree with the
--  access it describes.
--
--  Safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  balin_lesson_blocks
--
--  A student with island access sees every published lesson, as before. A row
--  here closes one lesson for one student.
--
--  A block list rather than an allow list, on purpose: every installation that
--  already granted island access keeps working unchanged, and a lesson
--  published tomorrow reaches everyone who has the island without the admin
--  visiting each student. Closing a lesson keeps the student's progress in it;
--  removing the row reopens it exactly where they left off.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS balin_lesson_blocks (
    user_id    BIGINT UNSIGNED NOT NULL,
    lesson_id  INT UNSIGNED    NOT NULL,
    blocked_by BIGINT UNSIGNED NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_balin_block_lesson (lesson_id),
    CONSTRAINT fk_balin_block_user   FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_balin_block_lesson FOREIGN KEY (lesson_id)  REFERENCES balin_lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_block_admin  FOREIGN KEY (blocked_by) REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
