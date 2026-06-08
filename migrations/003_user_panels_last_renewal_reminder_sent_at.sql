-- Used by suspend_expired cron to dedupe renewal reminder emails.
-- Without it the hourly cron would email customers up to 24 times per day.
ALTER TABLE user_panels
  ADD COLUMN last_renewal_reminder_sent_at DATETIME NULL AFTER bypass_suspension;
