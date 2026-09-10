-- =====================================================================
--  Widens announcement targeting: university, major, course, package —
--  on top of the existing all/term/group/user audiences.
-- =====================================================================

ALTER TABLE notifications
    MODIFY COLUMN audience ENUM('all','term','group','university','major','course','package','user')
        NOT NULL DEFAULT 'all',
    ADD COLUMN university_id SMALLINT UNSIGNED NULL AFTER group_id,
    ADD COLUMN major_id      SMALLINT UNSIGNED NULL AFTER university_id,
    ADD COLUMN course_id     INT UNSIGNED NULL AFTER major_id,
    ADD COLUMN package_id    INT UNSIGNED NULL AFTER course_id,
    ADD KEY idx_notif_university (university_id),
    ADD KEY idx_notif_major (major_id),
    ADD KEY idx_notif_course (course_id),
    ADD KEY idx_notif_package (package_id),
    ADD CONSTRAINT fk_notif_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_notif_major      FOREIGN KEY (major_id)      REFERENCES majors(id)        ON DELETE CASCADE,
    ADD CONSTRAINT fk_notif_course     FOREIGN KEY (course_id)     REFERENCES courses(id)        ON DELETE CASCADE,
    ADD CONSTRAINT fk_notif_package    FOREIGN KEY (package_id)    REFERENCES packages(id)       ON DELETE CASCADE;
