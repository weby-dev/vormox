-- Admin-only flag. When 1, the hourly suspend_expired cron skips this panel.
-- Used to exempt VIP customers from automated suspension.
ALTER TABLE user_panels
  ADD COLUMN bypass_suspension TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_renew;
