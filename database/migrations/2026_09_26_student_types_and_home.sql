-- ============================================================================
--  Student types and the new home screen
--
--  student_types          what kind of student someone is (ترمی، علوم پایه،
--                         دستیاری، لیسانس به پزشکی، تخصص …). The admin creates
--                         them and picks, for each, which sections of the site
--                         are on. `modules` is a JSON list of section keys
--                         (see app/Services/Modules.php).
--  student_type_requests  after signing up a student asks to be one type; the
--                         admin approves or rejects. Until then the student
--                         sees `modules_default`.
--  users.student_type_id  the approved type.
--
--  Settings:
--    dashboard_countdowns  the countdowns on the dashboard, set by the admin
--    modules_off           sections switched off for everyone
--    modules_default       sections for a student with no approved type
--                          (empty = everything)
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS student_types (
    id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug              VARCHAR(64)  NOT NULL,
    title             VARCHAR(128) NOT NULL,
    description       VARCHAR(500) NULL,
    color             VARCHAR(16)  NOT NULL DEFAULT 'blue',
    icon              VARCHAR(32)  NOT NULL DEFAULT 'school',
    modules           JSON         NOT NULL,
    requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order        SMALLINT     NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_types_slug (slug),
    KEY idx_student_types_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS student_type_id SMALLINT UNSIGNED NULL AFTER group_id;
ALTER TABLE users ADD KEY IF NOT EXISTS idx_users_student_type (student_type_id);

CREATE TABLE IF NOT EXISTS student_type_requests (
    id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED   NOT NULL,
    type_id     SMALLINT UNSIGNED NOT NULL,
    note        VARCHAR(500)      NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note  VARCHAR(500)      NULL,
    handled_by  BIGINT UNSIGNED   NULL,
    handled_at  DATETIME          NULL,
    created_at  DATETIME          NOT NULL,
    PRIMARY KEY (id),
    KEY idx_stype_req_status (status, created_at),
    KEY idx_stype_req_user (user_id, created_at),
    CONSTRAINT fk_stype_req_user  FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_stype_req_type  FOREIGN KEY (type_id)    REFERENCES student_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_stype_req_admin FOREIGN KEY (handled_by) REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A starting set the admin can rename, re-colour or delete.
INSERT IGNORE INTO student_types (slug, title, description, color, icon, modules, requires_approval, sort_order, created_at) VALUES
    ('termi',     'دانشجوی ترمی',      'دانشجوی در حال تحصیل با کلاس و امتحان ترم',        'blue',   'school',
        JSON_ARRAY('lessons','courses','mindmaps','library','notes','qbank','my_exams','flashcards','figures','balin','planner','analytics','shop'), 1, 1, NOW()),
    ('basic',     'علوم پایه',         'آمادگی آزمون علوم پایه',                          'violet', 'lesson',
        JSON_ARRAY('lessons','courses','mindmaps','library','notes','qbank','my_exams','flashcards','figures','analytics','shop'), 1, 2, NOW()),
    ('residency', 'دستیاری',           'آمادگی آزمون دستیاری',                            'teal',   'stethoscope',
        JSON_ARRAY('lessons','courses','mindmaps','library','notes','qbank','my_exams','flashcards','balin','analytics','shop'), 1, 3, NOW()),
    ('bs2md',     'لیسانس به پزشکی',   'آزمون لیسانس به پزشکی',                           'amber',  'target',
        JSON_ARRAY('lessons','courses','mindmaps','library','notes','qbank','my_exams','flashcards','figures','analytics','shop'), 1, 4, NOW()),
    ('specialty', 'تخصص',              'دستیار و متخصص',                                  'rose',   'trophy',
        JSON_ARRAY('lessons','courses','library','notes','qbank','my_exams','flashcards','balin','analytics','shop'), 1, 5, NOW());

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('dashboard_countdowns', '[]', 'json', 0, NOW()),
    ('modules_off',          '[]', 'json', 0, NOW()),
    ('modules_default',      '[]', 'json', 0, NOW()),
    -- Ask a new student which type they are on their first visit.
    ('student_type_prompt',  '1',  'bool', 0, NOW());
