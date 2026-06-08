<?php
// cron/suspend_expired.php
// Runs hourly. Two responsibilities:
//   1. Send a renewal-reminder email starting REMINDER_DAYS_BEFORE_EXPIRY days
//      before expiry, and keep sending (at most once per 23h) until the panel
//      is either renewed or suspended.
//   2. Suspend any active panel that has been expired for SUSPEND_AFTER_DAYS days
//      or more — UNLESS user_panels.bypass_suspension = 1.
//
// Recommended schedule:   0 * * * *

require_once __DIR__ . '/_bootstrap.php';

cron_log("== suspend_expired start ==");

// -----------------------------------------------------------------------
// 1) Renewal reminders
// -----------------------------------------------------------------------
// Reminder window: from REMINDER_DAYS_BEFORE_EXPIRY days BEFORE expiry,
// through SUSPEND_AFTER_DAYS days AFTER expiry. (Sending stops once the
// panel is no longer status='active'.)
try {
    $remStmt = $pdo->prepare("
        SELECT p.id, p.domain, p.expiry_date,
               u.email, u.first_name
          FROM user_panels p
          JOIN users       u ON u.id = p.user_id
         WHERE p.status = 'active'
           AND p.expiry_date IS NOT NULL
           AND p.expiry_date BETWEEN DATE_SUB(NOW(), INTERVAL :suspendDays DAY)
                                 AND DATE_ADD(NOW(), INTERVAL :remindDays  DAY)
           AND (
                 p.last_renewal_reminder_sent_at IS NULL
              OR p.last_renewal_reminder_sent_at < DATE_SUB(NOW(), INTERVAL 23 HOUR)
           )
    ");
    $remStmt->bindValue(':suspendDays', SUSPEND_AFTER_DAYS, PDO::PARAM_INT);
    $remStmt->bindValue(':remindDays',  REMINDER_DAYS_BEFORE_EXPIRY, PDO::PARAM_INT);
    $remStmt->execute();
    $candidates = $remStmt->fetchAll();
} catch (PDOException $e) {
    cron_log("Reminder query failed: " . $e->getMessage());
    $candidates = [];
}

cron_log("Reminder candidates: " . count($candidates));

$sent = 0; $failed = 0;
foreach ($candidates as $row) {
    $daysLeft = (int) floor((strtotime($row['expiry_date']) - time()) / 86400);

    if ($daysLeft > 0) {
        $subject = "Your Vormox panel '{$row['domain']}' expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's');
    } elseif ($daysLeft === 0) {
        $subject = "Your Vormox panel '{$row['domain']}' expires today";
    } else {
        $subject = "Your Vormox panel '{$row['domain']}' has expired — renew to avoid suspension";
    }

    $body = cron_render_reminder_body(
        $row['first_name'],
        $row['domain'],
        $row['expiry_date'],
        $daysLeft
    );

    if (cron_send_email($row['email'], $row['first_name'], $subject, $body)) {
        $pdo->prepare("UPDATE user_panels SET last_renewal_reminder_sent_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);
        cron_log("  → reminder sent: panel {$row['id']} ({$row['domain']}) to {$row['email']}");
        $sent++;
    } else {
        cron_log("  ✗ reminder FAILED: panel {$row['id']} ({$row['domain']}) to {$row['email']}");
        $failed++;
    }
}
cron_log("Reminders: {$sent} sent, {$failed} failed");

// -----------------------------------------------------------------------
// 2) Suspend long-expired panels (honoring bypass_suspension)
// -----------------------------------------------------------------------
try {
    // Capture rows we're about to suspend so we can log domains + notify users.
    $aboutStmt = $pdo->prepare("
        SELECT p.id, p.domain, u.email, u.first_name
          FROM user_panels p
          JOIN users       u ON u.id = p.user_id
         WHERE p.status            = 'active'
           AND p.bypass_suspension = 0
           AND p.expiry_date IS NOT NULL
           AND p.expiry_date < DATE_SUB(NOW(), INTERVAL :days DAY)
    ");
    $aboutStmt->bindValue(':days', SUSPEND_AFTER_DAYS, PDO::PARAM_INT);
    $aboutStmt->execute();
    $about = $aboutStmt->fetchAll();

    if ($about) {
        $ids   = array_column($about, 'id');
        $place = implode(',', array_fill(0, count($ids), '?'));

        $upd = $pdo->prepare("UPDATE user_panels SET status = 'suspended' WHERE id IN ($place)");
        $upd->execute($ids);

        cron_log("Suspended " . count($ids) . " panel(s): " . implode(', ', array_column($about, 'domain')));

        // Best-effort suspension notice — uses the shared brand template.
        foreach ($about as $r) {
            $body  = vormox_email_hero(
                $r['first_name'],
                'Service Suspended',
                "Your panel {$r['domain']} has been suspended",
                "The renewal invoice was not paid within " . SUSPEND_AFTER_DAYS . " days of expiry. Settle the outstanding invoice to restore service.",
                'red'
            );
            $body .= vormox_email_details([
                ['Panel',  $r['domain']],
                ['Status', 'Suspended'],
            ], 'red');
            $body .= vormox_email_button('View Invoices', rtrim(SITE_URL, '/') . '/invoices.php');

            $html = vormox_email_template(
                "Service suspended — {$r['domain']}",
                $body,
                "{$r['domain']} was suspended. Pay the outstanding invoice to restore service."
            );

            cron_send_email($r['email'], $r['first_name'], "Service suspended — {$r['domain']}", $html);
        }
    } else {
        cron_log("No panels eligible for suspension this run.");
    }
} catch (PDOException $e) {
    cron_log("Suspension step failed: " . $e->getMessage());
}

cron_log("== suspend_expired done ==");
