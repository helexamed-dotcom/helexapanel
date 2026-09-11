-- =====================================================================
--  Repairs the sessions table after the empty-session-id bug.
--
--  php_session_id carries a unique index. A unique index permits any
--  number of NULLs but only one empty string, so the first row written
--  with '' — which happens when PHP cannot start a session, usually an
--  unwritable storage/sessions — took the slot, and every later login
--  anywhere on the site failed with:
--
--      Duplicate entry '' for key 'uq_sessions_php_sid'
--
--  Two changes: the column may now be NULL, and the rows already
--  carrying '' are converted so they stop blocking the slot. Those rows
--  were never usable as sessions — nothing can match an empty id — so
--  they are closed rather than left looking active.
--
--  Safe to run more than once.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE sessions
    MODIFY COLUMN php_session_id VARCHAR(128) NULL;

UPDATE sessions
   SET is_active          = 0,
       logout_at          = COALESCE(logout_at, NOW()),
       termination_reason = COALESCE(termination_reason, 'security'),
       php_session_id     = NULL
 WHERE php_session_id = '';
