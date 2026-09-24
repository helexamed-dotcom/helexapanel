-- ============================================================================
--  فلش‌کارت — Flashcards
--
--  Two kinds of deck share one table:
--    * course sessions — the admin's ready-made cards, grouped under a course
--      (درس «زبان» → جلسه ۱ … جلسه ۲۴), granted to students per course;
--    * personal decks  — a student's own cards, visible to nobody else.
--
--  Wholly additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

-- =====================================================================
--  درس فلش‌کارت (admin-owned)
-- =====================================================================
CREATE TABLE IF NOT EXISTS fc_courses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    title       VARCHAR(191) NOT NULL,
    description VARCHAR(500) NULL,
    -- A name from a fixed palette and a single emoji, not raw CSS or markup:
    -- the student hub paints each course card from these two values.
    color       VARCHAR(16)  NOT NULL DEFAULT 'blue',
    icon        VARCHAR(16)  NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_course_uuid (uuid),
    UNIQUE KEY uq_fc_course_title (title),
    KEY idx_fc_course_status (status, sort_order),
    CONSTRAINT fk_fc_course_author FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  دسته کارت: a course session, or a student's personal deck
-- =====================================================================
--
--  Exactly one of course_id / owner_id is set. A CHECK would say so, but older
--  MySQL parses CHECK and ignores it, so the rule lives in the repository —
--  the only code that inserts here — and this comment records it.
--
CREATE TABLE IF NOT EXISTS fc_decks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    course_id   INT UNSIGNED NULL,
    owner_id    BIGINT UNSIGNED NULL,
    title       VARCHAR(191) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_deck_uuid (uuid),
    KEY idx_fc_deck_course (course_id, sort_order),
    KEY idx_fc_deck_owner  (owner_id, created_at),
    CONSTRAINT fk_fc_deck_course FOREIGN KEY (course_id)
        REFERENCES fc_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_deck_owner FOREIGN KEY (owner_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  کارت
-- =====================================================================
CREATE TABLE IF NOT EXISTS fc_cards (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    deck_id     INT UNSIGNED NOT NULL,
    front       TEXT         NOT NULL,
    back        TEXT         NOT NULL,
    hint        VARCHAR(500) NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_card_uuid (uuid),
    KEY idx_fc_card_deck (deck_id, sort_order, id),
    CONSTRAINT fk_fc_card_deck FOREIGN KEY (deck_id)
        REFERENCES fc_decks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  وضعیت مرور هر کارت برای هر دانشجو (spaced repetition)
-- =====================================================================
--
--  No row means "new". Progress lives per student, never on the card, so a
--  course session is shared by a hundred students without any of them seeing
--  another's schedule — and editing a card's text keeps everyone's progress.
--
--  ease is stored ×100 (250 = 2.5) so it stays an integer.
--
CREATE TABLE IF NOT EXISTS fc_progress (
    user_id          BIGINT UNSIGNED NOT NULL,
    card_id          BIGINT UNSIGNED NOT NULL,
    reps             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lapses           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ease             SMALLINT UNSIGNED NOT NULL DEFAULT 250,
    interval_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    due_at           DATETIME     NOT NULL,
    last_rating      TINYINT UNSIGNED NULL,
    last_reviewed_at DATETIME     NULL,
    starred          TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, card_id),
    KEY idx_fc_progress_due (user_id, due_at),
    KEY idx_fc_progress_card (card_id),
    CONSTRAINT fk_fc_progress_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_progress_card FOREIGN KEY (card_id)
        REFERENCES fc_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per review, for "reviewed today" and the day streak. The progress
-- row only knows the latest review, and a streak needs every day.
CREATE TABLE IF NOT EXISTS fc_review_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    card_id     BIGINT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    reviewed_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fc_log_user_time (user_id, reviewed_at),
    CONSTRAINT fk_fc_log_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_log_card FOREIGN KEY (card_id)
        REFERENCES fc_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  دسترسی دانشجو — per course, never per session
-- =====================================================================
--
--  The admin grants «زبان», not «زبان · جلسه ۷»: a new session added to the
--  course later reaches everyone who holds the course, with no second grant.
--  A row means access; no row means none.
--
CREATE TABLE IF NOT EXISTS fc_access (
    user_id    BIGINT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    granted_at DATETIME     NOT NULL,
    PRIMARY KEY (user_id, course_id),
    KEY idx_fc_access_course (course_id),
    CONSTRAINT fk_fc_access_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_access_course FOREIGN KEY (course_id)
        REFERENCES fc_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_access_admin FOREIGN KEY (granted_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Permissions and settings
-- =====================================================================
INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('flashcards.manage',          'مدیریت فلش‌کارت‌ها',              'flashcards', NOW()),
    ('flashcards.manage_students', 'مدیریت دسترسی فلش‌کارت دانشجویان', 'flashcards', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'flashcards';

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    -- Ceilings on what one student can store, so personal decks cannot be
    -- used to fill the database.
    ('fc_user_deck_limit',  '60',   'int', 0, NOW()),
    ('fc_user_card_limit',  '3000', 'int', 0, NOW()),
    ('fc_import_max_rows',  '2000', 'int', 0, NOW()),
    -- How many new cards one study session introduces by default.
    ('fc_new_per_session',  '20',   'int', 0, NOW());
