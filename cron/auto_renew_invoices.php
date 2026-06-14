<?php
// cron/auto_renew_invoices.php
//
// Generates the next-cycle renewal invoice for every active panel whose
// expiry is approaching. Default lead time: 5 days (override via
// AUTO_RENEW_LEAD_DAYS in .env).
//
// Dedup is by "does this panel already have an Unpaid invoice?" — if yes,
// skip. Once the user pays, expiry advances out of the window and we won't
// re-fire until the new expiry approaches.
//
// Email notification goes out via the standard notify_invoice_created()
// template so the customer sees the same brand chrome they would for a
// manually-issued invoice.
//
// Recommended schedule:   0 3 * * *      (daily at 03:00, off-peak)

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/pricing.php';

$lead_days = (int) vormox_env('AUTO_RENEW_LEAD_DAYS', 5);
if ($lead_days < 1) $lead_days = 5;

cron_log("== auto_renew_invoices start ==");
cron_log("Lead time: {$lead_days} day(s) before expiry");

// ---------------------------------------------------------------------------
// Candidate query.
//
// active panels with expiry in (now, now+lead_days]
//    AND no Unpaid invoice already attached to the panel
//    AND no invoice (any status) whose period covers the next cycle —
//        this is the safety against a previously-paid renewal triggering
//        a duplicate billing if the cron runs while expiry hasn't yet
//        rolled forward.
//
// LEFT JOIN + IS NULL pattern is faster than NOT EXISTS on small tables
// and the explain plan is easier to reason about.
// ---------------------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.user_id, p.domain, p.total_price, p.billing_cycle, p.expiry_date,
               u.email, u.first_name
          FROM user_panels   p
          JOIN users         u  ON u.id = p.user_id
          LEFT JOIN invoices ip ON ip.panel_id = p.id AND ip.status = 'Unpaid'
          LEFT JOIN invoices ic ON ic.panel_id = p.id
                               AND ic.status   IN ('Unpaid', 'Paid')
                               AND ic.period_end IS NOT NULL
                               AND ic.period_end >= p.expiry_date
         WHERE p.status = 'active'
           AND p.expiry_date IS NOT NULL
           AND p.expiry_date >  NOW()
           AND p.expiry_date <= DATE_ADD(NOW(), INTERVAL :days DAY)
           AND ip.id IS NULL
           AND ic.id IS NULL
    ");
    $stmt->bindValue(':days', $lead_days, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll();
} catch (PDOException $e) {
    cron_log("Candidate query failed: " . $e->getMessage());
    exit(1);
}

cron_log("Found " . count($candidates) . " panel(s) needing a renewal invoice.");

$created = 0;
$failed  = 0;
foreach ($candidates as $p) {
    $domain = $p['domain'];
    $cycle  = $p['billing_cycle'] ?? 'monthly';
    $months = vormox_cycle_months($cycle);
    $amount = (float) ($p['total_price'] ?: 0);

    // Period covers the NEXT cycle, starting from current expiry.
    $base         = strtotime($p['expiry_date']);
    $period_start = date('Y-m-d', $base);
    $period_end   = date('Y-m-d', strtotime("+{$months} months", $base));
    $due_date     = date('Y-m-d', $base); // due on (or before) expiry day

    $invoice_num = 'INV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

    try {
        $pdo->beginTransaction();

        // Re-check inside the transaction in case a concurrent admin action
        // created an invoice for this panel between the SELECT above and now.
        $reCheck = $pdo->prepare("
            SELECT COUNT(*) FROM invoices
             WHERE panel_id = ? AND status = 'Unpaid'
        ");
        $reCheck->execute([$p['id']]);
        if ((int) $reCheck->fetchColumn() > 0) {
            $pdo->rollBack();
            cron_log("  · {$domain} — Unpaid invoice appeared during run, skipping.");
            continue;
        }

        $pdo->prepare("
            INSERT INTO invoices (user_id, panel_id, invoice_number, amount, type, status,
                                  due_date, period_start, period_end, created_at)
            VALUES (?, ?, ?, ?, 'renew', 'Unpaid', ?, ?, ?, NOW())
        ")->execute([$p['user_id'], $p['id'], $invoice_num, $amount, $due_date, $period_start, $period_end]);

        $pdo->commit();

        // Notification after commit so a failed mail doesn't roll back the DB row.
        notify_invoice_created(
            $p['email'],
            $p['first_name'],
            $invoice_num,
            $amount,
            $due_date,
            "Auto-renewal invoice for <strong>" . htmlspecialchars($domain, ENT_QUOTES) . "</strong> covering "
            . date('M j, Y', strtotime($period_start)) . " — " . date('M j, Y', strtotime($period_end))
            . ". Pay before " . date('M j, Y', strtotime($due_date)) . " to keep the service active."
        );

        cron_log(sprintf("  ✓ %s → %s ($%s) period %s → %s",
            $domain, $invoice_num, number_format($amount, 2), $period_start, $period_end
        ));
        $created++;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
        }
        cron_log("  ✗ {$domain}: " . $e->getMessage());
        $failed++;
    }
}

cron_log("Done: {$created} invoice(s) created, {$failed} failed.");
cron_log("== auto_renew_invoices done ==");
