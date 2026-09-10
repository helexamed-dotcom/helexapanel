-- =====================================================================
--  Subjects independent of content courses, and brand/media settings.
-- =====================================================================

-- A "subject" is a class period's name for scheduling and exams. It is
-- deliberately a separate table from `courses`: a course is a content-bearing
-- unit in the LMS, a subject is just a label an admin manages so weekly
-- schedules and exams stay consistent without ever requiring LMS content.
CREATE TABLE IF NOT EXISTS subjects (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    major_id   SMALLINT UNSIGNED NULL,     -- NULL = general, offered to every major
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

ALTER TABLE schedule_items
    ADD COLUMN subject_id SMALLINT UNSIGNED NULL AFTER course_id,
    ADD KEY idx_items_subject (subject_id),
    ADD CONSTRAINT fk_items_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL;

ALTER TABLE exams
    ADD COLUMN subject_id SMALLINT UNSIGNED NULL AFTER course_id,
    ADD KEY idx_exams_subject (subject_id),
    ADD CONSTRAINT fk_exams_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL;

-- Brand assets: empty string means "use the built-in default". The path
-- stored is relative to public_html/assets/ (e.g. "images/logo.png").
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, is_public, updated_at) VALUES
    ('site_logo_path',     '', 'string', 1, NOW()),
    ('avatar_male_path',   '', 'string', 1, NOW()),
    ('avatar_female_path', '', 'string', 1, NOW());
