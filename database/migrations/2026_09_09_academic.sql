-- =====================================================================
--  Academic hierarchy: University -> Major -> Term -> Group
--
--  Additive and non-destructive. The existing terms keep working: they
--  simply have no major attached and behave as general terms until an
--  admin assigns them.
-- =====================================================================

-- The client character set must be declared before any Persian literal
-- below. Without it a CLI whose default is latin1 stores the UTF-8 bytes
-- a second time over, and every seeded string arrives double-encoded.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS universities (
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

CREATE TABLE IF NOT EXISTS majors (
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

-- Terms become scoped to a major. NULL means "general", which is what every
-- term created before this migration is, so nothing that already works breaks.
ALTER TABLE terms
    ADD COLUMN major_id SMALLINT UNSIGNED NULL AFTER id,
    ADD KEY idx_terms_major (major_id, sort_order),
    ADD CONSTRAINT fk_terms_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE;

-- The unique key on terms.title has to go: two majors may both have a "ترم ۵".
ALTER TABLE terms DROP INDEX uq_terms_title;
ALTER TABLE terms ADD UNIQUE KEY uq_terms_major_title (major_id, title);

ALTER TABLE users
    ADD COLUMN university_id SMALLINT UNSIGNED NULL AFTER major,
    ADD COLUMN major_id      SMALLINT UNSIGNED NULL AFTER university_id,
    ADD COLUMN gender        ENUM('male','female') NULL AFTER full_name,
    ADD KEY idx_users_university (university_id, major_id),
    ADD CONSTRAINT fk_users_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_major      FOREIGN KEY (major_id)      REFERENCES majors(id)       ON DELETE SET NULL;

-- A student can carry units from more than one term at the same time.
-- users.term_id stays as the primary term so every existing query keeps
-- working; this table is the full set.
CREATE TABLE IF NOT EXISTS user_semesters (
    user_id    BIGINT UNSIGNED   NOT NULL,
    term_id    SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME          NOT NULL,
    PRIMARY KEY (user_id, term_id),
    KEY idx_user_semesters_term (term_id),
    CONSTRAINT fk_user_semesters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_semesters_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Students who already had a single term keep it as their first selection.
INSERT IGNORE INTO user_semesters (user_id, term_id, created_at)
SELECT id, term_id, NOW() FROM users WHERE term_id IS NOT NULL AND deleted_at IS NULL;
