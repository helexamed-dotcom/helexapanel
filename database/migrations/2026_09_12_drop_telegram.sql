-- =====================================================================
--  Removes the Telegram bot.
--
--  Run this on an installation that already has the bot's tables. A fresh
--  install never creates them: they are gone from schema.sql as well.
--
--  notification_deliveries is deliberately kept — it is channel-agnostic
--  and outlives any single channel — but the rows that belonged to the
--  Telegram channel are cleared out with the rest.
-- =====================================================================

DELETE FROM notification_deliveries WHERE channel = 'telegram';

DROP TABLE IF EXISTS telegram_states;
DROP TABLE IF EXISTS telegram_accounts;

DELETE FROM settings WHERE setting_key IN (
    'telegram_bot_enabled',
    'telegram_bot_token',
    'telegram_webhook_secret',
    'telegram_bot_username',
    'telegram_queue_last_drain'
);
