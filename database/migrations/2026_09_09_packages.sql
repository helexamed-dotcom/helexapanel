-- =====================================================================
--  Packages, activations and the central notification path
-- =====================================================================

-- The client character set must be declared before any Persian literal
-- below. Without it a CLI whose default is latin1 stores the UTF-8 bytes
-- a second time over, and every seeded string arrives double-encoded.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS packages (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                   CHAR(36)     NOT NULL,
    title                  VARCHAR(191) NOT NULL,
    description            TEXT         NULL,
    color                  VARCHAR(16)  NULL,
    status                 ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    -- When a course is added later, should everyone who already holds the
    -- package receive it as well? Off by default: silently widening access
    -- should be a decision, not a side effect.
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

CREATE TABLE IF NOT EXISTS package_courses (
    package_id INT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    added_at   DATETIME     NOT NULL,
    PRIMARY KEY (package_id, course_id),
    KEY idx_package_courses_course (course_id),
    CONSTRAINT fk_pkg_courses_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    CONSTRAINT fk_pkg_courses_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_activations (
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

-- Notifications learn what they are about, and gain an idempotency key so a
-- retried activation cannot produce a second copy of the same announcement.
ALTER TABLE notifications
    MODIFY COLUMN notif_type ENUM('content','schedule','exam','course','package','message','system')
        NOT NULL DEFAULT 'system',
    ADD COLUMN related_type    VARCHAR(32) NULL AFTER notif_type,
    ADD COLUMN related_id      BIGINT UNSIGNED NULL AFTER related_type,
    ADD COLUMN idempotency_key VARCHAR(96) NULL AFTER related_id,
    ADD UNIQUE KEY uq_notifications_idempotency (idempotency_key),
    ADD KEY idx_notifications_related (related_type, related_id);

-- Managing packages is its own permission, so a course editor does not
-- automatically gain the ability to grant access to bundles.
INSERT IGNORE INTO permissions (slug, name, module, created_at)
VALUES ('manage_packages', 'مدیریت پکیج‌ها', 'courses', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.slug = 'manage_packages';
