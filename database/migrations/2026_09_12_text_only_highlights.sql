-- =====================================================================
--  Highlights become text-only.
--
--  Drawing a box over a region of an image was removed from the viewer, so
--  the rows it produced can no longer be drawn by anything. They are deleted
--  rather than left to accumulate, and the column stops accepting the value.
-- =====================================================================

DELETE FROM content_highlights WHERE kind = 'area';

ALTER TABLE content_highlights
    MODIFY kind ENUM('text') NOT NULL DEFAULT 'text';
