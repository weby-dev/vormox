<?php
// admin/setup_infrastructure.php
//
// One-click registration of all three infrastructure servers (BACKEND,
// FRONTEND, REVERSE_PROXY) with the panel's backend via its internal API:
//
//   POST http://{be_server_ip}:8080/internal/api/liveservers
//   X-Internal-Secret: <CRON_SECRET>
//
// All credentials come from panel_details. Each server type is sent in its
// own HTTP call; per-type results are returned so the admin can see which
// succeeded / failed / were skipped.
//
// Required CSRF + admin auth, same shape as the other admin/setup_* endpoints.

session_start();
require_once '../config.php';

header('Content-Type: application/json');

// --- Admin auth boilerplate ---
$user_ip = $_SERVER['REMOTE_ADDR'];
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    if ($countStmt->fetchColumn() > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'IP not whitelisted.']); exit;
        }
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Security check failed.']); exit;
}

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not signed in.']); exit;
}

csrf_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only.']); exit;
}

$panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
if (!$panel_id) {
    echo json_encode(['success' => false, 'message' => 'Missing panel_id.']); exit;
}

// --- Load the panel + every server-side detail we'll need ---
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.domain,
               pd.be_server_ip, pd.be_ssh_port, pd.be_ssh_user, pd.be_ssh_pass,
               pd.fe_server_ip, pd.fe_ssh_port, pd.fe_ssh_user, pd.fe_ssh_pass,
               pd.rp_server_ip, pd.rp_ssh_port, pd.rp_ssh_user, pd.rp_ssh_pass
          FROM user_panels   p
          JOIN panel_details pd ON pd.panel_id = p.id
         WHERE p.id = ? LIMIT 1
    ");
    $stmt->execute([$panel_id]);
    $panel = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error loading panel.']); exit;
}

if (!$panel) {
    echo json_encode(['success' => false, 'message' => 'Panel not found.']); exit;
}

// --- We need the BACKEND IP to call its API; everything else is per-type. ---
if (empty($panel['be_server_ip'])) {
    echo json_encode(['success' => false, 'message' => 'Backend IP is missing — cannot reach /internal/api/liveservers.']);
    exit;
}

$secret = vormox_env('CRON_SECRET', '');
if ($secret === '') {
    echo json_encode(['success' => false, 'message' => 'CRON_SECRET is not set in .env on the web server.']);
    exit;
}

$api_url = sprintf('http://%s:8080/internal/api/liveservers', $panel['be_server_ip']);

// --- Map panel_details columns → API payload per server type ---
$specs = [
    'BACKEND' => [
        'serverName' => "Backend - {$panel['domain']}",
        'serverIp'   => $panel['be_server_ip'],
        'username'   => $panel['be_ssh_user'],
        'password'   => $panel['be_ssh_pass'],
        'sshPort'    => (int) ($panel['be_ssh_port'] ?: 22),
    ],
    'FRONTEND' => [
        'serverName' => "Frontend - {$panel['domain']}",
        'serverIp'   => $panel['fe_server_ip'],
        'username'   => $panel['fe_ssh_user'],
        'password'   => $panel['fe_ssh_pass'],
        'sshPort'    => (int) ($panel['fe_ssh_port'] ?: 22),
    ],
    'REVERSE_PROXY' => [
        'serverName' => "Reverse Proxy - {$panel['domain']}",
        'serverIp'   => $panel['rp_server_ip'],
        'username'   => $panel['rp_ssh_user'],
        'password'   => $panel['rp_ssh_pass'],
        'sshPort'    => (int) ($panel['rp_ssh_port'] ?: 22),
    ],
];

// --- Fire all three calls and collect per-type results ---
$results = [];
$ok = 0; $skipped = 0; $failed = 0;

foreach ($specs as $type => $cfg) {
    if (empty($cfg['serverIp']) || empty($cfg['username']) || empty($cfg['password'])) {
        $results[$type] = [
            'status'  => 'skipped',
            'reason'  => 'Missing IP / SSH user / SSH password in panel_details.',
        ];
        $skipped++;
        continue;
    }

    $payload = json_encode([
        'serverType' => $type,
        'serverName' => $cfg['serverName'],
        'serverIp'   => $cfg['serverIp'],
        'username'   => $cfg['username'],
        'password'   => $cfg['password'],
        'sshPort'    => $cfg['sshPort'],
        'isActive'   => true,
    ]);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $results[$type] = ['status' => 'error', 'reason' => "cURL: {$err}"];
        $failed++;
    } elseif ($code < 200 || $code >= 300) {
        $msg = "HTTP {$code}";
        // Try to surface the backend's JSON error message if there is one
        $decoded = @json_decode($body, true);
        if (is_array($decoded) && !empty($decoded['error']))   $msg .= " — " . $decoded['error'];
        if (is_array($decoded) && !empty($decoded['message'])) $msg .= " — " . $decoded['message'];
        $results[$type] = ['status' => 'error', 'reason' => $msg];
        $failed++;
    } else {
        $results[$type] = ['status' => 'ok', 'http' => $code];
        $ok++;
    }
}

$parts = [];
if ($ok      > 0) $parts[] = "{$ok} added";
if ($skipped > 0) $parts[] = "{$skipped} skipped";
if ($failed  > 0) $parts[] = "{$failed} failed";
$summary = $parts ? implode(', ', $parts) : 'nothing to do';

echo json_encode([
    'success'  => ($failed === 0),
    'message'  => "Infrastructure registration: {$summary}.",
    'api'      => $api_url,
    'results'  => $results,
]);
