-- ============================================================================
--  «نقشه‌های ذهنی» — XMind-like maps drawn in the site's own editor
--
--  mindmaps        one map. The whole tree is one JSON document (a central
--                  topic and its branches, each node with its text, note,
--                  colour, marker, image and optional درسنامه), validated
--                  on save. Filed under a درس like درسنامه‌ها, optionally
--                  only for holders of a package.
--  mindmap_links   the درسنامه‌ها a map's nodes point at, kept in step
--                  with the tree on every save, so a درسنامه can list the
--                  maps that teach it.
--  mindmap_reads   a student's «مرور کردم» (paid once) and when they last
--                  opened the map.
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mindmaps (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    title       VARCHAR(191)    NOT NULL,
    summary     VARCHAR(300)    NULL,
    subject_id  INT UNSIGNED    NULL,
    package_id  INT UNSIGNED    NULL,
    theme       VARCHAR(16)     NOT NULL DEFAULT 'classic',
    layout      VARCHAR(8)      NOT NULL DEFAULT 'map',
    tone        VARCHAR(16)     NOT NULL DEFAULT 'violet',
    data        MEDIUMTEXT      NOT NULL,
    node_count  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NULL,
    deleted_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mindmaps_uuid (uuid),
    KEY idx_mindmaps_list (status, deleted_at, sort_order),
    KEY idx_mindmaps_subject (subject_id),
    CONSTRAINT fk_mindmaps_subject FOREIGN KEY (subject_id) REFERENCES qb_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_mindmaps_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_mindmaps_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mindmap_links (
    mindmap_id INT UNSIGNED NOT NULL,
    lesson_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (mindmap_id, lesson_id),
    KEY idx_mindmap_links_lesson (lesson_id),
    CONSTRAINT fk_mindmap_links_map    FOREIGN KEY (mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE,
    CONSTRAINT fk_mindmap_links_lesson FOREIGN KEY (lesson_id)  REFERENCES lessons(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mindmap_reads (
    user_id     BIGINT UNSIGNED NOT NULL,
    mindmap_id  INT UNSIGNED    NOT NULL,
    opened_at   DATETIME        NOT NULL,
    done_at     DATETIME        NULL,
    PRIMARY KEY (user_id, mindmap_id),
    KEY idx_mindmap_reads_map (mindmap_id),
    CONSTRAINT fk_mindmap_reads_user FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_mindmap_reads_map  FOREIGN KEY (mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
