<?php
// includes/notifications.php
// Shared web-side notification helpers. All HTML rendered through
// includes/email_layout.php so every email matches the app's UI.

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/email_layout.php';

// Make sure .env is loaded for code paths that pulled in notifications.php
// directly (without going through config.php first — rare but possible).
vormox_load_env(dirname(__DIR__) . '/.env');

if (!defined('VORMOX_NOTIFY_FROM')) {
    define('VORMOX_NOTIFY_FROM',      vormox_env('MAIL_FROM_ADDRESS', 'noreply@getwebup.com'));
    define('VORMOX_NOTIFY_FROM_NAME', vormox_env('MAIL_FROM_NAME',    'Vormox'));
    define('VORMOX_NOTIFY_AUTH',      vormox_env('ZEPTOMAIL_AUTH',    ''));

    // Public URL of the app; used for absolute links inside email bodies.
    // Honors SITE_URL env var, then falls back to the current request,
    // then to a hard fallback.
    if (!defined('VORMOX_SITE_URL')) {
        $envSite = vormox_env('SITE_URL', '');
        if ($envSite !== '') {
            define('VORMOX_SITE_URL', $envSite);
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            define('VORMOX_SITE_URL', $scheme . '://' . $_SERVER['HTTP_HOST']);
        } else {
            define('VORMOX_SITE_URL', 'https://app.vormox.com');
        }
    }
}

/**
 * Send a transactional HTML email via ZeptoMail. Returns true on 2xx.
 * Failures are logged via error_log so they don't break the calling flow.
 */
function vormox_send_mail($toEmail, $toName, $subject, $htmlBody) {
    if (empty($toEmail)) return false;

    $payload = json_encode([
        'from'     => ['address' => VORMOX_NOTIFY_FROM, 'name' => VORMOX_NOTIFY_FROM_NAME],
        'to'       => [['email_address' => ['address' => $toEmail, 'name' => $toName ?: '']]],
        'subject'  => $subject,
        'htmlbody' => $htmlBody,
    ]);

    $ch = curl_init('https://api.zeptomail.in/v1.1/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'authorization: ' . VORMOX_NOTIFY_AUTH,
            'content-type: application/json',
            'accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("vormox_send_mail cURL error to {$toEmail}: {$err}");
        return false;
    }
    if ($code < 200 || $code >= 300) {
        error_log("vormox_send_mail HTTP {$code} to {$toEmail}: {$resp}");
        return false;
    }
    return true;
}

// =============================================================================
// Templates — all wrap themselves in vormox_email_template() so every message
// uses the same brand chrome (logo, dark card, footer).
// =============================================================================

/**
 * "Service restored — payment received" after an expired panel was paid.
 */
function notify_panel_renewed_after_expiry($email, $firstName, $domain, $newExpiryDate) {
    if (empty($email) || empty($domain) || empty($newExpiryDate)) return false;

    $expiryFmt = date('F j, Y', strtotime($newExpiryDate));

    $body  = vormox_email_hero(
        $firstName,
        'Service Restored',
        "Your panel {$domain} is back online",
        "We've received your payment and extended the plan. If the panel was suspended it should resume within a few minutes.",
        'green'
    );
    $body .= vormox_email_details([
        ['Panel',      $domain],
        ['New expiry', $expiryFmt],
    ], 'green');
    $body .= vormox_email_button('Open Dashboard', rtrim(VORMOX_SITE_URL, '/') . '/dashboard.php');

    $html = vormox_email_template(
        "Service restored — {$domain}",
        $body,
        "Payment received. {$domain} is active again until {$expiryFmt}."
    );

    return vormox_send_mail($email, $firstName, "Service restored — {$domain}", $html);
}

/**
 * "New invoice issued" — any path that creates an invoice.
 */
function notify_invoice_created($email, $firstName, $invoiceNumber, $amount, $dueDate, $description = 'Invoice') {
    if (empty($email) || empty($invoiceNumber)) return false;

    $amountFmt = '$' . number_format((float) $amount, 2);
    $dueFmt    = !empty($dueDate) ? date('F j, Y', strtotime($dueDate)) : 'On receipt';
    $payLink   = rtrim(VORMOX_SITE_URL, '/') . '/view-invoice.php?id=' . urlencode($invoiceNumber);

    $body  = vormox_email_hero(
        $firstName,
        'New Invoice Issued',
        "Invoice {$invoiceNumber}",
        $description,
        'blue'
    );
    $body .= vormox_email_details([
        ['Invoice', $invoiceNumber],
        ['Amount',  $amountFmt],
        ['Due',     $dueFmt],
    ], 'blue');
    $body .= vormox_email_button('Pay Invoice', $payLink);

    $html = vormox_email_template(
        "Invoice {$invoiceNumber} — {$amountFmt}",
        $body,
        "New invoice {$invoiceNumber} for {$amountFmt}, due {$dueFmt}."
    );

    return vormox_send_mail($email, $firstName, "Invoice {$invoiceNumber} — {$amountFmt} due {$dueFmt}", $html);
}

/**
 * "Payment received" receipt — any path that flips an invoice Paid.
 */
function notify_invoice_paid($email, $firstName, $invoiceNumber, $amount, $description = '') {
    if (empty($email) || empty($invoiceNumber)) return false;

    $amountFmt = '$' . number_format((float) $amount, 2);
    $dateFmt   = date('M j, Y · g:i A');
    $viewLink  = rtrim(VORMOX_SITE_URL, '/') . '/view-invoice.php?id=' . urlencode($invoiceNumber);

    $body  = vormox_email_hero(
        $firstName,
        'Payment Received',
        "Thanks — {$amountFmt} confirmed",
        $description ?: "We've recorded your payment for invoice {$invoiceNumber}.",
        'green'
    );
    $body .= vormox_email_details([
        ['Invoice', $invoiceNumber],
        ['Amount',  $amountFmt],
        ['Paid on', $dateFmt],
    ], 'green');
    $body .= vormox_email_button('View Receipt', $viewLink, 'secondary');

    $html = vormox_email_template(
        "Payment received — {$invoiceNumber}",
        $body,
        "Payment of {$amountFmt} received for invoice {$invoiceNumber}."
    );

    return vormox_send_mail($email, $firstName, "Payment received — Invoice {$invoiceNumber} ({$amountFmt})", $html);
}

/**
 * Password reset OTP.
 */
function notify_password_reset_otp($email, $firstName, $otp, $minutesValid = 15) {
    if (empty($email) || empty($otp)) return false;

    $body  = vormox_email_hero(
        $firstName,
        'Password Reset',
        'Use this code to reset your password',
        "Enter this 6-digit code on the reset screen. If you didn't request a reset, you can ignore this email.",
        'purple'
    );
    $body .= vormox_email_code($otp);
    $body .= vormox_email_footnote("Code expires in {$minutesValid} minutes.");

    $html = vormox_email_template(
        'Your Vormox password reset code',
        $body,
        "Reset code: {$otp} (valid {$minutesValid} min)"
    );

    return vormox_send_mail($email, $firstName, 'Your Vormox password reset code', $html);
}

/**
 * Email verification OTP (used by auth_guard.php for unverified users).
 */
function notify_email_verification_otp($email, $firstName, $otp, $minutesValid = 15) {
    if (empty($email) || empty($otp)) return false;

    $body  = vormox_email_hero(
        $firstName,
        'Verify Email',
        'Activate your Vormox account',
        "Enter this 6-digit code in the verification screen to unlock your account.",
        'purple'
    );
    $body .= vormox_email_code($otp);
    $body .= vormox_email_footnote("Code expires in {$minutesValid} minutes. If you didn't sign up, you can ignore this email.");

    $html = vormox_email_template(
        'Your Vormox verification code',
        $body,
        "Verification code: {$otp}"
    );

    return vormox_send_mail($email, $firstName, 'Your Vormox verification code', $html);
}

/**
 * One-time code gating a backup download. Sent when a user requests to
 * download one of their database backups from the dashboard.
 */
function notify_backup_download_otp($email, $firstName, $otp, $minutesValid = 10) {
    if (empty($email) || empty($otp)) return false;

    $body  = vormox_email_hero(
        $firstName,
        'Backup Download',
        'Confirm your backup download',
        "Enter this 6-digit code on the backups screen to download your database backup.",
        'purple'
    );
    $body .= vormox_email_code($otp);
    $body .= vormox_email_footnote("Code expires in {$minutesValid} minutes. If you didn't request this download, ignore this email and consider changing your password.");

    $html = vormox_email_template(
        'Your Vormox backup download code',
        $body,
        "Backup download code: {$otp}"
    );

    return vormox_send_mail($email, $firstName, 'Your Vormox backup download code', $html);
}
