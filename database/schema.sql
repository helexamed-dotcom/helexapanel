-- =====================================================================
--  HeleXa Med — Database Schema (v1.0)
--  Engine : InnoDB | Charset : utf8mb4 | Collation : utf8mb4_unicode_ci
--  Target : MySQL 8.0+ / MariaDB 10.5+
--
--  NOTES
--  1) All unique string columns are VARCHAR(191) so the schema also works
--     on older InnoDB row formats (767-byte index limit with utf8mb4).
--  2) All dates are stored as GREGORIAN. Jalali (Persian) conversion is a
--     PRESENTATION concern and is done in PHP. Never store Jalali strings
--     in DATE columns — sorting, BETWEEN and indexes would break.
--  3) Timestamps are stored in the app timezone (Asia/Tehran) consistently
--     via PHP's date_default_timezone_set(); the DB never uses NOW() for
--     business logic, PHP supplies the value so behaviour is deterministic.
--  4) No secrets are stored in plaintext: passwords -> Argon2id hash,
--     session/viewer tokens -> SHA-256 of the raw token only.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- =====================================================================
--  SECTION 1 — IDENTITY, RBAC
-- =====================================================================

CREATE TABLE roles (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(64)  NOT NULL,          -- super_admin | admin | student
    name            VARCHAR(128) NOT NULL,
    is_system       TINYINT(1)   NOT NULL DEFAULT 0,-- system roles cannot be deleted
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(96)  NOT NULL,          -- manage_students, view_logs, ...
    name            VARCHAR(128) NOT NULL,
    module          VARCHAR(64)  NOT NULL,          -- grouping for the UI
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         TINYINT UNSIGNED  NOT NULL,
    permission_id   SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic hierarchy: University -> Major -> Term -> Group
CREATE TABLE universities (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(191) NOT NULL,
    city        VARCHAR(96)  NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_universities_title (title),
    KEY idx_universities_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE majors (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    university_id  SMALLINT UNSIGNED NOT NULL,
    title          VARCHAR(191) NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order     SMALLINT     NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_majors_university_title (university_id, title),
    KEY idx_majors_active (university_id, is_active, sort_order),
    CONSTRAINT fk_majors_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A subject is a class period's name for scheduling and exams: a lighter,
-- separate concept from `courses` (which is content-bearing LMS material).
-- NULL major_id means a general subject offered to every major.
CREATE TABLE subjects (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    major_id   SMALLINT UNSIGNED NULL,
    title      VARCHAR(191) NOT NULL,
    color      VARCHAR(16)  NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_major_title (major_id, title),
    KEY idx_subjects_major (major_id, sort_order),
    CONSTRAINT fk_subjects_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A term belongs to a major; NULL means a general term shared across majors.
CREATE TABLE terms (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    major_id        SMALLINT UNSIGNED NULL,
    title           VARCHAR(128) NOT NULL,          -- "ترم ۵"
    number          TINYINT UNSIGNED NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terms_major_title (major_id, title),
    KEY idx_terms_major (major_id, sort_order),
    CONSTRAINT fk_terms_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_groups (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    term_id         SMALLINT UNSIGNED NOT NULL,
    title           VARCHAR(128) NOT NULL,          -- "گروه ۲۳"
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_groups_term_title (term_id, title),
    CONSTRAINT fk_groups_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)     NOT NULL,      -- used in URLs, never the numeric id
    role_id             TINYINT UNSIGNED NOT NULL,
    username            VARCHAR(191) NOT NULL,
    mobile              VARCHAR(20)  NULL,          -- alternative login identifier
    email               VARCHAR(191) NULL,
    password_hash       VARCHAR(255) NOT NULL,      -- PASSWORD_ARGON2ID
    password_changed_at DATETIME     NULL,
    must_change_password TINYINT(1)  NOT NULL DEFAULT 0,
    full_name           VARCHAR(191) NOT NULL,
    gender              ENUM('male','female') NULL,
    avatar_path         VARCHAR(255) NULL,          -- relative to storage/private/uploads
    major               VARCHAR(128) NULL,          -- legacy free text; major_id wins when set
    university_id       SMALLINT UNSIGNED NULL,
    major_id            SMALLINT UNSIGNED NULL,
    term_id             SMALLINT UNSIGNED NULL,     -- primary term; user_semesters holds the full set
    group_id            SMALLINT UNSIGNED NULL,
    status              ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    -- brute-force state (per-account; per-IP lives in login_attempts)
    failed_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME     NULL,
    -- optional 2FA (architecture ready, feature-flagged in settings)
    totp_secret         VARBINARY(255) NULL,        -- encrypted at rest
    totp_enabled        TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at       DATETIME     NULL,
    last_login_ip       VARCHAR(45)  NULL,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME     NULL,
    deleted_at          DATETIME     NULL,          -- soft delete keeps FK history intact
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_mobile (mobile),
    KEY idx_users_role_status (role_id, status),
    KEY idx_users_term_group (term_id, group_id),
    KEY idx_users_university (university_id, major_id),
    CONSTRAINT fk_users_role  FOREIGN KEY (role_id)  REFERENCES roles(id)           ON DELETE RESTRICT,
    CONSTRAINT fk_users_term  FOREIGN KEY (term_id)  REFERENCES terms(id)           ON DELETE SET NULL,
    CONSTRAINT fk_users_group FOREIGN KEY (group_id) REFERENCES student_groups(id)  ON DELETE SET NULL,
    CONSTRAINT fk_users_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_major      FOREIGN KEY (major_id)      REFERENCES majors(id)       ON DELETE SET NULL,
    CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student can carry units from more than one term at the same time.
CREATE TABLE user_semesters (
    user_id    BIGINT UNSIGNED   NOT NULL,
    term_id    SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME          NOT NULL,
    PRIMARY KEY (user_id, term_id),
    KEY idx_user_semesters_term (term_id),
    CONSTRAINT fk_user_semesters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_semesters_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-admin permission overrides on top of the role.
CREATE TABLE user_permissions (
    user_id         BIGINT UNSIGNED   NOT NULL,
    permission_id   SMALLINT UNSIGNED NOT NULL,
    effect          ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    granted_by      BIGINT UNSIGNED   NULL,
    created_at      DATETIME          NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    KEY idx_up_permission (permission_id),
    CONSTRAINT fk_up_user       FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_up_granter    FOREIGN KEY (granted_by)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 2 — SESSIONS, AUTH TELEMETRY
-- =====================================================================

CREATE TABLE sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    php_session_id      VARCHAR(128) NOT NULL,      -- PHP session identifier
    token_hash          CHAR(64)     NOT NULL,      -- SHA-256 of the rotating session token
    device_hash         CHAR(64)     NOT NULL,      -- stable fingerprint (UA + platform + accept-lang)
    ip_address          VARCHAR(45)  NOT NULL,      -- IPv4/IPv6 text form
    user_agent          VARCHAR(512) NOT NULL,
    browser             VARCHAR(64)  NULL,
    operating_system    VARCHAR(64)  NULL,
    device_type         ENUM('desktop','tablet','mobile','bot','unknown') NOT NULL DEFAULT 'unknown',
    login_at            DATETIME     NOT NULL,
    last_activity       DATETIME     NOT NULL,
    logout_at           DATETIME     NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    terminated_by       BIGINT UNSIGNED NULL,       -- admin who force-logged-out
    termination_reason  ENUM('user_logout','idle_timeout','absolute_timeout','admin_force','new_device','password_change','security') NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_php_sid (php_session_id),
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY idx_sessions_user_active (user_id, is_active, last_activity),
    KEY idx_sessions_activity (last_activity),
    CONSTRAINT fk_sessions_user       FOREIGN KEY (user_id)       REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessions_terminator FOREIGN KEY (terminated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier          VARCHAR(191) NOT NULL,      -- what was typed (username/mobile)
    user_id             BIGINT UNSIGNED NULL,       -- resolved only on success
    ip_address          VARCHAR(45)  NOT NULL,
    user_agent          VARCHAR(512) NULL,
    successful          TINYINT(1)   NOT NULL DEFAULT 0,
    failure_reason      ENUM('bad_credentials','locked','inactive','suspended','rate_limited','single_device','csrf','2fa_failed') NULL,
    attempted_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_ip_time (ip_address, attempted_at),
    KEY idx_attempts_ident_time (identifier, attempted_at),
    KEY idx_attempts_time (attempted_at),
    CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,           -- NULL for anonymous/system events
    action          VARCHAR(96)  NOT NULL,          -- student.login, course.created, session.terminated
    target_type     VARCHAR(64)  NULL,              -- user | course | content | session
    target_id       BIGINT UNSIGNED NULL,
    ip_address      VARCHAR(45)  NULL,
    user_agent      VARCHAR(512) NULL,
    metadata        JSON         NULL,
    severity        ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_logs_user_time (user_id, created_at),
    KEY idx_logs_action_time (action, created_at),
    KEY idx_logs_target (target_type, target_id),
    KEY idx_logs_time (created_at),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 3 — COURSES, TREE STRUCTURE, CONTENT
-- =====================================================================

CREATE TABLE courses (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    title           VARCHAR(191) NOT NULL,
    slug            VARCHAR(191) NOT NULL,
    description     TEXT         NULL,
    thumbnail_path  VARCHAR(255) NULL,
    color           VARCHAR(16)  NULL,              -- UI accent per course
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    deleted_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_uuid (uuid),
    UNIQUE KEY uq_courses_slug (slug),
    KEY idx_courses_status_order (status, sort_order),
    CONSTRAINT fk_courses_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adjacency-list tree + materialized path for cheap subtree queries.
CREATE TABLE course_sections (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id       INT UNSIGNED NOT NULL,
    parent_id       INT UNSIGNED NULL,
    title           VARCHAR(191) NOT NULL,
    description     TEXT         NULL,
    icon            VARCHAR(64)  NULL,
    section_type    ENUM('folder','full_notes','summary_notes','question_bank','chat_learn','mind_map','custom')
                    NOT NULL DEFAULT 'folder',
    status          ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft',
    depth           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    path            VARCHAR(255) NOT NULL DEFAULT '/', -- e.g. /12/34/
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    deleted_at      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_sections_course_parent (course_id, parent_id, sort_order),
    KEY idx_sections_path (course_id, path),
    CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id)         ON DELETE CASCADE,
    CONSTRAINT fk_sections_parent FOREIGN KEY (parent_id) REFERENCES course_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_contents (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,          -- the only identifier exposed to clients
    course_id       INT UNSIGNED NOT NULL,          -- denormalized: single-join authorization
    section_id      INT UNSIGNED NULL,
    title           VARCHAR(191) NOT NULL,
    description     TEXT         NULL,
    content_type    ENUM('full_notes','summary_notes','question_bank','chat_learn','mind_map','custom')
                    NOT NULL DEFAULT 'full_notes',
    -- storage_kind = 'file'   -> storage_path points inside storage/private/lessons/
    -- storage_kind = 'inline' -> body_html holds the markup (small snippets only)
    storage_kind    ENUM('file','inline') NOT NULL DEFAULT 'file',
    storage_path    VARCHAR(255) NULL,              -- RELATIVE path; validated with realpath() allowlist
    original_filename VARCHAR(191) NULL,
    body_html       LONGTEXT     NULL,
    extra_css       LONGTEXT     NULL,
    extra_js        LONGTEXT     NULL,
    checksum        CHAR(64)     NULL,              -- SHA-256 of the stored file, tamper detection
    scan_report     JSON         NULL,              -- upload scanner findings (external hosts, storage APIs)
    byte_size       INT UNSIGNED NULL,
    estimated_minutes SMALLINT UNSIGNED NULL,
    is_downloadable TINYINT(1)   NOT NULL DEFAULT 0,
    is_printable    TINYINT(1)   NOT NULL DEFAULT 0,
    offline_enabled TINYINT(1)   NOT NULL DEFAULT 1,   -- may this lesson be stored for offline study?
    guest_visible   TINYINT(1)   NOT NULL DEFAULT 0,   -- readable without an account?
    status          ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    deleted_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contents_uuid (uuid),
    KEY idx_contents_course_status (course_id, status),
    KEY idx_contents_section_order (section_id, sort_order),
    KEY idx_contents_guest (guest_visible, status),
    CONSTRAINT fk_contents_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE CASCADE,
    CONSTRAINT fk_contents_section FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE SET NULL,
    CONSTRAINT fk_contents_creator FOREIGN KEY (created_by) REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private assets referenced by a lesson (images, fonts, css, js, svg).
CREATE TABLE content_assets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_id      INT UNSIGNED NOT NULL,
    asset_key       CHAR(32)     NOT NULL,          -- random handle used in rewritten URLs
    original_name   VARCHAR(191) NOT NULL,
    relative_path   VARCHAR(255) NOT NULL,          -- inside storage/private/lessons/<uuid>/
    mime_type       VARCHAR(96)  NOT NULL,
    byte_size       INT UNSIGNED NOT NULL,
    sha256          CHAR(64)     NOT NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_key (asset_key),
    UNIQUE KEY uq_assets_content_path (content_id, relative_path),
    CONSTRAINT fk_assets_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A package is a bundle of courses an admin can activate in one action.
CREATE TABLE packages (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                   CHAR(36)     NOT NULL,
    title                  VARCHAR(191) NOT NULL,
    description            TEXT         NULL,
    color                  VARCHAR(16)  NULL,
    status                 ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    auto_grant_new_courses TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order             SMALLINT     NOT NULL DEFAULT 0,
    created_by             BIGINT UNSIGNED NULL,
    created_at             DATETIME     NOT NULL,
    updated_at             DATETIME     NULL,
    deleted_at             DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_packages_uuid (uuid),
    KEY idx_packages_status (status, sort_order),
    CONSTRAINT fk_packages_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_courses (
    package_id INT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    added_at   DATETIME     NOT NULL,
    PRIMARY KEY (package_id, course_id),
    KEY idx_package_courses_course (course_id),
    CONSTRAINT fk_pkg_courses_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    CONSTRAINT fk_pkg_courses_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_activations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36)     NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    package_id   INT UNSIGNED NOT NULL,
    status       ENUM('active','suspended','expired','cancelled') NOT NULL DEFAULT 'active',
    starts_at    DATETIME     NULL,
    ends_at      DATETIME     NULL,
    course_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activated_by BIGINT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_uuid (uuid),
    UNIQUE KEY uq_activation_user_package (user_id, package_id),
    KEY idx_activation_package (package_id, status),
    CONSTRAINT fk_activation_user      FOREIGN KEY (user_id)      REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_activation_package   FOREIGN KEY (package_id)   REFERENCES packages(id) ON DELETE CASCADE,
    CONSTRAINT fk_activation_activator FOREIGN KEY (activated_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clickable items/buttons the admin composes (e.g. "جزوه کامل درس ۱").
CREATE TABLE content_buttons (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id          INT UNSIGNED NULL,          -- rendered inside a section page
    content_id          INT UNSIGNED NULL,          -- or inside a content page
    label               VARCHAR(191) NOT NULL,
    icon                VARCHAR(64)  NULL,
    target_content_id   INT UNSIGNED NULL,          -- internal target (preferred)
    target_url          VARCHAR(255) NULL,          -- external target (validated allowlist)
    style               VARCHAR(32)  NOT NULL DEFAULT 'primary',
    status              ENUM('active','hidden') NOT NULL DEFAULT 'active',
    sort_order          SMALLINT     NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_buttons_section (section_id, sort_order),
    KEY idx_buttons_content (content_id, sort_order),
    CONSTRAINT fk_buttons_section FOREIGN KEY (section_id)        REFERENCES course_sections(id)  ON DELETE CASCADE,
    CONSTRAINT fk_buttons_content FOREIGN KEY (content_id)        REFERENCES course_contents(id)  ON DELETE CASCADE,
    CONSTRAINT fk_buttons_target  FOREIGN KEY (target_content_id) REFERENCES course_contents(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 4 — ENROLLMENT & PROGRESS
-- =====================================================================

CREATE TABLE student_courses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED    NOT NULL,
    status          ENUM('active','suspended','expired','cancelled') NOT NULL DEFAULT 'active',
    starts_at       DATETIME     NULL,              -- NULL = immediately
    ends_at         DATETIME     NULL,              -- NULL = no expiry
    assigned_by     BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sc_user_course (user_id, course_id),
    KEY idx_sc_course_status (course_id, status),
    KEY idx_sc_window (status, starts_at, ends_at),
    CONSTRAINT fk_sc_user     FOREIGN KEY (user_id)     REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_sc_course   FOREIGN KEY (course_id)   REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_content_status (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    content_id          INT UNSIGNED    NOT NULL,
    course_id           INT UNSIGNED    NOT NULL,   -- denormalized for per-course progress stats
    status              ENUM('unread','studying','completed','review_later') NOT NULL DEFAULT 'unread',
    total_seconds       INT UNSIGNED    NOT NULL DEFAULT 0,  -- authoritative, server-computed
    client_state        JSON            NULL,                -- server-side stand-in for localStorage
    open_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    first_opened_at     DATETIME     NULL,
    last_opened_at      DATETIME     NULL,
    completed_at        DATETIME     NULL,
    updated_at          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scs_user_content (user_id, content_id),
    KEY idx_scs_user_status (user_id, status, last_opened_at),
    KEY idx_scs_user_course (user_id, course_id, status),
    CONSTRAINT fk_scs_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_scs_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE,
    CONSTRAINT fk_scs_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 5 — SECURE VIEWER & STUDY TIME (SERVER-AUTHORITATIVE)
-- =====================================================================

-- Short-lived, single-purpose tokens that gate the private content stream.
CREATE TABLE viewer_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash      CHAR(64)     NOT NULL,          -- SHA-256(raw token); raw is never stored
    user_id         BIGINT UNSIGNED NOT NULL,
    session_id      BIGINT UNSIGNED NOT NULL,       -- binds the token to ONE login session
    content_id      INT UNSIGNED    NOT NULL,
    purpose         ENUM('document','asset','heartbeat') NOT NULL DEFAULT 'document',
    ua_hash         CHAR(64)     NOT NULL,
    ip_prefix_hash  CHAR(64)     NULL,              -- hashed /24 (v4) or /48 (v6), not the full IP
    issued_at       DATETIME     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    max_uses        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    revoked_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vt_hash (token_hash),
    KEY idx_vt_expiry (expires_at),
    KEY idx_vt_session (session_id, content_id),
    KEY idx_vt_user_content (user_id, content_id, expires_at),
    CONSTRAINT fk_vt_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_vt_session FOREIGN KEY (session_id) REFERENCES sessions(id)        ON DELETE CASCADE,
    CONSTRAINT fk_vt_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE study_sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    content_id          INT UNSIGNED    NOT NULL,
    course_id           INT UNSIGNED    NOT NULL,
    session_id          BIGINT UNSIGNED NULL,       -- login session it belongs to
    started_at          DATETIME     NOT NULL,
    last_heartbeat_at   DATETIME     NOT NULL,
    ended_at            DATETIME     NULL,
    duration_seconds    INT UNSIGNED NOT NULL DEFAULT 0,  -- sum of accepted heartbeat deltas
    end_reason          ENUM('closed','idle','timeout','logout','sweep','offline') NULL,
    ip_address          VARCHAR(45)  NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_ss_user_started (user_id, started_at),
    KEY idx_ss_user_content (user_id, content_id, started_at),
    KEY idx_ss_active_sweep (is_active, last_heartbeat_at),
    CONSTRAINT fk_ss_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_ss_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE,
    CONSTRAINT fk_ss_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE CASCADE,
    CONSTRAINT fk_ss_session FOREIGN KEY (session_id) REFERENCES sessions(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE study_heartbeats (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    study_session_id    BIGINT UNSIGNED NOT NULL,
    beat_at             DATETIME     NOT NULL,
    accepted_seconds    SMALLINT UNSIGNED NOT NULL, -- server-clamped delta, never client-supplied
    is_visible          TINYINT(1)   NOT NULL DEFAULT 1,  -- document.visibilityState
    is_focused          TINYINT(1)   NOT NULL DEFAULT 1,
    scroll_percent      TINYINT UNSIGNED NULL,
    rejected            TINYINT(1)   NOT NULL DEFAULT 0,  -- anomaly (too fast / replay / stale)
    PRIMARY KEY (id),
    KEY idx_hb_session_time (study_session_id, beat_at),
    CONSTRAINT fk_hb_session FOREIGN KEY (study_session_id) REFERENCES study_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Highlights a student draws on a lesson.
CREATE TABLE content_highlights (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    content_id      INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    kind            ENUM('text','area') NOT NULL DEFAULT 'text',
    color           VARCHAR(16)  NOT NULL DEFAULT 'yellow',
    anchor          JSON         NOT NULL,   -- node path + offsets, or a percentage rect
    quote           VARCHAR(500) NULL,       -- lets a lost anchor be re-found by search
    note            VARCHAR(500) NULL,
    content_version CHAR(64)     NULL,       -- lesson checksum when it was made
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_highlight_uuid (uuid),
    KEY idx_highlight_owner (user_id, content_id, created_at),
    KEY idx_highlight_content (content_id),
    CONSTRAINT fk_highlight_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_highlight_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE CASCADE,
    CONSTRAINT fk_highlight_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-aggregated daily totals so the weekly chart never scans raw heartbeats.
CREATE TABLE study_daily_stats (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    stat_date           DATE         NOT NULL,      -- Gregorian; mapped to Jalali in PHP
    course_id           INT UNSIGNED NULL,
    total_seconds       INT UNSIGNED NOT NULL DEFAULT 0,
    contents_opened     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sds_user_date_course (user_id, stat_date, course_id),
    KEY idx_sds_user_date (user_id, stat_date),
    CONSTRAINT fk_sds_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_sds_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 6 — SCHEDULE, EXAMS, CALENDAR
-- =====================================================================

CREATE TABLE schedules (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title           VARCHAR(191) NOT NULL,
    term_id         SMALLINT UNSIGNED NOT NULL,
    group_id        SMALLINT UNSIGNED NULL,         -- NULL = applies to the whole term
    academic_year   VARCHAR(16)  NULL,              -- "1404-1405"
    effective_from  DATE         NULL,
    effective_to    DATE         NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_schedules_scope (term_id, group_id, is_active),
    CONSTRAINT fk_schedules_term    FOREIGN KEY (term_id)    REFERENCES terms(id)          ON DELETE CASCADE,
    CONSTRAINT fk_schedules_group   FOREIGN KEY (group_id)   REFERENCES student_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedules_creator FOREIGN KEY (created_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id     INT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,      -- 0=شنبه ... 6=جمعه
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    title           VARCHAR(191) NOT NULL,          -- always the display text, free text
    subject_id      SMALLINT UNSIGNED NULL,         -- optional link to the subjects list
    course_id       INT UNSIGNED NULL,              -- optional link to LMS content, unrelated to subject_id
    teacher         VARCHAR(191) NULL,
    location        VARCHAR(191) NULL,
    color           VARCHAR(16)  NULL,
    notes           VARCHAR(255) NULL,
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_items_schedule_day (schedule_id, weekday, start_time),
    KEY idx_items_subject (subject_id),
    CONSTRAINT fk_items_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_subject  FOREIGN KEY (subject_id)  REFERENCES subjects(id)  ON DELETE SET NULL,
    CONSTRAINT fk_items_course   FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title           VARCHAR(191) NOT NULL,
    exam_kind       ENUM('final','midterm','quiz','practical','other') NOT NULL DEFAULT 'final',
    subject_id      SMALLINT UNSIGNED NULL,
    course_id       INT UNSIGNED NULL,
    term_id         SMALLINT UNSIGNED NULL,
    group_id        SMALLINT UNSIGNED NULL,
    exam_date       DATE         NOT NULL,          -- Gregorian
    start_time      TIME         NULL,
    end_time        TIME         NULL,
    location        VARCHAR(191) NULL,
    description     TEXT         NULL,
    is_published    TINYINT(1)   NOT NULL DEFAULT 1,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_exams_scope_date (term_id, group_id, exam_date),
    KEY idx_exams_subject (subject_id),
    KEY idx_exams_kind_date (exam_kind, exam_date),
    CONSTRAINT fk_exams_subject FOREIGN KEY (subject_id) REFERENCES subjects(id)        ON DELETE SET NULL,
    CONSTRAINT fk_exams_course  FOREIGN KEY (course_id)  REFERENCES courses(id)         ON DELETE SET NULL,
    CONSTRAINT fk_exams_term    FOREIGN KEY (term_id)    REFERENCES terms(id)           ON DELETE CASCADE,
    CONSTRAINT fk_exams_group   FOREIGN KEY (group_id)   REFERENCES student_groups(id)  ON DELETE CASCADE,
    CONSTRAINT fk_exams_creator FOREIGN KEY (created_by) REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_events (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title           VARCHAR(191) NOT NULL,
    description     TEXT         NULL,
    event_type      ENUM('holiday','event','reminder','deadline','custom') NOT NULL DEFAULT 'event',
    event_date      DATE         NOT NULL,
    start_time      TIME         NULL,
    end_time        TIME         NULL,
    term_id         SMALLINT UNSIGNED NULL,
    group_id        SMALLINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL,           -- personal reminder
    color           VARCHAR(16)  NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_events_date (event_date),
    KEY idx_events_scope (term_id, group_id, event_date),
    KEY idx_events_user (user_id, event_date),
    CONSTRAINT fk_events_term    FOREIGN KEY (term_id)    REFERENCES terms(id)          ON DELETE CASCADE,
    CONSTRAINT fk_events_group   FOREIGN KEY (group_id)   REFERENCES student_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_user    FOREIGN KEY (user_id)    REFERENCES users(id)          ON DELETE CASCADE,
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 7 — NOTIFICATIONS & MESSAGES
-- =====================================================================

CREATE TABLE notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title           VARCHAR(191) NOT NULL,
    body            TEXT         NULL,
    notif_type      ENUM('content','schedule','exam','course','package','message','support','system') NOT NULL DEFAULT 'system',
    related_type    VARCHAR(32)  NULL,          -- course | package | ...
    related_id      BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(96)  NULL,          -- one activation, one announcement
    audience        ENUM('all','term','group','university','major','course','package','user')
                    NOT NULL DEFAULT 'all',
    term_id         SMALLINT UNSIGNED NULL,
    group_id        SMALLINT UNSIGNED NULL,
    university_id   SMALLINT UNSIGNED NULL,
    major_id        SMALLINT UNSIGNED NULL,
    course_id       INT UNSIGNED NULL,
    package_id      INT UNSIGNED NULL,
    link_url        VARCHAR(255) NULL,              -- internal route only
    created_by      BIGINT UNSIGNED NULL,
    published_at    DATETIME     NOT NULL,
    expires_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_idempotency (idempotency_key),
    KEY idx_notifications_related (related_type, related_id),
    KEY idx_notif_published (published_at),
    KEY idx_notif_university (university_id),
    KEY idx_notif_major (major_id),
    KEY idx_notif_course (course_id),
    KEY idx_notif_package (package_id),
    CONSTRAINT fk_notif_term        FOREIGN KEY (term_id)        REFERENCES terms(id)          ON DELETE CASCADE,
    CONSTRAINT fk_notif_group       FOREIGN KEY (group_id)       REFERENCES student_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_university2 FOREIGN KEY (university_id)  REFERENCES universities(id)   ON DELETE CASCADE,
    CONSTRAINT fk_notif_major2      FOREIGN KEY (major_id)       REFERENCES majors(id)         ON DELETE CASCADE,
    CONSTRAINT fk_notif_course2     FOREIGN KEY (course_id)      REFERENCES courses(id)        ON DELETE CASCADE,
    CONSTRAINT fk_notif_package2    FOREIGN KEY (package_id)     REFERENCES packages(id)       ON DELETE CASCADE,
    CONSTRAINT fk_notif_creator     FOREIGN KEY (created_by)     REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    read_at         DATETIME     NULL,
    deleted_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_un_notif_user (notification_id, user_id),
    KEY idx_un_user_unread (user_id, is_read, created_at),
    CONSTRAINT fk_un_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_un_user  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id       BIGINT UNSIGNED NOT NULL,
    subject         VARCHAR(191) NOT NULL,
    body            TEXT         NOT NULL,          -- plain text / escaped on output
    is_broadcast    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_messages_sender (sender_id, created_at),
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_recipients (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id      BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    read_at         DATETIME     NULL,
    deleted_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mr_message_user (message_id, user_id),
    KEY idx_mr_user_unread (user_id, is_read),
    CONSTRAINT fk_mr_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_mr_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SECTION 8 — SETTINGS
-- =====================================================================

CREATE TABLE settings (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key     VARCHAR(96)  NOT NULL,
    setting_value   TEXT         NULL,
    value_type      ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    is_public       TINYINT(1)   NOT NULL DEFAULT 0, -- exposed to the front-end?
    updated_by      BIGINT UNSIGNED NULL,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency ledger for offline sync. The unique key is the defence against
-- replayed batches: a duplicate event is refused by the database itself.
CREATE TABLE offline_sync_events (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    event_id          CHAR(36)     NOT NULL,
    event_type        ENUM('study','status','position','highlight') NOT NULL,
    content_id        INT UNSIGNED NULL,
    accepted_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
    client_started_at DATETIME     NULL,
    client_ended_at   DATETIME     NULL,
    outcome           ENUM('accepted','duplicate','rejected') NOT NULL,
    reason            VARCHAR(64)  NULL,
    device_hash       CHAR(64)     NULL,
    received_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_user_event (user_id, event_id),
    KEY idx_sync_user_time (user_id, received_at),
    KEY idx_sync_content (content_id),
    CONSTRAINT fk_sync_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    CONSTRAINT fk_sync_content FOREIGN KEY (content_id) REFERENCES course_contents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-lived "remember me" handles. Split-token design: the selector finds
-- the row, the validator proves ownership, and only the validator's hash is
-- stored. Each use rotates the validator, which is also how a stolen cookie
-- is detected.
CREATE TABLE remember_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    selector       CHAR(32)     NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    device_hash    CHAR(64)     NOT NULL,
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(512) NULL,
    created_at     DATETIME     NOT NULL,
    last_used_at   DATETIME     NULL,
    expires_at     DATETIME     NOT NULL,
    revoked_at     DATETIME     NULL,
    revoke_reason  ENUM('logout','password_change','admin','theft_suspected','expired','rotated') NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id, revoked_at),
    KEY idx_remember_expiry (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student's support conversation. One open/answered ticket at a time per
-- student; a message after closure starts a new one.
CREATE TABLE support_tickets (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)     NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    status           ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    last_message_at  DATETIME     NOT NULL,
    created_at       DATETIME     NOT NULL,
    closed_at        DATETIME     NULL,
    closed_by        BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_uuid (uuid),
    KEY idx_ticket_user (user_id, status),
    KEY idx_ticket_status (status, last_message_at),
    CONSTRAINT fk_ticket_user      FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per message in a ticket, either side.
CREATE TABLE support_messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)     NOT NULL,
    ticket_id        BIGINT UNSIGNED NOT NULL,
    sender_type      ENUM('student','admin') NOT NULL,
    sender_id        BIGINT UNSIGNED NOT NULL,
    body             TEXT         NULL,
    attachment_path  VARCHAR(255) NULL,
    created_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_message_uuid (uuid),
    KEY idx_support_message_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_message_sender FOREIGN KEY (sender_id) REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram account link. telegram_user_id is never trusted for authorization
-- on its own; every other query joins through user_id, which is only set
-- once a real password has been verified through the bot's login flow.
CREATE TABLE telegram_accounts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NOT NULL,
    telegram_user_id BIGINT          NOT NULL,
    chat_id          BIGINT          NOT NULL,
    telegram_username VARCHAR(64)    NULL,
    first_name       VARCHAR(191)    NULL,
    phone_shared      VARCHAR(20)    NULL,
    notify_general    TINYINT(1)     NOT NULL DEFAULT 1,
    notify_schedule   TINYINT(1)     NOT NULL DEFAULT 1,
    notify_exams      TINYINT(1)     NOT NULL DEFAULT 1,
    notify_announcements TINYINT(1)  NOT NULL DEFAULT 1,
    notify_support    TINYINT(1)     NOT NULL DEFAULT 1,
    linked_at        DATETIME        NOT NULL,
    last_seen_at     DATETIME        NULL,
    unlinked_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_telegram_user (telegram_user_id),
    KEY idx_telegram_account_user (user_id, unlinked_at),
    CONSTRAINT fk_telegram_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The bot's stand-in for an HTTP session: each webhook call is stateless, so
-- this row is "what step is this chat in and what has it collected so far".
CREATE TABLE telegram_states (
    telegram_user_id BIGINT       NOT NULL,
    step             VARCHAR(48)  NOT NULL,
    payload          JSON         NULL,
    updated_at       DATETIME     NOT NULL,
    expires_at       DATETIME     NOT NULL,
    PRIMARY KEY (telegram_user_id),
    KEY idx_telegram_state_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound deliveries for any notification channel; channel-agnostic so a
-- future email/push channel can reuse the same queue and worker shape.
CREATE TABLE notification_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    channel         VARCHAR(32)  NOT NULL,
    status          ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(255) NULL,
    next_attempt_at DATETIME     NOT NULL,
    created_at      DATETIME     NOT NULL,
    sent_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_once (notification_id, user_id, channel),
    KEY idx_delivery_due (status, next_attempt_at),
    CONSTRAINT fk_delivery_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic request throttle (see app/Services/Throttle.php).
CREATE TABLE rate_limits (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket       VARCHAR(64)  NOT NULL,
    identifier   CHAR(64)     NOT NULL,   -- HMAC of the user id or IP, never the raw value
    window_start DATETIME     NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_window (bucket, identifier, window_start),
    KEY idx_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED DATA (also inserted programmatically by install.php)
-- =====================================================================

INSERT INTO roles (slug, name, is_system, created_at) VALUES
    ('super_admin', 'مدیر ارشد', 1, NOW()),
    ('admin',       'مدیر',      1, NOW()),
    ('student',     'دانشجو',    1, NOW());

INSERT INTO permissions (slug, name, module, created_at) VALUES
    ('manage_students',      'مدیریت دانشجویان',      'students', NOW()),
    ('manage_courses',       'مدیریت دوره‌ها',         'courses',  NOW()),
    ('manage_content',       'مدیریت محتوای آموزشی',   'content',  NOW()),
    ('manage_schedule',      'مدیریت برنامه هفتگی',    'schedule', NOW()),
    ('manage_exams',         'مدیریت امتحانات',        'exams',    NOW()),
    ('manage_calendar',      'مدیریت تقویم',           'calendar', NOW()),
    ('manage_messages',      'مدیریت پیام‌ها',          'comms',    NOW()),
    ('manage_notifications', 'مدیریت اطلاعیه‌ها',       'comms',    NOW()),
    ('view_sessions',        'مشاهده نشست‌ها',          'security', NOW()),
    ('terminate_sessions',   'خروج اجباری نشست‌ها',     'security', NOW()),
    ('view_logs',            'مشاهده گزارش فعالیت',    'security', NOW()),
    ('manage_admins',        'مدیریت مدیران',          'system',   NOW()),
    ('manage_roles',         'مدیریت نقش‌ها و دسترسی', 'system',   NOW()),
    ('manage_settings',      'مدیریت تنظیمات',         'system',   NOW()),
    ('manage_packages',      'مدیریت پکیج‌ها',          'courses',  NOW());

-- Super admin gets everything (admins are granted individually in the panel).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'super_admin';

INSERT INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('single_device_enabled',    '1',            'bool',   0, NOW()),
    ('single_device_behavior',   'block_new',    'string', 0, NOW()), -- block_new | force_logout_previous
    ('single_device_admins',     '0',            'bool',   0, NOW()), -- apply the one-session rule to admins too
    ('session_absolute_timeout', '7200',         'int',    0, NOW()),
    ('session_idle_timeout',     '1800',         'int',    0, NOW()),
    ('admin_idle_timeout',       '900',          'int',    0, NOW()),
    ('login_max_attempts',       '5',            'int',    0, NOW()),
    ('login_lockout_seconds',    '900',          'int',    0, NOW()),
    ('viewer_token_ttl',         '90',           'int',    0, NOW()),
    ('heartbeat_interval',       '25',           'int',    1, NOW()),
    ('heartbeat_grace',          '15',           'int',    0, NOW()),
    ('study_idle_cutoff',        '120',          'int',    0, NOW()),
    ('watermark_enabled',        '1',            'bool',   0, NOW()),
    ('print_protection_enabled', '1',            'bool',   1, NOW()),
    ('content_cache_mode',       'revalidate',   'string', 0, NOW()), -- strict | revalidate
    ('content_max_upload_mb',    '25',           'int',    0, NOW()),
    ('viewer_allow_external_fonts', '1',         'bool',   0, NOW()),
    ('viewer_local_font',        '1',            'bool',   0, NOW()), -- inject the self-hosted Persian face as a fallback
    ('two_factor_enabled',       '0',            'bool',   0, NOW()),
    ('app_name',                 'HeleXa Med',   'string', 1, NOW()),
    ('log_retention_days',       '180',          'int',    0, NOW()),
    ('attempt_retention_days',   '30',           'int',    0, NOW()),
    ('maintenance_last_run',     '0',            'int',    0, NOW()),
    ('offline_enabled',          '1',            'bool',   1, NOW()),
    ('offline_lease_days',       '14',           'int',    0, NOW()),
    ('offline_max_event_seconds','7200',         'int',    0, NOW()),
    ('offline_max_daily_seconds','28800',        'int',    0, NOW()),
    ('offline_max_contents',     '60',           'int',    0, NOW()),
    ('remember_me_enabled',      '1',            'bool',   1, NOW()),
    ('remember_me_days',         '30',           'int',    0, NOW()),
    ('guest_mode_enabled',       '1',            'bool',   1, NOW()),
    ('guest_watermark_text',     'نسخه نمایشی — helexapanel.ir', 'string', 0, NOW()),
    ('highlight_enabled',        '1',            'bool',   1, NOW()),
    ('highlight_max_per_content','300',          'int',    0, NOW()),
    ('site_logo_path',           '',             'string', 1, NOW()),
    ('avatar_male_path',         '',             'string', 1, NOW()),
    ('avatar_female_path',       '',             'string', 1, NOW()),
    ('telegram_bot_enabled',     '0',            'bool',   0, NOW()),
    ('telegram_bot_token',       '',             'string', 0, NOW()),
    ('telegram_webhook_secret',  '',             'string', 0, NOW()),
    ('telegram_bot_username',    '',             'string', 1, NOW()),
    ('telegram_queue_last_drain', '0',           'int',    0, NOW());
