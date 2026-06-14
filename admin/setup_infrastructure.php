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

// The internal-api endpoint refuses any request whose source IP isn't
// 127.0.0.1 — so we SSH into the backend and run `curl` from there. That
// requires BE SSH credentials in addition to the BE server IP.
if (empty($panel['be_server_ip']) || empty($panel['be_ssh_user']) || empty($panel['be_ssh_pass'])) {
    echo json_encode(['success' => false, 'message' => 'Backend SSH credentials (IP + user + password) are required to register infrastructure — the API only accepts localhost calls so we have to invoke it from the backend host itself.']);
    exit;
}

$secret = vormox_env('CRON_SECRET', '');
if ($secret === '') {
    echo json_encode(['success' => false, 'message' => 'CRON_SECRET is not set in .env on the web server.']);
    exit;
}

$be_ssh_port = (int) ($panel['be_ssh_port'] ?: 22);
$api_url     = 'http://127.0.0.1:8080/internal/api/liveservers'; // called from the backend's own shell

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

// --- Open ONE SSH session to the backend; reuse it for all three calls ---
if (!function_exists('ssh2_connect')) {
    echo json_encode(['success' => false, 'message' => 'PHP ssh2 extension missing on web server.']);
    exit;
}
$errno = 0; $errstr = '';
$probe = @stream_socket_client("tcp://{$panel['be_server_ip']}:{$be_ssh_port}", $errno, $errstr, 5);
if (!$probe) {
    echo json_encode(['success' => false, 'message' => "Backend host unreachable: {$panel['be_server_ip']}:{$be_ssh_port} ({$errstr})"]);
    exit;
}
fclose($probe);

$conn = @ssh2_connect($panel['be_server_ip'], $be_ssh_port);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'SSH handshake to backend failed.']);
    exit;
}
if (!@ssh2_auth_password($conn, $panel['be_ssh_user'], $panel['be_ssh_pass'])) {
    echo json_encode(['success' => false, 'message' => 'SSH authentication to backend failed.']);
    exit;
}

// Run each curl from the backend's own shell so the source IP is 127.0.0.1.
// The JSON payload is shipped via base64 to dodge all the shell-quoting
// headaches that arise from passwords containing $, !, ", etc.
$header_secret = escapeshellarg("X-Internal-Secret: {$secret}");
$header_json   = escapeshellarg("Content-Type: application/json");
$url_arg       = escapeshellarg($api_url);

$results = [];
$ok = 0; $skipped = 0; $failed = 0;

foreach ($specs as $type => $cfg) {
    if (empty($cfg['serverIp']) || empty($cfg['username']) || empty($cfg['password'])) {
        $results[$type] = [
            'status' => 'skipped',
            'reason' => 'Missing IP / SSH user / SSH password in panel_details.',
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
    ], JSON_UNESCAPED_SLASHES);

    $payload_b64 = base64_encode($payload);

    // base64 → curl --data-binary @- → trailing marker captures the HTTP code
    // so PHP can split body and status cleanly.
    $cmd = "printf %s '{$payload_b64}' | base64 -d | "
         . "curl -s -m 15 --connect-timeout 5 "
         . "-X POST {$url_arg} "
         . "-H {$header_json} "
         . "-H {$header_secret} "
         . "--data-binary @- "
         . "-w '\\n---HTTP=%{http_code}'";

    $stream = @ssh2_exec($conn, $cmd);
    if (!$stream) {
        $results[$type] = ['status' => 'error', 'reason' => 'SSH exec failed (could not start curl on backend).'];
        $failed++;
        continue;
    }
    stream_set_blocking($stream, true);
    stream_set_timeout($stream, 25);
    $output = (string) @stream_get_contents(@ssh2_fetch_stream($stream, SSH2_STREAM_STDIO));
    @fclose($stream);

    // Parse "BODY\n---HTTP=NNN" — fall back to whole string + unknown code
    $body = $output;
    $code = 0;
    if (preg_match('/^(.*?)\n?---HTTP=(\d+)\s*$/s', $output, $m)) {
        $body = $m[1];
        $code = (int) $m[2];
    }

    if ($code === 0) {
        $results[$type] = ['status' => 'error', 'reason' => 'No response from backend curl. Output: ' . trim($body)];
        $failed++;
    } elseif ($code < 200 || $code >= 300) {
        $msg = "HTTP {$code}";
        $decoded = @json_decode(trim($body), true);
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
