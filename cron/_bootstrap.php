<?php
// cron/_bootstrap.php
// Shared bootstrap for all cron scripts: DB, single-instance lock, logging, mailer.

// CLI-only. Refuse browser execution so cron scripts can't be triggered by accident.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Cron scripts must be executed from CLI.\n");
}

require_once __DIR__ . '/../config.php';                // $pdo
require_once __DIR__ . '/config.php';                   // CRON_SECRET, ZEPTOMAIL_AUTH, etc.
require_once __DIR__ . '/../includes/email_layout.php'; // shared brand template

// ---------------------------------------------------------------------------
// Single-instance lock — refuses to start if a previous run is still going.
// Critical for the 5-minute lifecycle job: if one tick takes >5 minutes,
// we don't want a second tick stampeding the backends.
// ---------------------------------------------------------------------------
$cron_script    = basename($argv[0] ?? 'cron');
$cron_lock_path = sys_get_temp_dir() . '/vormox_cron_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cron_script) . '.lock';
$cron_lock_fp   = fopen($cron_lock_path, 'c');
if (!$cron_lock_fp || !flock($cron_lock_fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Skipping {$cron_script}: previous run still in progress.\n");
    exit(0);
}
register_shutdown_function(function () use ($cron_lock_fp) {
    if ($cron_lock_fp) { @flock($cron_lock_fp, LOCK_UN); @fclose($cron_lock_fp); }
});

// ---------------------------------------------------------------------------
// Logging helper — timestamped stdout. Pipe to a logfile from cron.
// ---------------------------------------------------------------------------
function cron_log($msg) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

// ---------------------------------------------------------------------------
// ZeptoMail sender — returns true on HTTP 2xx, false otherwise.
// ---------------------------------------------------------------------------
function cron_send_email($toEmail, $toName, $subject, $htmlBody) {
    $payload = json_encode([
        'from'     => ['address' => CRON_MAIL_FROM_ADDRESS, 'name' => CRON_MAIL_FROM_NAME],
        'to'       => [['email_address' => ['address' => $toEmail, 'name' => $toName]]],
        'subject'  => $subject,
        'htmlbody' => $htmlBody,
    ]);

    $ch = curl_init('https://api.zeptomail.in/v1.1/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'authorization: ' . ZEPTOMAIL_AUTH,
            'content-type: application/json',
            'accept: application/json',
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        cron_log("Mail cURL error sending to {$toEmail}: {$err}");
        return false;
    }
    if ($code < 200 || $code >= 300) {
        cron_log("Mail HTTP {$code} sending to {$toEmail}: {$resp}");
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Renewal reminder email body. Adapts copy based on days remaining/overdue.
// ---------------------------------------------------------------------------
function cron_render_reminder_body($firstName, $domain, $expiryDate, $daysLeft) {
    $expiryFmt = date('F j, Y', strtotime($expiryDate));

    if ($daysLeft > 0) {
        $eyebrow  = 'Renewal Reminder';
        $headline = "Your panel expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's');
        $sub      = "Renew before {$expiryFmt} to keep {$domain} running without interruption.";
        $accent   = 'orange';
    } elseif ($daysLeft === 0) {
        $eyebrow  = 'Expires Today';
        $headline = 'Your panel expires today';
        $sub      = "{$domain} reaches its expiry today. Renew now to avoid service interruption.";
        $accent   = 'orange';
    } else {
        $overdue       = abs($daysLeft);
        $daysToSuspend = max(0, SUSPEND_AFTER_DAYS - $overdue);
        $eyebrow       = 'Action Required';
        $headline      = 'Your panel has expired';
        $sub           = "{$domain} expired {$overdue} day" . ($overdue === 1 ? '' : 's') . ' ago. '
                       . "It will be automatically suspended in {$daysToSuspend} day"
                       . ($daysToSuspend === 1 ? '' : 's') . ' if not renewed.';
        $accent        = 'red';
    }

    $body  = vormox_email_hero($firstName, $eyebrow, $headline, $sub, $accent);
    $body .= vormox_email_details([
        ['Panel',       $domain],
        ['Expiry date', $expiryFmt],
    ], $accent);
    $body .= vormox_email_button('Renew Now', rtrim(SITE_URL, '/') . '/invoices.php');

    return vormox_email_template(
        "Renewal reminder — {$domain}",
        $body,
        "{$headline}. {$domain} expiry: {$expiryFmt}."
    );
}
