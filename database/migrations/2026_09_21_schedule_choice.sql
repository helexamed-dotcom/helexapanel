-- ============================================================================
--  Choosing classes across groups and terms
--
--  student_schedule_picks   the classes a student has taken, picked from the
--                           plans of every group of every term they are in.
--                           A student with no picks sees their own group plan.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS student_schedule_picks (
    user_id    BIGINT UNSIGNED   NOT NULL,
    item_id    INT UNSIGNED      NOT NULL,
    term_id    SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME          NOT NULL,
    PRIMARY KEY (user_id, item_id),
    KEY idx_schedule_picks_term (user_id, term_id),
    KEY idx_schedule_picks_item (item_id),
    CONSTRAINT fk_schedule_picks_user FOREIGN KEY (user_id) REFERENCES users(id)          ON DELETE CASCADE,
    CONSTRAINT fk_schedule_picks_item FOREIGN KEY (item_id) REFERENCES schedule_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_picks_term FOREIGN KEY (term_id) REFERENCES terms(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;