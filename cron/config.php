<?php
// cron/config.php — cron-side configuration.
// All secrets are read from .env (loaded by the bootstrap via includes/env.php).
// Non-secret tunables stay as constants here.

// ---------------------------------------------------------------------------
// Backend lifecycle endpoint
// ---------------------------------------------------------------------------
define('CRON_SECRET',              vormox_env('CRON_SECRET', ''));
define('BACKEND_LIFECYCLE_PORT',   (int) vormox_env('BACKEND_LIFECYCLE_PORT',   8080));
define('BACKEND_LIFECYCLE_PATH',   vormox_env('BACKEND_LIFECYCLE_PATH',         '/api/internal/cron/trigger-lifecycle'));
define('LIFECYCLE_HTTP_TIMEOUT',   (int) vormox_env('LIFECYCLE_HTTP_TIMEOUT',   8));
define('LIFECYCLE_CONNECT_TIMEOUT',(int) vormox_env('LIFECYCLE_CONNECT_TIMEOUT', 3));

// ---------------------------------------------------------------------------
// Suspension policy
// ---------------------------------------------------------------------------
define('SUSPEND_AFTER_DAYS',           (int) vormox_env('SUSPEND_AFTER_DAYS',           7));
define('REMINDER_DAYS_BEFORE_EXPIRY',  (int) vormox_env('REMINDER_DAYS_BEFORE_EXPIRY',  5));

// ---------------------------------------------------------------------------
// Mail (ZeptoMail — same provider as auth_guard.php)
// ---------------------------------------------------------------------------
define('ZEPTOMAIL_AUTH',         vormox_env('ZEPTOMAIL_AUTH',     ''));
define('CRON_MAIL_FROM_ADDRESS', vormox_env('MAIL_FROM_ADDRESS', 'noreply@getwebup.com'));
define('CRON_MAIL_FROM_NAME',    vormox_env('MAIL_FROM_NAME',    'Vormox Billing'));

// Used in email "Renew" button links.
define('SITE_URL',               vormox_env('SITE_URL', 'https://app.vormox.com'));
