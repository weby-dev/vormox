<?php
// cron/lifecycle_trigger.php
// Runs every 5 minutes. For every active panel, fires:
//
//   POST http://<be_server_ip>:<BACKEND_LIFECYCLE_PORT><BACKEND_LIFECYCLE_PATH>
//   Header: X-Cron-Secret: <CRON_SECRET>
//
// Fans out in parallel via curl_multi so one slow backend can't block the
// whole batch.
//
// Recommended schedule:   */5 * * * *

require_once __DIR__ . '/_bootstrap.php';

cron_log("== lifecycle_trigger start ==");

try {
    $stmt = $pdo->query("
        SELECT p.id, p.domain, pd.be_server_ip
          FROM user_panels  p
          JOIN panel_details pd ON pd.panel_id = p.id
         WHERE p.status = 'active'
           AND pd.be_server_ip IS NOT NULL
           AND pd.be_server_ip <> ''
    ");
    $panels = $stmt->fetchAll();
} catch (PDOException $e) {
    cron_log("Panel query failed: " . $e->getMessage());
    exit(1);
}

if (!$panels) {
    cron_log("No active panels with backend IPs.");
    cron_log("== lifecycle_trigger done ==");
    exit(0);
}

cron_log("Triggering lifecycle on " . count($panels) . " panel(s)…");

$mh      = curl_multi_init();
$handles = [];

foreach ($panels as $p) {
    $url = sprintf(
        'http://%s:%d%s',
        $p['be_server_ip'],
        BACKEND_LIFECYCLE_PORT,
        BACKEND_LIFECYCLE_PATH
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => LIFECYCLE_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => LIFECYCLE_CONNECT_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'X-Cron-Secret: ' . CRON_SECRET,
            'Content-Length: 0',
        ],
    ]);

    curl_multi_add_handle($mh, $ch);
    $handles[(int)$p['id']] = ['ch' => $ch, 'panel' => $p];
}

// Pump until all parallel requests finish (or time out).
$running = null;
do {
    curl_multi_exec($mh, $running);
    if ($running > 0) curl_multi_select($mh, 1.0);
} while ($running > 0);

$ok = 0; $fail = 0;
foreach ($handles as $id => $h) {
    $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
    $err  = curl_error($h['ch']);
    $tag  = "[{$h['panel']['domain']} @ {$h['panel']['be_server_ip']}]";

    if ($err) {
        cron_log("  ✗ {$tag} cURL error: {$err}");
        $fail++;
    } elseif ($code < 200 || $code >= 300) {
        cron_log("  ✗ {$tag} HTTP {$code}");
        $fail++;
    } else {
        cron_log("  ✓ {$tag} HTTP {$code}");
        $ok++;
    }

    curl_multi_remove_handle($mh, $h['ch']);
    curl_close($h['ch']);
}
curl_multi_close($mh);

cron_log("Done: {$ok} ok, {$fail} failed.");
cron_log("== lifecycle_trigger done ==");
