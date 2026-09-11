-- =====================================================================
--  🏝️ جزیره بالین — Balin Island
--
--  Run this from the CLI, never through phpMyAdmin's web importer:
--
--      mysql -u USER -p helexamed < 2026_09_13_balin_island.sql
--
--  Everything here is additive. No existing table is altered or dropped,
--  so the migration is safe to run on a live installation and the only
--  thing a rollback needs is DROP TABLE on the balin_ prefix plus the two
--  DELETEs at the bottom of this comment block:
--
--      DELETE FROM permissions WHERE module = 'balin';
--      DELETE FROM settings WHERE setting_key LIKE 'balin\_%';
--
--  Settings live in the project's existing `settings` table under a
--  balin_ prefix rather than a private balin_settings table: the project
--  already has typed, cached settings and the spec's own rule is to
--  extend what exists instead of building a parallel copy.
-- =====================================================================

-- The client character set must be declared before any Persian literal
-- below. Without it a CLI whose default is latin1 stores the UTF-8 bytes
-- a second time over, and every seeded string arrives double-encoded.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Content: lesson → stage → block
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_lessons (
    id                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid               CHAR(36)        NOT NULL,
    slug               VARCHAR(120)    NOT NULL,
    title              VARCHAR(191)    NOT NULL,
    description        TEXT            NULL,
    cover_path         VARCHAR(255)    NULL,
    icon               VARCHAR(32)     NULL,          -- emoji or icon name
    color              VARCHAR(16)     NULL,
    display_order      INT UNSIGNED    NOT NULL DEFAULT 1000,
    status             ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    estimated_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    xp_reward          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    extra_notes        TEXT            NULL,
    -- version guards concurrent admin edits; content_version guards student
    -- progress recorded against an older revision of the same lesson.
    version            INT UNSIGNED    NOT NULL DEFAULT 1,
    content_version    INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NULL,
    published_at       DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_lesson_uuid (uuid),
    UNIQUE KEY uq_balin_lesson_slug (slug),
    KEY idx_balin_lesson_order (status, display_order),
    CONSTRAINT fk_balin_lesson_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_stages (
    id                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid               CHAR(36)        NOT NULL,
    lesson_id          INT UNSIGNED    NOT NULL,
    title              VARCHAR(191)    NOT NULL,
    subtitle           VARCHAR(191)    NULL,
    description        TEXT            NULL,
    -- Gaps of 1000 so inserting between two stages usually rewrites one row
    -- instead of renumbering the whole lesson.
    display_order      INT UNSIGNED    NOT NULL DEFAULT 1000,
    status             ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    is_final_case      TINYINT(1)      NOT NULL DEFAULT 0,
    xp_reward          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    version            INT UNSIGNED    NOT NULL DEFAULT 1,
    content_version    INT UNSIGNED    NOT NULL DEFAULT 1,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_stage_uuid (uuid),
    KEY idx_balin_stage_lesson (lesson_id, display_order),
    CONSTRAINT fk_balin_stage_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teachers and the fictional students who carry the dialogue. These are
-- teaching props, never real accounts — no link to users on purpose.
CREATE TABLE IF NOT EXISTS balin_characters (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)      NOT NULL,
    name           VARCHAR(120)  NOT NULL,
    char_type      ENUM('teacher','student','doctor','patient','nurse','other') NOT NULL DEFAULT 'student',
    gender         ENUM('male','female') NOT NULL DEFAULT 'male',
    icon           VARCHAR(32)   NULL,
    svg_path       VARCHAR(255)  NULL,          -- relative to public_html/assets/
    side           ENUM('left','right') NOT NULL DEFAULT 'left',
    color          VARCHAR(16)   NULL,
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    display_order  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    version        INT UNSIGNED  NOT NULL DEFAULT 1,
    created_at     DATETIME      NOT NULL,
    updated_at     DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_character_uuid (uuid),
    KEY idx_balin_character_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_media (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)        NOT NULL,
    kind          ENUM('image','audio','video','file') NOT NULL,
    visibility    ENUM('public','private') NOT NULL DEFAULT 'private',
    storage_path  VARCHAR(255)    NOT NULL,     -- relative to its storage root
    original_name VARCHAR(191)    NULL,
    mime          VARCHAR(96)     NOT NULL,
    byte_size     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Required for images by the accessibility rule; enforced in the service
    -- layer because audio and video legitimately have none.
    alt_text      VARCHAR(255)    NULL,
    caption       VARCHAR(255)    NULL,
    transcript    TEXT            NULL,
    width_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    position      ENUM('start','center','end') NOT NULL DEFAULT 'center',
    allow_download TINYINT(1)     NOT NULL DEFAULT 0,
    checksum      CHAR(64)        NULL,
    uploaded_by   BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_media_uuid (uuid),
    KEY idx_balin_media_kind (kind),
    CONSTRAINT fk_balin_media_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Clinical skill tracks — cross-lesson, distinct from the per-lesson
--  skills below. A question may be tagged with several of them.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_skill_tracks (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)     NOT NULL,
    name          VARCHAR(120) NOT NULL,
    name_en       VARCHAR(120) NULL,
    slug          VARCHAR(120) NOT NULL,
    icon          VARCHAR(32)  NULL,
    color         VARCHAR(16)  NULL,
    description   TEXT         NULL,
    category      ENUM('clinical_reasoning','procedural','communication','documentation','professionalism')
                  NOT NULL DEFAULT 'clinical_reasoning',
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    -- Below this many answered tagged questions the profile shows
    -- "not enough data" rather than a percentage from a tiny sample.
    min_questions_for_reliable_mastery SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    badge_thresholds JSON       NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    version       INT UNSIGNED NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_skill_track_uuid (uuid),
    UNIQUE KEY uq_balin_skill_track_slug (slug),
    KEY idx_balin_skill_track_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-lesson skill (ECG inside cardiology). Deliberately a different table
-- from balin_skill_tracks: the two answer different questions and mixing
-- them would make either one meaningless.
CREATE TABLE IF NOT EXISTS balin_skills (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id     INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    slug          VARCHAR(120) NOT NULL,
    icon          VARCHAR(32)  NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_skill_lesson_slug (lesson_id, slug),
    CONSTRAINT fk_balin_skill_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Questions
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_questions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    lesson_id       INT UNSIGNED NOT NULL,
    -- Null for a question that lives only in a checkpoint exam pool.
    stage_id        INT UNSIGNED NULL,
    skill_id        SMALLINT UNSIGNED NULL,       -- per-lesson skill
    prompt          TEXT         NOT NULL,
    explanation     TEXT         NULL,
    hint            TEXT         NULL,
    difficulty      ENUM('easy','medium','hard','expert') NOT NULL DEFAULT 'medium',
    xp_reward       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    is_required     TINYINT(1)   NOT NULL DEFAULT 1,
    is_final_case_step TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_question_uuid (uuid),
    KEY idx_balin_question_lesson (lesson_id, status),
    KEY idx_balin_question_stage (stage_id),
    KEY idx_balin_question_difficulty (difficulty),
    CONSTRAINT fk_balin_question_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_question_stage  FOREIGN KEY (stage_id)  REFERENCES balin_stages(id)  ON DELETE SET NULL,
    CONSTRAINT fk_balin_question_skill  FOREIGN KEY (skill_id)  REFERENCES balin_skills(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_question_options (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id   INT UNSIGNED NOT NULL,
    label         VARCHAR(8)   NOT NULL,          -- A, B, C ...
    body          TEXT         NOT NULL,
    is_correct    TINYINT(1)   NOT NULL DEFAULT 0,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Branching is schema-ready but not switched on: an option may point at
    -- the block the scenario should continue from.
    next_block_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_balin_option_question (question_id, display_order),
    CONSTRAINT fk_balin_option_question FOREIGN KEY (question_id) REFERENCES balin_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_question_skill_tags (
    question_id    INT UNSIGNED NOT NULL,
    skill_track_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (question_id, skill_track_id),
    KEY idx_balin_tag_track (skill_track_id),
    CONSTRAINT fk_balin_tag_question FOREIGN KEY (question_id)    REFERENCES balin_questions(id)    ON DELETE CASCADE,
    CONSTRAINT fk_balin_tag_track    FOREIGN KEY (skill_track_id) REFERENCES balin_skill_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Checkpoint exams
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_checkpoint_exams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    lesson_id       INT UNSIGNED NOT NULL,
    position_type   ENUM('before_stage','after_stage','after_lesson') NOT NULL DEFAULT 'after_stage',
    anchor_stage_id INT UNSIGNED NULL,
    title           VARCHAR(191) NOT NULL,
    description     TEXT         NULL,
    primary_skill_track_id   SMALLINT UNSIGNED NULL,
    secondary_skill_track_ids JSON NULL,
    question_source_mode ENUM('fixed_list','random_pool') NOT NULL DEFAULT 'fixed_list',
    num_questions   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    pass_threshold_percent TINYINT UNSIGNED NOT NULL DEFAULT 70,
    is_gating       TINYINT(1)   NOT NULL DEFAULT 0,
    max_attempts    SMALLINT UNSIGNED NULL,       -- NULL = unlimited
    cooldown_hours_between_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    time_limit_minutes SMALLINT UNSIGNED NULL,
    xp_reward       SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    difficulty_mix  JSON         NULL,
    display_order   INT UNSIGNED NOT NULL DEFAULT 1000,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    content_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_exam_uuid (uuid),
    KEY idx_balin_exam_lesson (lesson_id, display_order),
    KEY idx_balin_exam_anchor (anchor_stage_id, position_type),
    CONSTRAINT fk_balin_exam_lesson FOREIGN KEY (lesson_id)       REFERENCES balin_lessons(id)      ON DELETE CASCADE,
    CONSTRAINT fk_balin_exam_anchor FOREIGN KEY (anchor_stage_id) REFERENCES balin_stages(id)       ON DELETE CASCADE,
    CONSTRAINT fk_balin_exam_track  FOREIGN KEY (primary_skill_track_id) REFERENCES balin_skill_tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_checkpoint_exam_questions (
    exam_id       INT UNSIGNED NOT NULL,
    question_id   INT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (exam_id, question_id),
    KEY idx_balin_exam_q_order (exam_id, display_order),
    CONSTRAINT fk_balin_examq_exam     FOREIGN KEY (exam_id)     REFERENCES balin_checkpoint_exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_examq_question FOREIGN KEY (question_id) REFERENCES balin_questions(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_checkpoint_attempts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    exam_id        INT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    -- The pool drawn for this attempt, so a resumed attempt asks the same
    -- questions and a later attempt can be shown to have asked others.
    question_ids   JSON         NULL,
    score_percent  DECIMAL(5,2) NULL,
    correct_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    passed         TINYINT(1)   NULL,
    started_at     DATETIME     NOT NULL,
    completed_at   DATETIME     NULL,
    expires_at     DATETIME     NULL,
    is_preview     TINYINT(1)   NOT NULL DEFAULT 0,
    idempotency_key CHAR(64)    NULL,
    content_version_at_completion INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_attempt_uuid (uuid),
    UNIQUE KEY uq_balin_attempt_number (user_id, exam_id, attempt_number),
    UNIQUE KEY uq_balin_attempt_idem (idempotency_key),
    KEY idx_balin_attempt_user_exam (user_id, exam_id),
    CONSTRAINT fk_balin_attempt_user FOREIGN KEY (user_id) REFERENCES users(id)                  ON DELETE CASCADE,
    CONSTRAINT fk_balin_attempt_exam FOREIGN KEY (exam_id) REFERENCES balin_checkpoint_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Blocks — the ordered contents of a stage. Declared after questions and
--  exams because it points at both.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_blocks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    stage_id        INT UNSIGNED NOT NULL,
    block_type      ENUM('chat','question','image','audio','video','text','finding','hint',
                         'warning','system','divider','checkpoint_anchor') NOT NULL,
    display_order   INT UNSIGNED NOT NULL DEFAULT 1000,
    is_required     TINYINT(1)   NOT NULL DEFAULT 1,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    character_id    SMALLINT UNSIGNED NULL,
    side_override   ENUM('left','right') NULL,
    body            TEXT         NULL,
    media_id        INT UNSIGNED NULL,
    question_id     INT UNSIGNED NULL,
    checkpoint_exam_id INT UNSIGNED NULL,
    animation       VARCHAR(32)  NULL,
    settings        JSON         NULL,
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_block_uuid (uuid),
    KEY idx_balin_block_stage (stage_id, display_order),
    CONSTRAINT fk_balin_block_stage     FOREIGN KEY (stage_id)     REFERENCES balin_stages(id)     ON DELETE CASCADE,
    CONSTRAINT fk_balin_block_character FOREIGN KEY (character_id) REFERENCES balin_characters(id) ON DELETE SET NULL,
    CONSTRAINT fk_balin_block_media     FOREIGN KEY (media_id)     REFERENCES balin_media(id)      ON DELETE SET NULL,
    CONSTRAINT fk_balin_block_question  FOREIGN KEY (question_id)  REFERENCES balin_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_balin_block_exam      FOREIGN KEY (checkpoint_exam_id) REFERENCES balin_checkpoint_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Level titles
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_rank_tiers (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    min_level     SMALLINT UNSIGNED NOT NULL,
    max_level     SMALLINT UNSIGNED NOT NULL,
    title         VARCHAR(120) NOT NULL,
    icon          VARCHAR(32)  NULL,
    color         VARCHAR(16)  NULL,
    description   VARCHAR(255) NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    version       INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_tier_range (min_level, max_level),
    KEY idx_balin_tier_lookup (min_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Student state
-- ---------------------------------------------------------------------

-- Access defaults to deny: a student with no row here has no access.
CREATE TABLE IF NOT EXISTS balin_student_access (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    is_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    granted_by  BIGINT UNSIGNED NULL,
    granted_at  DATETIME     NOT NULL,
    revoked_at  DATETIME     NULL,
    note        VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_access_user (user_id),
    CONSTRAINT fk_balin_access_user  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_access_admin FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_progress (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    lesson_id      INT UNSIGNED NOT NULL,
    stage_id       INT UNSIGNED NOT NULL,
    status         ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
    last_block_id  INT UNSIGNED NULL,
    started_at     DATETIME     NOT NULL,
    last_activity_at DATETIME   NOT NULL,
    completed_at   DATETIME     NULL,
    content_version_at_completion INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_progress_stage (user_id, stage_id),
    KEY idx_balin_progress_lesson (user_id, lesson_id),
    CONSTRAINT fk_balin_progress_user   FOREIGN KEY (user_id)   REFERENCES users(id)          ON DELETE CASCADE,
    CONSTRAINT fk_balin_progress_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id)  ON DELETE CASCADE,
    CONSTRAINT fk_balin_progress_stage  FOREIGN KEY (stage_id)  REFERENCES balin_stages(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One answer per question per student, forever. The unique key is the rule
-- itself, not a hint: the second insert loses the race and is reported as
-- "already answered" rather than being allowed to award XP twice.
CREATE TABLE IF NOT EXISTS balin_answers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    question_id   INT UNSIGNED NOT NULL,
    lesson_id     INT UNSIGNED NOT NULL,
    stage_id      INT UNSIGNED NULL,
    attempt_id    BIGINT UNSIGNED NULL,          -- set when answered inside a checkpoint exam
    option_id     INT UNSIGNED NULL,
    is_correct    TINYINT(1)   NOT NULL DEFAULT 0,
    used_hint     TINYINT(1)   NOT NULL DEFAULT 0,
    xp_awarded    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    difficulty    ENUM('easy','medium','hard','expert') NOT NULL DEFAULT 'medium',
    weight        DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    time_spent_ms INT UNSIGNED NULL,
    content_version_at_completion INT UNSIGNED NULL,
    idempotency_key CHAR(64)   NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_answer_once (user_id, question_id),
    UNIQUE KEY uq_balin_answer_idem (idempotency_key),
    KEY idx_balin_answer_lesson (user_id, lesson_id),
    KEY idx_balin_answer_attempt (attempt_id),
    KEY idx_balin_answer_question (question_id, is_correct),
    CONSTRAINT fk_balin_answer_user     FOREIGN KEY (user_id)     REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_balin_answer_question FOREIGN KEY (question_id) REFERENCES balin_questions(id)   ON DELETE CASCADE,
    CONSTRAINT fk_balin_answer_lesson   FOREIGN KEY (lesson_id)   REFERENCES balin_lessons(id)     ON DELETE CASCADE,
    CONSTRAINT fk_balin_answer_option   FOREIGN KEY (option_id)   REFERENCES balin_question_options(id) ON DELETE SET NULL,
    CONSTRAINT fk_balin_answer_attempt  FOREIGN KEY (attempt_id)  REFERENCES balin_checkpoint_attempts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The XP ledger. Every balance in the product is derived from it, so a
-- corrected or deleted row corrects every derived number on the next read.
CREATE TABLE IF NOT EXISTS balin_xp_transactions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    amount         INT          NOT NULL,
    type           ENUM('question_correct','lesson_complete','stage_complete','final_case',
                        'daily_mission','weekly_mission','achievement','checkpoint_exam_passed',
                        'admin_adjustment') NOT NULL,
    source_type    VARCHAR(32)  NULL,
    source_id      BIGINT UNSIGNED NULL,
    competition_id INT UNSIGNED NULL,
    metadata       JSON         NULL,
    idempotency_key CHAR(64)    NULL,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_xp_idem (idempotency_key),
    KEY idx_balin_xp_user_time (user_id, created_at),
    KEY idx_balin_xp_competition (competition_id, user_id),
    CONSTRAINT fk_balin_xp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Display cache only. Recomputed from the ledger whenever XP changes, and
-- never the source of truth for anything.
CREATE TABLE IF NOT EXISTS balin_user_stats (
    user_id          BIGINT UNSIGNED NOT NULL,
    total_xp         INT UNSIGNED NOT NULL DEFAULT 0,
    cached_level     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    answered_count   INT UNSIGNED NOT NULL DEFAULT 0,
    correct_count    INT UNSIGNED NOT NULL DEFAULT 0,
    accuracy_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    stages_completed INT UNSIGNED NOT NULL DEFAULT 0,
    lessons_completed INT UNSIGNED NOT NULL DEFAULT 0,
    cached_at        DATETIME     NOT NULL,
    PRIMARY KEY (user_id),
    KEY idx_balin_stats_xp (total_xp),
    CONSTRAINT fk_balin_stats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_skill_mastery (
    user_id        BIGINT UNSIGNED NOT NULL,
    skill_track_id SMALLINT UNSIGNED NOT NULL,
    mastery_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    weighted_correct DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    weight_total   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    cached_at      DATETIME     NOT NULL,
    PRIMARY KEY (user_id, skill_track_id),
    KEY idx_balin_skill_mastery_track (skill_track_id, mastery_percent),
    CONSTRAINT fk_balin_skillmastery_user  FOREIGN KEY (user_id)        REFERENCES users(id)              ON DELETE CASCADE,
    CONSTRAINT fk_balin_skillmastery_track FOREIGN KEY (skill_track_id) REFERENCES balin_skill_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_skill_progress (
    user_id        BIGINT UNSIGNED NOT NULL,
    skill_id       SMALLINT UNSIGNED NOT NULL,
    mastery_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cached_at      DATETIME     NOT NULL,
    PRIMARY KEY (user_id, skill_id),
    CONSTRAINT fk_balin_skillprog_user  FOREIGN KEY (user_id)  REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_balin_skillprog_skill FOREIGN KEY (skill_id) REFERENCES balin_skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_lesson_mastery (
    user_id        BIGINT UNSIGNED NOT NULL,
    lesson_id      INT UNSIGNED NOT NULL,
    mastery_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    correct_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cached_at      DATETIME     NOT NULL,
    PRIMARY KEY (user_id, lesson_id),
    CONSTRAINT fk_balin_lessonmastery_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_balin_lessonmastery_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per active day. The unique key makes a double-count impossible
-- however many times the day's first activity is replayed.
CREATE TABLE IF NOT EXISTS balin_streaks (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    streak_date DATE         NOT NULL,           -- already normalised to the institution timezone
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_streak_day (user_id, streak_date),
    CONSTRAINT fk_balin_streak_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_user_streak_state (
    user_id          BIGINT UNSIGNED NOT NULL,
    current_streak   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_active_date DATE     NULL,
    freezes_available TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_balin_streakstate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Achievements, missions, competition
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS balin_achievements (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug           VARCHAR(80)  NOT NULL,
    title          VARCHAR(120) NOT NULL,
    description    VARCHAR(255) NULL,
    icon           VARCHAR(32)  NULL,
    category       VARCHAR(40)  NULL,
    -- rule_type says which counter the thresholds are read against; the
    -- engine refuses a slug it does not know rather than guessing.
    rule_type      ENUM('first_answer','correct_answers','stages_completed','lessons_completed',
                        'streak_days','skill_track_mastery','checkpoint_first_pass','weekly_champion')
                   NOT NULL,
    rule_config    JSON         NULL,
    tier_thresholds JSON        NULL,            -- {"bronze":10,"silver":30,"gold":75,"platinum":150}
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    display_order  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_achievement_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_achievements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    achievement_id SMALLINT UNSIGNED NOT NULL,
    tier           ENUM('bronze','silver','gold','platinum') NOT NULL DEFAULT 'bronze',
    unlocked_at    DATETIME     NOT NULL,
    metadata       JSON         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_student_achievement (user_id, achievement_id, tier),
    CONSTRAINT fk_balin_sa_user        FOREIGN KEY (user_id)        REFERENCES users(id)               ON DELETE CASCADE,
    CONSTRAINT fk_balin_sa_achievement FOREIGN KEY (achievement_id) REFERENCES balin_achievements(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_missions (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(60)  NOT NULL,
    mission_type  ENUM('daily','weekly') NOT NULL,
    title         VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    rules         JSON         NOT NULL,         -- {"answers":3,"correct":0,"stages":1}
    xp_reward     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_mission_slug (slug),
    KEY idx_balin_mission_active (mission_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_student_missions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    mission_id  SMALLINT UNSIGNED NOT NULL,
    period_key  VARCHAR(16)  NOT NULL,           -- 2026-09-10 for daily, 2026-W37 for weekly
    progress    JSON         NULL,
    completed_at DATETIME    NULL,
    xp_awarded  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_student_mission (user_id, mission_id, period_key),
    CONSTRAINT fk_balin_sm_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_balin_sm_mission FOREIGN KEY (mission_id) REFERENCES balin_missions(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_competitions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)     NOT NULL,
    title         VARCHAR(191) NOT NULL,
    description   TEXT         NULL,
    start_date    DATETIME     NOT NULL,
    end_date      DATETIME     NOT NULL,
    status        ENUM('scheduled','active','ended','cancelled') NOT NULL DEFAULT 'scheduled',
    rules         JSON         NULL,
    leaderboard_visibility ENUM('public','participants','admins') NOT NULL DEFAULT 'public',
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_competition_uuid (uuid),
    KEY idx_balin_competition_status (status, start_date),
    CONSTRAINT fk_balin_competition_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_competition_xp (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    competition_id INT UNSIGNED NOT NULL,
    xp             INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_competition_xp (user_id, competition_id),
    KEY idx_balin_compxp_rank (competition_id, xp),
    CONSTRAINT fk_balin_compxp_user        FOREIGN KEY (user_id)        REFERENCES users(id)               ON DELETE CASCADE,
    CONSTRAINT fk_balin_compxp_competition FOREIGN KEY (competition_id) REFERENCES balin_competitions(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_rewards (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    competition_id INT UNSIGNED NULL,
    title          VARCHAR(191) NOT NULL,
    description    TEXT         NULL,
    rank_position  SMALLINT UNSIGNED NULL,
    winner_user_id BIGINT UNSIGNED NULL,
    status         ENUM('draft','assigned','delivered','cancelled') NOT NULL DEFAULT 'draft',
    admin_note     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_reward_uuid (uuid),
    KEY idx_balin_reward_competition (competition_id, rank_position),
    CONSTRAINT fk_balin_reward_competition FOREIGN KEY (competition_id) REFERENCES balin_competitions(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_reward_winner      FOREIGN KEY (winner_user_id) REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rebuilt from the ledger on a schedule. A hand-edited row here is
-- overwritten by the next rebuild, which is the point.
CREATE TABLE IF NOT EXISTS balin_leaderboard_snapshot (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_type   ENUM('overall_xp','weekly_xp','lesson','mastery','streak','skill') NOT NULL,
    scope_id     INT UNSIGNED NULL,              -- lesson id, skill track id, competition id
    user_id      BIGINT UNSIGNED NOT NULL,
    score        DECIMAL(12,2) NOT NULL DEFAULT 0,
    rank_position INT UNSIGNED NOT NULL,
    generated_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balin_board_user (board_type, scope_id, user_id),
    KEY idx_balin_board_rank (board_type, scope_id, rank_position),
    CONSTRAINT fk_balin_board_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_status_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    BIGINT UNSIGNED NULL,
    from_status VARCHAR(24)  NOT NULL,
    to_status   VARCHAR(24)  NOT NULL,
    note        VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_balin_status_time (created_at),
    CONSTRAINT fk_balin_status_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Permissions
-- =====================================================================

INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('balin.view',                     'مشاهده جزیره بالین',            'balin', NOW()),
    ('balin.create',                   'ساخت محتوای بالین',              'balin', NOW()),
    ('balin.edit',                     'ویرایش محتوای بالین',            'balin', NOW()),
    ('balin.delete',                   'حذف محتوای بالین',               'balin', NOW()),
    ('balin.publish',                  'انتشار محتوای بالین',            'balin', NOW()),
    ('balin.manage_students',          'مدیریت دسترسی دانشجویان بالین',  'balin', NOW()),
    ('balin.manage_characters',        'مدیریت شخصیت‌ها',                'balin', NOW()),
    ('balin.manage_questions',         'مدیریت سؤالات',                  'balin', NOW()),
    ('balin.manage_competition',       'مدیریت رقابت هفتگی',             'balin', NOW()),
    ('balin.manage_rewards',           'مدیریت جوایز',                   'balin', NOW()),
    ('balin.view_statistics',          'مشاهده آمار بالین',              'balin', NOW()),
    ('balin.manage_settings',          'مدیریت تنظیمات بالین',           'balin', NOW()),
    ('balin.manage_skill_tracks',      'مدیریت مهارت‌های بالینی',        'balin', NOW()),
    ('balin.manage_checkpoint_exams',  'مدیریت آزمون‌های بین‌مرحله‌ای',   'balin', NOW()),
    ('balin.manage_rank_titles',       'مدیریت عنوان سطح‌ها',            'balin', NOW());

-- The super admin role holds every permission, including ones added later.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'balin';

-- =====================================================================
--  Settings
-- =====================================================================

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    -- coming_soon | published | maintenance | disabled
    ('balin_status',              'coming_soon', 'string', 1, NOW()),
    ('balin_coming_soon_text',    'جزیره بالین قراره به زودی در هلکسا منتشر بشه؛ آماده باش رفیق، هنوز مسیر بزرگی در پیش داریم. 🏝️', 'string', 1, NOW()),
    ('balin_maintenance_text',    'جزیره بالین در حال به‌روزرسانی است. به‌زودی برمی‌گردیم.', 'string', 1, NOW()),
    ('balin_maintenance_eta',     '',            'string', 1, NOW()),
    ('balin_timezone',            'Asia/Tehran', 'string', 0, NOW()),
    ('balin_xp_per_correct',      '10',          'int',    0, NOW()),
    ('balin_hint_penalty_percent','50',          'int',    0, NOW()),
    ('balin_weight_easy',         '1',           'int',    0, NOW()),
    ('balin_weight_medium',       '2',           'int',    0, NOW()),
    ('balin_weight_hard',         '3',           'int',    0, NOW()),
    ('balin_weight_expert',       '4',           'int',    0, NOW()),
    ('balin_final_case_multiplier','1.5',        'string', 0, NOW()),
    -- repeat_last | admin_defined : what happens to rank titles past the last tier
    ('balin_rank_overflow_mode',  'repeat_last', 'string', 0, NOW()),
    ('balin_leaderboard_page_size','25',         'int',    0, NOW()),
    ('balin_leaderboard_rebuild_minutes','15',   'int',    0, NOW()),
    -- empty | last_ended : what a student sees when no competition is running
    ('balin_no_competition_mode', 'empty',       'string', 0, NOW()),
    ('balin_answer_rate_per_minute','20',        'int',    0, NOW()),
    ('balin_preview_ttl_minutes', '60',          'int',    0, NOW()),
    ('balin_media_max_image_mb',  '4',           'int',    0, NOW()),
    ('balin_media_max_audio_mb',  '15',          'int',    0, NOW()),
    ('balin_media_max_video_mb',  '60',          'int',    0, NOW());

-- =====================================================================
--  Seed data
-- =====================================================================

-- Twenty tiers of ten levels each. The display title is built at runtime as
-- "<title> · قدم <n>", so adding a tier never needs a code change.
INSERT IGNORE INTO balin_rank_tiers (min_level, max_level, title, icon, display_order) VALUES
    (1,   10,  'تازه‌وارد جزیره',        '🌱', 1),
    (11,  20,  'دانشجوی مقدماتی',        '📘', 2),
    (21,  30,  'دانشجوی بالینی',         '🩺', 3),
    (31,  40,  'کارآموز',                '🧪', 4),
    (41,  50,  'کارآموز کارکشته',        '🔬', 5),
    (51,  60,  'انترن',                  '💉', 6),
    (61,  70,  'انترن ارشد',             '🩻', 7),
    (71,  80,  'دستیار بالینی',          '📋', 8),
    (81,  90,  'رزیدنت سال اول',         '🫀', 9),
    (91,  100, 'رزیدنت میانی',           '🧠', 10),
    (101, 110, 'رزیدنت ارشد',            '🫁', 11),
    (111, 120, 'چیف رزیدنت',             '🦠', 12),
    (121, 130, 'فلوی بالینی',            '🧬', 13),
    (131, 140, 'متخصص جوان',             '🎓', 14),
    (141, 150, 'متخصص بالینی',           '⚕️', 15),
    (151, 160, 'متخصص ارشد',             '🏥', 16),
    (161, 170, 'استاد بالینی',           '📖', 17),
    (171, 180, 'استاد برجسته هلکسا',     '🌟', 18),
    (181, 190, 'افسانه جزیره بالین',     '🏝️', 19),
    (191, 200, 'اسطوره بالینی هلکسا',    '👑', 20);

INSERT IGNORE INTO balin_skill_tracks
    (uuid, name, name_en, slug, icon, category, display_order, badge_thresholds, is_active, created_at) VALUES
    (UUID(), 'شرح‌حال‌گیری',            'History Taking',            'history-taking',      '🗣️', 'clinical_reasoning', 1,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'معاینه بالینی',           'Physical Examination',      'physical-exam',       '🩺', 'procedural',         2,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'تشخیص افتراقی',           'Differential Diagnosis',    'differential',        '🧠', 'clinical_reasoning', 3,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'درخواست منطقی آزمایش',    'Rational Test Ordering',    'test-ordering',       '🧪', 'clinical_reasoning', 4,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'تفسیر پاراکلینیک',        'Lab & Imaging Interpretation', 'interpretation',   '🩻', 'clinical_reasoning', 5,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'تصمیم‌گیری درمانی',       'Treatment Planning',        'treatment-planning',  '💊', 'clinical_reasoning', 6,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'اولویت‌بندی اورژانسی',    'Triage & Emergency',        'triage',              '🚨', 'clinical_reasoning', 7,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'ارتباط با بیمار',         'Patient Communication',     'patient-communication','🤝', 'communication',     8,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'مستندسازی بالینی',        'Clinical Documentation',    'documentation',       '📝', 'documentation',      9,  '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'مهارت‌های عملی',          'Procedural Skills',         'procedural-skills',   '🔧', 'procedural',         10, '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'کار تیمی و ارجاع',        'Teamwork & Referral',       'teamwork',            '👥', 'communication',      11, '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW()),
    (UUID(), 'اخلاق حرفه‌ای پزشکی',     'Medical Professionalism',   'professionalism',     '⚖️', 'professionalism',    12, '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 1, NOW());

INSERT IGNORE INTO balin_characters (uuid, name, char_type, gender, icon, side, is_active, display_order, created_at) VALUES
    ('ba110000-0000-4000-8000-000000000001', 'دکتر آریان‌فر', 'teacher', 'male',   '🧑‍⚕️', 'right', 1, 1, NOW()),
    ('ba110000-0000-4000-8000-000000000002', 'سارا',          'student', 'female', '👩‍🎓', 'left',  1, 2, NOW()),
    ('ba110000-0000-4000-8000-000000000003', 'نیما',          'student', 'male',   '👨‍🎓', 'left',  1, 3, NOW()),
    ('ba110000-0000-4000-8000-000000000004', 'مریم',          'student', 'female', '👩‍🎓', 'left',  1, 4, NOW()),
    ('ba110000-0000-4000-8000-000000000005', 'کاوه',          'student', 'male',   '👨‍🎓', 'left',  1, 5, NOW());

INSERT IGNORE INTO balin_achievements
    (slug, title, description, icon, category, rule_type, rule_config, tier_thresholds, display_order, created_at) VALUES
    ('first-step',      'اولین قدم بالینی', 'اولین سؤال بالینی را پاسخ دادی.',        '🩺', 'progress',  'first_answer',          NULL,                          NULL, 1, NOW()),
    ('clinical-thinker','متفکر بالینی',     'پاسخ صحیح به سؤالات بالینی.',            '🧠', 'progress',  'correct_answers',       NULL, '{"bronze":10,"silver":50,"gold":150,"platinum":400}', 2, NOW()),
    ('stage-runner',    'مسیرپیما',         'تکمیل مراحل جزیره بالین.',               '🗺️', 'progress',  'stages_completed',      NULL, '{"bronze":5,"silver":20,"gold":60,"platinum":150}',   3, NOW()),
    ('case-closer',     'پرونده‌بند',       'تکمیل درس‌های بالینی.',                  '📁', 'progress',  'lessons_completed',     NULL, '{"bronze":1,"silver":3,"gold":8,"platinum":20}',      4, NOW()),
    ('streak-master',   'استمرار',          'روزهای پیاپی فعالیت.',                   '🔥', 'habit',     'streak_days',           NULL, '{"bronze":3,"silver":7,"gold":30,"platinum":100}',    5, NOW()),
    ('history-master',  'استاد شرح‌حال',    'رسیدن به سطح طلایی در مهارت شرح‌حال‌گیری.', '🗣️', 'skill',  'skill_track_mastery',   '{"skill_slug":"history-taking"}', '{"bronze":10,"silver":30,"gold":75,"platinum":150}', 6, NOW()),
    ('first-try',       'قبولی در تلاش اول','عبور از یک آزمون بین‌مرحله‌ای در اولین تلاش.', '🎯', 'exam', 'checkpoint_first_pass', NULL, '{"bronze":1,"silver":3,"gold":10,"platinum":25}',     7, NOW()),
    ('weekly-champion', 'قهرمان هفته',      'رتبه اول رقابت هفتگی.',                  '🏆', 'competition','weekly_champion',      NULL, NULL,                                                  8, NOW());

INSERT IGNORE INTO balin_missions (slug, mission_type, title, description, rules, xp_reward, is_active, display_order, created_at) VALUES
    ('daily-default',  'daily',  'مأموریت روزانه', 'یک مرحله کامل کن و ۳ سؤال پاسخ بده.',   '{"stages":1,"answers":3}',                 30, 1, 1, NOW()),
    ('weekly-default', 'weekly', 'مأموریت هفتگی',  '۵ مرحله، ۱۰ پاسخ صحیح و ۳ روز فعالیت.', '{"stages":5,"correct":10,"streak_days":3}', 100, 1, 2, NOW());
