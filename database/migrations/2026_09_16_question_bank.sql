-- ============================================================================
--  بانک سوال — Question Bank
--
--  Wholly additive: no existing table is altered or dropped, and re-running
--  the file is safe. Every statement is IF NOT EXISTS or INSERT IGNORE.
--
--  SET NAMES matters here: this file carries Persian in the seed rows, and a
--  client defaulting to latin1 would store every one of them double-encoded.
-- ============================================================================
SET NAMES utf8mb4;

-- =====================================================================
--  Taxonomy: درس ← زیردرس ← عنوان
-- =====================================================================
--
--  One self-referencing table, not three.
--
--  The three levels have identical columns and identical operations — create,
--  rename, reorder, activate, delete. Three tables would mean three
--  repositories, three controllers and three forms that must be kept in step,
--  and the first time a fourth level is wanted the whole shape has to be
--  rebuilt. A parent_id with an explicit depth gives the same tree with one
--  of each.
--
--  `depth` is stored rather than derived because every listing query filters
--  on it ("show me the top level"), and walking parents to work out how deep a
--  row is would turn one indexed read into as many reads as the tree is deep.
--  It is written by the application, which refuses to attach a child to a row
--  that is already at the deepest level.
--
CREATE TABLE IF NOT EXISTS qb_subjects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    parent_id   INT UNSIGNED NULL,              -- NULL at depth 1
    depth       TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 درس · 2 زیردرس · 3 عنوان
    title       VARCHAR(191) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_subject_uuid (uuid),

    -- Two siblings may not share a name, but two different parents may each
    -- have a child called "کلیات" — which is why the parent is part of the key.
    -- MySQL treats NULLs as distinct in a unique index, so this does NOT
    -- constrain the top level; the application checks depth-1 titles itself.
    UNIQUE KEY uq_qb_subject_sibling (parent_id, title),

    KEY idx_qb_subject_parent (parent_id, sort_order),
    KEY idx_qb_subject_depth  (depth, sort_order),
    CONSTRAINT fk_qb_subject_parent FOREIGN KEY (parent_id)
        REFERENCES qb_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_subject_author FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  برچسب — free-form labels the admin defines
-- =====================================================================
--
--  Separate from the taxonomy because it answers a different question. The
--  taxonomy says where a question sits in the syllabus; a tag says what kind
--  of question it is (علوم پایه، تالیفی، …) and cuts across every subject.
--  Forcing both into one tree would mean either duplicating every tag under
--  every subject, or pretending a tag is a subject.
--
CREATE TABLE IF NOT EXISTS qb_tags (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid       CHAR(36)     NOT NULL,
    title      VARCHAR(96)  NOT NULL,
    color      VARCHAR(16)  NULL,           -- a chip class name, not raw CSS
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_tag_uuid  (uuid),
    UNIQUE KEY uq_qb_tag_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  سوال
-- =====================================================================
--
--  All three taxonomy columns are nullable and independent, because the brief
--  asks for exactly that: a question may be filed under a درس with no زیردرس,
--  or left unfiled entirely and sorted later. The application enforces the one
--  rule that a stored tree cannot enforce by itself — that a زیردرس named here
--  really is a child of the درس named here — since a foreign key can only say
--  "this row exists", not "this row is below that one".
--
--  stem_text and stem_image are both nullable and at least one must be set.
--  That is a CHECK in MariaDB and in MySQL 8, but older MySQL parses CHECK and
--  silently ignores it, so the rule lives in the application where it is
--  guaranteed to run, and the column comment records the intent.
--
CREATE TABLE IF NOT EXISTS qb_questions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,

    subject_id      INT UNSIGNED NULL,   -- درس      (depth 1)
    sub_subject_id  INT UNSIGNED NULL,   -- زیردرس   (depth 2)
    topic_id        INT UNSIGNED NULL,   -- عنوان    (depth 3)

    stem_text       TEXT         NULL,   -- at least one of stem_text/stem_image
    stem_image      VARCHAR(191) NULL,   -- stored filename, never a path

    difficulty      ENUM('easy','medium','hard','expert') NOT NULL DEFAULT 'medium',

    explanation_text  TEXT         NULL,
    explanation_image VARCHAR(191) NULL,

    status          ENUM('draft','published') NOT NULL DEFAULT 'draft',

    -- Optimistic locking, the same way Balin does it: the edit form carries
    -- the version it rendered, and a save against a stale version is refused
    -- rather than silently overwriting another admin's work.
    version         INT UNSIGNED NOT NULL DEFAULT 1,

    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    deleted_at      DATETIME     NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_question_uuid (uuid),

    -- The listing filters on subject and status together and orders by id, so
    -- the index carries all three and the common query never sorts on disk.
    KEY idx_qb_question_subject (subject_id, status, id),
    KEY idx_qb_question_sub     (sub_subject_id),
    KEY idx_qb_question_topic   (topic_id),
    KEY idx_qb_question_live    (deleted_at, status),

    CONSTRAINT fk_qb_question_subject FOREIGN KEY (subject_id)
        REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_qb_question_sub FOREIGN KEY (sub_subject_id)
        REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_qb_question_topic FOREIGN KEY (topic_id)
        REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_qb_question_author FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  گزینه‌ها
-- =====================================================================
--
--  Options are rows, not a JSON column on the question. A JSON blob cannot be
--  indexed on is_correct, cannot be counted in SQL, and turns "how many
--  questions have no correct answer marked" — the one integrity question worth
--  asking about a question bank — into a full scan in PHP.
--
CREATE TABLE IF NOT EXISTS qb_options (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)     NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    body_text   TEXT         NULL,   -- at least one of body_text/body_image
    body_image  VARCHAR(191) NULL,
    is_correct  TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_option_uuid (uuid),
    KEY idx_qb_option_question (question_id, sort_order),
    CONSTRAINT fk_qb_option_question FOREIGN KEY (question_id)
        REFERENCES qb_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  برچسب‌های هر سوال
-- =====================================================================
CREATE TABLE IF NOT EXISTS qb_question_tags (
    question_id BIGINT UNSIGNED NOT NULL,
    tag_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (question_id, tag_id),
    KEY idx_qb_qtag_tag (tag_id),
    CONSTRAINT fk_qb_qtag_question FOREIGN KEY (question_id)
        REFERENCES qb_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_qtag_tag FOREIGN KEY (tag_id)
        REFERENCES qb_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  دسترسی دانشجو
-- =====================================================================
--
--  Access is granted per درس (a depth-1 row), not per question.
--
--  Per question would be unusable: granting a student a bank of four hundred
--  questions would be four hundred clicks and four hundred rows. Per student
--  with no subject at all would be a single on/off switch, which cannot
--  express "this student gets آناتومی but not فیزیولوژی" — and the brief asks
--  for exactly that distinction on the student's own page.
--
--  A row means access. Revoking deletes the row rather than flipping a flag,
--  so "no row" is the single meaning of "no access" and there is no second
--  state to keep consistent. The grant is dated and attributed, so the student
--  page can say who opened it and when.
--
CREATE TABLE IF NOT EXISTS qb_student_access (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    granted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qb_access (user_id, subject_id),
    KEY idx_qb_access_subject (subject_id),
    CONSTRAINT fk_qb_access_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_access_subject FOREIGN KEY (subject_id)
        REFERENCES qb_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_access_admin FOREIGN KEY (granted_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  پاسخ‌های دانشجو
-- =====================================================================
--
--  Unlike Balin, practice may be repeated: there is no UNIQUE on
--  (user, question). A question bank is for drilling, and a student who got a
--  question wrong last week should be able to try it again. Every attempt is a
--  row, so "how often does this student miss cardiology questions" is a
--  GROUP BY rather than something the table forgot.
--
--  option_id is SET NULL on delete: editing a question replaces its options,
--  and the student's history should survive that as "answered, and was
--  right/wrong" even when the exact option row is gone.
--
CREATE TABLE IF NOT EXISTS qb_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    option_id   BIGINT UNSIGNED NULL,
    subject_id  INT UNSIGNED NULL,       -- denormalised for per-subject stats
    is_correct  TINYINT(1)   NOT NULL,
    answered_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_qb_attempt_user_question (user_id, question_id, id),
    KEY idx_qb_attempt_user_subject  (user_id, subject_id),
    -- "What share of students chose each option" reads each student's first
    -- attempt at one question, which this index answers without a scan.
    -- An installation that ran this file before the index was added can add
    -- it by hand:
    --   ALTER TABLE qb_attempts ADD KEY idx_qb_attempt_question (question_id, user_id, id);
    KEY idx_qb_attempt_question      (question_id, user_id, id),
    CONSTRAINT fk_qb_attempt_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_attempt_question FOREIGN KEY (question_id)
        REFERENCES qb_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_attempt_option FOREIGN KEY (option_id)
        REFERENCES qb_options(id) ON DELETE SET NULL,
    CONSTRAINT fk_qb_attempt_subject FOREIGN KEY (subject_id)
        REFERENCES qb_subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  نشان‌های شخصی دانشجو
-- =====================================================================
--
--  A student's own markers on a question — «نشان‌شده» and «نیاز به مرور».
--  Private to the student and unrelated to the admin's tags, which describe
--  the question rather than one person's relationship with it. The primary
--  key is the whole row, so a marker is either there or not and toggling it
--  twice cannot leave a duplicate.
--
CREATE TABLE IF NOT EXISTS qb_marks (
    user_id     BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    mark        ENUM('saved','review') NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, question_id, mark),
    KEY idx_qb_marks_question (question_id),
    CONSTRAINT fk_qb_marks_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_marks_question FOREIGN KEY (question_id)
        REFERENCES qb_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Permissions
-- =====================================================================
INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('qbank.view',            'مشاهده بانک سوال',           'qbank', NOW()),
    ('qbank.manage_taxonomy', 'مدیریت دروس و برچسب‌ها',      'qbank', NOW()),
    ('qbank.manage_questions','مدیریت سوالات',              'qbank', NOW()),
    ('qbank.publish',         'انتشار سوالات',              'qbank', NOW()),
    ('qbank.manage_students', 'مدیریت دسترسی دانشجویان',     'qbank', NOW());

-- The super admin role holds every permission, including ones added later.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'qbank';

-- =====================================================================
--  Settings
-- =====================================================================
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    -- coming_soon | published | disabled — the same three-state publication
    -- switch Balin uses, checked on the server rather than by hiding a link.
    ('qbank_status',          'coming_soon', 'string', 0, NOW()),
    ('qbank_coming_soon_text','بانک سوال به‌زودی در دسترس قرار می‌گیرد.', 'string', 0, NOW()),
    ('qbank_image_max_kb',    '3072',        'int',    0, NOW());

-- =====================================================================
--  Seed tags — the two the brief names, so the field is not empty on
--  first use. Both are ordinary rows the admin can rename or delete.
-- =====================================================================
INSERT IGNORE INTO qb_tags (uuid, title, color, sort_order, is_active, created_at) VALUES
    (UUID(), 'علوم پایه', 'chip-blue',  1, 1, NOW()),
    (UUID(), 'تالیفی',    'chip-green', 2, 1, NOW());
