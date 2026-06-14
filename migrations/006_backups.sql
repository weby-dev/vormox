-- Tracks every DB backup uploaded to S3 by cron/db_backup.php.
-- One row per dump; status flows pending -> uploaded | failed.
CREATE TABLE IF NOT EXISTS backups (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  panel_id      INT NOT NULL,
  user_id       INT NOT NULL,
  subscription  VARCHAR(255) NOT NULL,          -- S3 top-level folder (panel domain)
  db_name       VARCHAR(255) NOT NULL,
  s3_key        VARCHAR(512) NOT NULL,          -- <sub>/<YYYY-MM-DD>/<db>_<ts>.sql.xz
  size_bytes    BIGINT NULL,
  table_count   INT NULL,
  status        ENUM('pending','uploaded','failed') NOT NULL DEFAULT 'pending',
  error         TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_at   DATETIME NULL,
  UNIQUE KEY uniq_s3_key (s3_key),
  KEY idx_panel (panel_id),
  KEY idx_user  (user_id),
  KEY idx_sub   (subscription)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
