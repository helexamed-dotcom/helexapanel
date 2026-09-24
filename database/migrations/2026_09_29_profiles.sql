-- ============================================================================
--  The student profile — one score for the whole site, and an Instagram-like
--  page around it.
--
--  balin_xp_transactions  the ledger Balin island started now takes the
--                         other sections' rewards too: flashcard reviews,
--                         the figure game, reading a درسنامه, finishing an
--                         exam, finishing a mind map.
--  user_profiles          the student's own choices: a handle, a bio, a
--                         colour, whether the account is private, whether
--                         they appear in the rankings, and which parts of
--                         the profile others may see.
--  profile_posts          a post: text and an optional image.
--  profile_post_likes     one like per student per post.
--  profile_follows        who follows whom. On a private account a follow
--                         waits for approval (status = 'pending').
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE balin_xp_transactions
    MODIFY COLUMN type ENUM('question_correct','lesson_complete','stage_complete','final_case','daily_mission','weekly_mission',
                            'achievement','checkpoint_exam_passed','admin_adjustment',
                            'flashcard_review','figure_correct','lesson_read','exam_finished','mindmap_done') NOT NULL;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id              BIGINT UNSIGNED NOT NULL,
    handle               VARCHAR(30)     NULL,
    bio                  VARCHAR(300)    NULL,
    tone                 VARCHAR(16)     NOT NULL DEFAULT 'indigo',
    is_private           TINYINT(1)      NOT NULL DEFAULT 0,
    show_in_leaderboard  TINYINT(1)      NOT NULL DEFAULT 1,
    visibility           JSON            NULL,
    created_at           DATETIME        NOT NULL,
    updated_at           DATETIME        NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_profiles_handle (handle),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_posts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    body        VARCHAR(2000)   NULL,
    image_path  VARCHAR(191)    NULL,
    tone        VARCHAR(16)     NOT NULL DEFAULT 'indigo',
    audience    ENUM('everyone','followers','me') NOT NULL DEFAULT 'everyone',
    like_count  INT UNSIGNED    NOT NULL DEFAULT 0,
    hidden_at   DATETIME        NULL,
    hidden_by   BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL,
    deleted_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_profile_posts_uuid (uuid),
    KEY idx_profile_posts_user (user_id, deleted_at, created_at),
    KEY idx_profile_posts_feed (deleted_at, hidden_at, created_at),
    CONSTRAINT fk_profile_posts_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_posts_hidder FOREIGN KEY (hidden_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_post_likes (
    post_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (post_id, user_id),
    KEY idx_profile_likes_user (user_id),
    CONSTRAINT fk_profile_likes_post FOREIGN KEY (post_id) REFERENCES profile_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_follows (
    follower_id  BIGINT UNSIGNED NOT NULL,
    followee_id  BIGINT UNSIGNED NOT NULL,
    status       ENUM('accepted','pending') NOT NULL DEFAULT 'accepted',
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (follower_id, followee_id),
    KEY idx_profile_follows_followee (followee_id, status),
    CONSTRAINT fk_profile_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_follows_followee FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('points.manage', 'امتیازها، لیگ‌ها و پست‌های پروفایل دانشجوها', 'points', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'points';
