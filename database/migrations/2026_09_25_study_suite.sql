-- ============================================================================
--  The study suite
--
--  qb_marks.mark 'exam'   a question the student added to «آزمون‌های من».
--                         The collection files itself under the question's own
--                         درس / زیردرس / عنوان, so nothing else is stored.
--  qb_my_exams            one exam a student built from that collection.
--  qb_my_exam_answers     its answers; one row per question, blank until
--                         answered.
--  qb_reports             «گزارش اشکال»: a student's report on one question.
--  lesson_note            «درسنامه»: on a درس / زیردرس / عنوان (shown for every
--                         question under it) and, optionally, on one question.
--  activation_codes       single-use codes that activate a package.
--  study_marks            «درس‌های من»: what a student marked to read.
--
--  Additive and safe to re-run. Constraint names are unique across the whole
--  database (MySQL error 121 otherwise), hence fk_actcode_* rather than
--  fk_activation_*, which package_activations already uses.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE qb_marks
    MODIFY COLUMN mark ENUM('saved','review','exam') NOT NULL;

CREATE TABLE IF NOT EXISTS qb_my_exams (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)        NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    title            VARCHAR(191)    NOT NULL,
    subject_id       INT UNSIGNED    NULL,          -- the درس, or NULL for a mix
    question_ids     JSON            NOT NULL,      -- the order the exam shows them in
    negative_marking TINYINT(1)      NOT NULL DEFAULT 0,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = no timer
    status           ENUM('in_progress','finished') NOT NULL DEFAULT 'in_progress',
    correct_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    wrong_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    blank_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    score_percent    DECIMAL(6,2)    NULL,          -- with the chosen marking
    raw_percent      DECIMAL(6,2)    NULL,          -- plain correct / total
    started_at       DATETIME        NOT NULL,
    finished_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_my_exam_uuid (uuid),
    KEY idx_qb_my_exam_user (user_id, started_at),
    CONSTRAINT fk_qb_my_exam_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_my_exam_answers (
    exam_id     BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    option_id   BIGINT UNSIGNED NULL,
    is_correct  TINYINT(1)      NULL,
    answered_at DATETIME        NULL,
    PRIMARY KEY (exam_id, question_id),
    CONSTRAINT fk_qb_my_answer_exam FOREIGN KEY (exam_id) REFERENCES qb_my_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_reports (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    reason      ENUM('wrong_key','wrong_text','unclear','image','duplicate','other') NOT NULL DEFAULT 'other',
    body        TEXT            NULL,
    status      ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    admin_note  TEXT            NULL,
    handled_by  BIGINT UNSIGNED NULL,
    handled_at  DATETIME        NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_qb_reports_status (status, created_at),
    KEY idx_qb_reports_question (question_id),
    CONSTRAINT fk_qb_report_question FOREIGN KEY (question_id) REFERENCES qb_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_report_user     FOREIGN KEY (user_id)     REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_qb_report_admin    FOREIGN KEY (handled_by)  REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE qb_subjects  ADD COLUMN IF NOT EXISTS lesson_note MEDIUMTEXT NULL AFTER description;
ALTER TABLE qb_questions ADD COLUMN IF NOT EXISTS lesson_note MEDIUMTEXT NULL AFTER explanation_image;

CREATE TABLE IF NOT EXISTS activation_codes (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    code          VARCHAR(32)     NOT NULL,
    package_id    INT UNSIGNED    NOT NULL,
    duration_days SMALLINT UNSIGNED NULL,          -- access length once used; NULL = no end
    expires_at    DATETIME        NULL,            -- last moment the code can be used
    note          VARCHAR(191)    NULL,            -- who it is for, as the admin wrote it
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL,
    redeemed_by   BIGINT UNSIGNED NULL,
    redeemed_at   DATETIME        NULL,
    revoked_at    DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_code (code),
    KEY idx_activation_package (package_id),
    CONSTRAINT fk_actcode_package  FOREIGN KEY (package_id)  REFERENCES packages(id) ON DELETE CASCADE,
    CONSTRAINT fk_actcode_redeemer FOREIGN KEY (redeemed_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_actcode_creator  FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_marks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    kind       ENUM('content','library','qbank_topic','balin_lesson','course','custom') NOT NULL,
    ref_id     BIGINT UNSIGNED NULL,
    title      VARCHAR(191)    NOT NULL,
    url        VARCHAR(255)    NULL,
    note       VARCHAR(500)    NULL,
    due_date   DATE            NULL,
    done_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_study_marks_user (user_id, done_at, due_date),
    KEY idx_study_marks_ref (user_id, kind, ref_id),
    CONSTRAINT fk_study_marks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
