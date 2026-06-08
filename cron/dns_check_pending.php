<?php
// cron/dns_check_pending.php
//
// Purpose: bridge the gap between "admin has assigned an RP server IP" and
// "actual provisioning starts". A panel sits in `pending` (or `payment_pending`)
// until BOTH:
//   1. an admin has filled in panel_details.rp_server_ip
//   2. the customer has pointed their domain's A / AAAA record at that IP
//
// This cron runs every few minutes, resolves each candidate panel's domain,
// and if the answer includes the configured rp_server_ip → flips
// user_panels.status to 'creating' so the downstream provisioning pipeline
// (lifecycle_trigger.php or whatever else watches `creating`) can take over.
//
// Recommended schedule:   */5 * * * *
//
// Tunables (override via .env if you want):
//   - DNS_CHECK_STATUSES   — comma-separated panel statuses considered candidates.
//                             Default: pending,payment_pending
//   - DNS_QUERY_TIMEOUT    — seconds. Soft cap on a single domain's resolution.
//                             Default: 5

require_once __DIR__ . '/_bootstrap.php';

$candidate_statuses = array_filter(array_map('trim',
    explode(',', vormox_env('DNS_CHECK_STATUSES', 'pending,payment_pending'))
));
if (empty($candidate_statuses)) {
    $candidate_statuses = ['pending', 'payment_pending'];
}

cron_log("== dns_check_pending start ==");
cron_log("Candidate statuses: " . implode(', ', $candidate_statuses));

// ---------------------------------------------------------------------------
// Pull every panel that's a candidate AND has an RP IP we can verify against.
// ---------------------------------------------------------------------------
$place = implode(',', array_fill(0, count($candidate_statuses), '?'));
try {
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.domain,
               p.status,
               p.user_id,
               pd.rp_server_ip,
               u.email      AS user_email,
               u.first_name AS user_first_name
          FROM user_panels   p
          JOIN panel_details pd ON pd.panel_id = p.id
          JOIN users         u  ON u.id        = p.user_id
         WHERE p.status IN ($place)
           AND pd.rp_server_ip IS NOT NULL
           AND pd.rp_server_ip <> ''
           AND p.domain        IS NOT NULL
           AND p.domain        <> ''
    ");
    $stmt->execute($candidate_statuses);
    $candidates = $stmt->fetchAll();
} catch (PDOException $e) {
    cron_log("Candidate query failed: " . $e->getMessage());
    exit(1);
}

if (!$candidates) {
    cron_log("No candidates to check.");
    cron_log("== dns_check_pending done ==");
    exit(0);
}

cron_log("Checking " . count($candidates) . " panel(s)…");

$promoted = 0;
$pending  = 0;
foreach ($candidates as $panel) {
    $domain   = strtolower(trim($panel['domain']));
    $expected = trim($panel['rp_server_ip']);

    // Quick sanity check on the stored expectation
    if (filter_var($expected, FILTER_VALIDATE_IP) === false) {
        cron_log("  ! panel {$panel['id']} ({$domain}): rp_server_ip '{$expected}' is not a valid IP, skipping");
        continue;
    }

    $resolved = vormox_dns_lookup($domain);

    if (empty($resolved)) {
        cron_log("  · {$domain} — no A/AAAA records yet (expected {$expected})");
        $pending++;
        continue;
    }

    if (in_array($expected, $resolved, true)) {
        // DNS is pointed correctly. Promote to creating — but ONLY if it's
        // still in a candidate status (could have moved during our run).
        try {
            $upd = $pdo->prepare("
                UPDATE user_panels
                   SET status = 'creating'
                 WHERE id = ?
                   AND status IN ($place)
            ");
            $upd->execute(array_merge([$panel['id']], $candidate_statuses));

            if ($upd->rowCount() > 0) {
                cron_log("  ✓ {$domain} → {$expected} matched. status: {$panel['status']} → creating");
                $promoted++;
            } else {
                cron_log("  · {$domain} already moved out of candidate state by something else.");
            }
        } catch (PDOException $e) {
            cron_log("  ✗ {$domain}: DB update failed: " . $e->getMessage());
        }
    } else {
        cron_log("  · {$domain} expected {$expected}, got [" . implode(', ', $resolved) . "]");
        $pending++;
    }
}

cron_log("Done: {$promoted} promoted, {$pending} still pending DNS.");
cron_log("== dns_check_pending done ==");

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Resolve A + AAAA records for $domain and return all IPs as strings.
 * Returns [] on any lookup failure (so a single bad domain doesn't blow up
 * the whole cron run).
 */
function vormox_dns_lookup($domain) {
    $ips = [];

    // IPv4
    $a = @dns_get_record($domain, DNS_A);
    if (is_array($a)) {
        foreach ($a as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
        }
    }

    // IPv6
    $aaaa = @dns_get_record($domain, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }

    return array_values(array_unique($ips));
}
