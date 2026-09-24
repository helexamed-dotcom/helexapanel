-- ============================================================================
--  Self-registration and free packages
--
--  users.registration_source  gains 'self': an account the student opened
--                             from /register (switched on in the settings).
--
--  packages.is_free           a package every student receives: each new
--                             account on creation, existing ones when the
--                             admin marks the package free or presses
--                             "give it to everyone".
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE users
    MODIFY COLUMN registration_source ENUM('admin','self_otp','self') NOT NULL DEFAULT 'admin';

ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS is_free TINYINT(1) NOT NULL DEFAULT 0 AFTER is_full_access;
