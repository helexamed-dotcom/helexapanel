-- ============================================================================
--  درسنامه → زیردرس → صفحه, and one set of tags for everything
--
--  lesson_sections     a درسنامه's زیردرس‌ها / فهرست (e.g. «باکتری‌شناسی» →
--                      «کلیات»، «کوکسی‌های گرم مثبت»…), in order.
--  lesson_pages        the pages of a زیردرس, each with its own title, body
--                      and tags; the student reads page by page.
--  lesson_page_tags    a page's tags — the SAME qb_tags as the questions, the
--                      figure game, the flashcard decks, بالین and mind maps,
--                      so «کلیات باکتری‌شناسی» is one tag everywhere and the
--                      analysis can join them all.
--  lesson_page_reads   each student's own layer on one page: highlights and
--                      when they finished it.
--  fc_deck_tags, balin_lesson_tags, mindmap_tags
--                      the same tags on a flashcard deck, a بالین lesson and a
--                      mind map.
--
--  Every existing درسنامه becomes one زیردرس with one page holding its body,
--  its tags and every student's highlights, so nothing is lost. Additive and
--  safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_sections (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    lesson_id   INT UNSIGNED  NOT NULL,
    title       VARCHAR(191)  NOT NULL,
    sort_order  SMALLINT      NOT NULL DEFAULT 0,
    created_at  DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lesson_sections_lesson (lesson_id, sort_order),
    CONSTRAINT fk_lesson_sections_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_pages (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)      NOT NULL,
    lesson_id       INT UNSIGNED  NOT NULL,
    section_id      INT UNSIGNED  NOT NULL,
    title           VARCHAR(191)  NOT NULL,
    body_html       MEDIUMTEXT    NOT NULL,
    reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order      SMALLINT      NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_pages_uuid (uuid),
    KEY idx_lesson_pages_section (section_id, sort_order),
    KEY idx_lesson_pages_lesson (lesson_id),
    CONSTRAINT fk_lesson_pages_lesson  FOREIGN KEY (lesson_id)  REFERENCES lessons(id)         ON DELETE CASCADE,
    CONSTRAINT fk_lesson_pages_section FOREIGN KEY (section_id) REFERENCES lesson_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_page_tags (
    page_id INT UNSIGNED NOT NULL,
    tag_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (page_id, tag_id),
    KEY idx_lesson_page_tags_tag (tag_id),
    CONSTRAINT fk_lesson_page_tags_page FOREIGN KEY (page_id) REFERENCES lesson_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_page_tags_tag  FOREIGN KEY (tag_id)  REFERENCES qb_tags(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_page_reads (
    user_id    BIGINT UNSIGNED NOT NULL,
    page_id    INT UNSIGNED    NOT NULL,
    highlights JSON            NULL,
    read_at    DATETIME        NULL,
    opened_at  DATETIME        NOT NULL,
    updated_at DATETIME        NULL,
    PRIMARY KEY (user_id, page_id),
    KEY idx_lesson_page_reads_page (page_id),
    CONSTRAINT fk_lesson_page_reads_user FOREIGN KEY (user_id) REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_lesson_page_reads_page FOREIGN KEY (page_id) REFERENCES lesson_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where the student left off, so the درسنامه opens on that page.
ALTER TABLE lesson_reads ADD COLUMN IF NOT EXISTS last_page_id INT UNSIGNED NULL AFTER progress;

-- ---------------------------------------------------------------- existing
-- One زیردرس and one page per درسنامه that has none yet.
INSERT INTO lesson_sections (lesson_id, title, sort_order, created_at)
SELECT l.id, l.title, 0, NOW() FROM lessons l
WHERE NOT EXISTS (SELECT 1 FROM lesson_sections s WHERE s.lesson_id = l.id);

INSERT INTO lesson_pages (uuid, lesson_id, section_id, title, body_html, reading_minutes, sort_order, created_at, updated_at)
SELECT UUID(), l.id, (SELECT MIN(s.id) FROM lesson_sections s WHERE s.lesson_id = l.id), l.title, l.body_html, l.reading_minutes, 0, NOW(), l.updated_at
FROM lessons l
WHERE l.body_html <> '' AND NOT EXISTS (SELECT 1 FROM lesson_pages p WHERE p.lesson_id = l.id);

INSERT IGNORE INTO lesson_page_tags (page_id, tag_id)
SELECT p.id, lt.tag_id FROM lesson_pages p JOIN lesson_tags lt ON lt.lesson_id = p.lesson_id
WHERE p.id = (SELECT MIN(p2.id) FROM lesson_pages p2 WHERE p2.lesson_id = p.lesson_id)
  AND NOT EXISTS (SELECT 1 FROM lesson_page_tags x JOIN lesson_pages px ON px.id = x.page_id WHERE px.lesson_id = p.lesson_id);

INSERT IGNORE INTO lesson_page_reads (user_id, page_id, highlights, read_at, opened_at, updated_at)
SELECT r.user_id, (SELECT MIN(p.id) FROM lesson_pages p WHERE p.lesson_id = r.lesson_id), r.highlights, r.read_at, r.opened_at, r.updated_at
FROM lesson_reads r
WHERE EXISTS (SELECT 1 FROM lesson_pages p WHERE p.lesson_id = r.lesson_id);

-- ---------------------------------------------------------------- tags elsewhere
CREATE TABLE IF NOT EXISTS fc_deck_tags (
    deck_id INT UNSIGNED NOT NULL,
    tag_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (deck_id, tag_id),
    KEY idx_fc_deck_tags_tag (tag_id),
    CONSTRAINT fk_fc_deck_tags_deck FOREIGN KEY (deck_id) REFERENCES fc_decks(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_deck_tags_tag  FOREIGN KEY (tag_id)  REFERENCES qb_tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balin_lesson_tags (
    lesson_id INT UNSIGNED NOT NULL,
    tag_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (lesson_id, tag_id),
    KEY idx_balin_lesson_tags_tag (tag_id),
    CONSTRAINT fk_balin_lesson_tags_lesson FOREIGN KEY (lesson_id) REFERENCES balin_lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_balin_lesson_tags_tag    FOREIGN KEY (tag_id)    REFERENCES qb_tags(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mindmap_tags (
    mindmap_id INT UNSIGNED NOT NULL,
    tag_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (mindmap_id, tag_id),
    KEY idx_mindmap_tags_tag (tag_id),
    CONSTRAINT fk_mindmap_tags_map FOREIGN KEY (mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE,
    CONSTRAINT fk_mindmap_tags_tag FOREIGN KEY (tag_id)     REFERENCES qb_tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
