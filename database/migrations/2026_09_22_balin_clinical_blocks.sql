-- ============================================================================
--  Balin island — clinical block types
--
--  vitals     علائم حیاتی (one reading per line: name | value | unit | flag)
--  lab        نتایج آزمایش (test | result | unit | normal range | flag)
--  pearl      نکته کلیدی
--  ddx        تشخیص افتراقی (one diagnosis per line)
--  reference  منبع
--
--  Only widens the ENUM; existing rows are untouched. Safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE balin_blocks
    MODIFY COLUMN block_type ENUM('chat','question','image','audio','video','text','finding','hint',
                                  'warning','system','divider','checkpoint_anchor',
                                  'vitals','lab','pearl','ddx','reference') NOT NULL;