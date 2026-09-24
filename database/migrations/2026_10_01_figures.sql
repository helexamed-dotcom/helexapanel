-- ============================================================================
--  «بازی با شکل» — an image (an atlas page, a diagram) with hotspots on it
--
--  figures        one image and its settings, filed under a درس like the
--                 other study material, optionally only for a package.
--  figure_spots   one hotspot: where it is (percent of the image, so it
--                 survives any display size), its name, an optional
--                 question of its own with choices, a hint and an
--                 explanation, and the shared tag / درسنامه it belongs to.
--                 The game asks «find X» (tap the spot) or points at a spot
--                 and asks about it.
--  figure_plays   one finished round: the mode, the score, the time — for
--                 the student's best and the admin's numbers.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS figures (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36)        NOT NULL,
    title        VARCHAR(191)    NOT NULL,
    summary      VARCHAR(300)    NULL,
    image_path   VARCHAR(191)    NULL,
    image_w      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    image_h      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    subject_id   INT UNSIGNED    NULL,
    package_id   INT UNSIGNED    NULL,
    tone         VARCHAR(16)     NOT NULL DEFAULT 'amber',
    status       ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order   SMALLINT        NOT NULL DEFAULT 0,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL,
    updated_at   DATETIME        NULL,
    deleted_at   DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_figures_uuid (uuid),
    KEY idx_figures_list (status, deleted_at, sort_order),
    KEY idx_figures_subject (subject_id),
    CONSTRAINT fk_figures_subject FOREIGN KEY (subject_id) REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_figures_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_figures_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS figure_spots (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    figure_id    INT UNSIGNED    NOT NULL,
    skey         VARCHAR(16)     NOT NULL,
    label        VARCHAR(160)    NOT NULL,
    x            DECIMAL(6,3)    NOT NULL,
    y            DECIMAL(6,3)    NOT NULL,
    r            DECIMAL(6,3)    NOT NULL DEFAULT 4.000,
    question     VARCHAR(500)    NULL,
    options      JSON            NULL,
    hint         VARCHAR(300)    NULL,
    explanation  VARCHAR(1000)   NULL,
    tag_id       INT UNSIGNED    NULL,
    lesson_id    INT UNSIGNED    NULL,
    sort_order   SMALLINT        NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_figure_spots_key (figure_id, skey),
    KEY idx_figure_spots_tag (tag_id),
    KEY idx_figure_spots_lesson (lesson_id),
    CONSTRAINT fk_figure_spots_figure FOREIGN KEY (figure_id) REFERENCES figures(id) ON DELETE CASCADE,
    CONSTRAINT fk_figure_spots_tag    FOREIGN KEY (tag_id)    REFERENCES qb_tags(id) ON DELETE SET NULL,
    CONSTRAINT fk_figure_spots_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS figure_plays (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    figure_id    INT UNSIGNED    NOT NULL,
    mode         ENUM('find','ask') NOT NULL,
    total        SMALLINT UNSIGNED NOT NULL,
    correct      SMALLINT UNSIGNED NOT NULL,
    seconds      INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_figure_plays_user (user_id, figure_id),
    CONSTRAINT fk_figure_plays_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_figure_plays_figure FOREIGN KEY (figure_id) REFERENCES figures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('figures.manage', 'ساخت و ویرایش بازی با شکل', 'figures', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'figures';
