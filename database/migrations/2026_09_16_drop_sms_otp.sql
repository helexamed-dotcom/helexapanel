-- ============================================================================
--  Removes the SMS gateway, the texted sign-in code, and SMS password reset.
--
--  Run order: after 2026_09_14_phone_auth.sql. Safe to re-run.
--
--  SET NAMES is here because the client's default may be latin1, and without
--  it the Persian text further down would be stored double-encoded. Every
--  other migration in this project that carries Persian does the same.
-- ============================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  STOP AND READ THIS BEFORE RUNNING — accounts that would be locked out
--
--  The phone_auth migration made users.password_hash nullable so that an
--  account created by texted code alone could exist without a password.
--  With the code path gone, such an account has no way to sign in at all.
--
--  Run this FIRST and deal with what it returns:
--
--      SELECT id, uuid, username, mobile, full_name, created_at
--      FROM users
--      WHERE password_hash IS NULL AND deleted_at IS NULL;
--
--  For each row, either set a temporary password from the admin panel
--  (Students -> the student -> reset password), or soft-delete the account
--  if it was never really used. The column is deliberately NOT made NOT NULL
--  again by this migration: doing so would abort the whole migration on the
--  first such row, which is a confusing way to learn that those accounts
--  exist. It stays nullable and harmless once the rows are dealt with.
-- ---------------------------------------------------------------------------

-- ------------------------------------------------------------ otp_codes
-- Every issued code lived here. Nothing else reads this table: the cooldown
-- and hourly ceilings it backed were only ever consulted by the OTP service.
DROP TABLE IF EXISTS otp_codes;

-- ------------------------------------------------- telegram, if still present
-- These were dropped by 2026_09_12_drop_telegram.sql. Repeated here so that an
-- installation which never ran that migration ends up in the same state as one
-- that did, rather than silently keeping two orphan tables.
DROP TABLE IF EXISTS telegram_states;
DROP TABLE IF EXISTS telegram_accounts;

-- ------------------------------------------------------------- settings
-- The gateway credentials go too. sms_api_key_enc held the operator password
-- encrypted under the application key; leaving it behind would mean a live
-- credential sitting in a table nothing reads any more.
DELETE FROM settings WHERE setting_key IN (
    'sms_enabled',
    'sms_provider',
    'sms_username',
    'sms_from',
    'sms_api_key_enc',
    'otp_enabled',
    'otp_registration_enabled',
    'otp_ttl_seconds',
    'otp_resend_seconds',
    'otp_max_attempts',
    'otp_max_per_hour',
    'otp_max_per_ip_per_hour',
    'otp_message_template'
);

-- Any Telegram settings an older installation may still carry.
DELETE FROM settings WHERE setting_key LIKE 'telegram\_%';

-- ------------------------------------------------------- rate limit rows
-- The throttle buckets these routes used. Harmless if left, but they name
-- endpoints that no longer exist, which makes the table misleading to read.
DELETE FROM rate_limits WHERE bucket IN (
    'otp_request', 'otp_verify', 'password_reset', 'sms_test'
);

-- ------------------------------------------------- notification deliveries
-- notification_deliveries itself is kept: it is channel-agnostic and outlives
-- any one channel. Only the rows naming a channel that is gone are removed.
DELETE FROM notification_deliveries WHERE channel = 'sms';

-- ---------------------------------------------------------------------------
--  registration_source and phone_verified_at are deliberately KEPT.
--
--  Both are historical facts about accounts that already exist — which ones
--  signed themselves up, and which numbers were proved — not switches for a
--  feature. Dropping them would rewrite that history to say every account was
--  created by an admin. They are simply never written to again.
-- ---------------------------------------------------------------------------
