<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// ==========================================
// 1. VERIFY USER LOGIN
// ==========================================
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$panel_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Panel ID']);
    exit;
}

// ==========================================
// 2. SECURITY CHECK & FETCH CREDENTIALS
// ==========================================
try {
    // SECURITY STRICT CHECK: user_id = ? ensures they own this panel
    $stmt = $pdo->prepare("
        SELECT p.id, p.domain, p.user_id, pd.* FROM user_panels p 
        JOIN panel_details pd ON p.id = pd.panel_id 
        WHERE p.id = ? AND p.user_id = ? LIMIT 1
    ");
    $stmt->execute([$panel_id, $user_id]);
    $panel = $stmt->fetch();
    
    if (!$panel) {
        // Log this attempt if you have a security logging system
        echo json_encode(['status' => 'error', 'message' => 'Access Denied: You do not own this service.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database validation error.']);
    exit;
}

// ==========================================
// 3. SSH EXECUTION ENGINE
// ==========================================
function execute_ssh_command($ip, $port, $user, $pass, $command, $timeout = 5) {
    if (!function_exists('ssh2_connect')) {
        return ['success' => false, 'output' => "Error: PHP ssh2 extension missing on web server."];
    }
    $port = (int) ($port ?: 22);

    // Pre-check at the TCP layer so an unreachable IP fails in $timeout
    // seconds instead of blocking the PHP worker for 60+.
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, $timeout);
    if (!$sock) {
        return ['success' => false, 'output' => "SSH host unreachable: {$ip}:{$port} ({$errstr})"];
    }
    fclose($sock);

    $connection = @ssh2_connect($ip, $port);
    if (!$connection) return ['success' => false, 'output' => "SSH handshake failed to {$ip}:{$port}"];

    if (!@ssh2_auth_password($connection, $user, $pass)) {
        return ['success' => false, 'output' => "SSH authentication failed for '{$user}'."];
    }

    $stream = @ssh2_exec($connection, $command);
    if (!$stream) return ['success' => false, 'output' => "SSH command execution failed."];

    stream_set_blocking($stream, true);
    stream_set_timeout($stream, $timeout * 2);

    $stdio = @ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
    if (!$stdio) return ['success' => false, 'output' => "SSH stream open failed."];
    stream_set_blocking($stdio, true);
    stream_set_timeout($stdio, $timeout * 2);

    $output = @stream_get_contents($stdio);
    @fclose($stdio);
    @fclose($stream);

    return ['success' => true, 'output' => $output !== false ? $output : ''];
}

// ==========================================
// 4. ACTION DISPATCHER (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    csrf_require();
    $action = filter_input(INPUT_POST, 'ajax_action', FILTER_SANITIZE_SPECIAL_CHARS);
    $type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_SPECIAL_CHARS); // 'be' or 'fe'

    // STRICT USER LIMITATIONS: Clients can only start/stop/restart. 
    // They CANNOT create, update, or terminate services.
    $allowed_actions = ['start', 'stop', 'restart'];
    if (!in_array($action, $allowed_actions)) {
        echo json_encode(['status' => 'error', 'message' => 'Action not permitted for client accounts.']);
        exit;
    }

    $ip = ($type === 'be') ? $panel['be_server_ip'] : $panel['fe_server_ip'];
    $port = ($type === 'be') ? $panel['be_ssh_port'] : $panel['fe_ssh_port'];
    $user = ($type === 'be') ? $panel['be_ssh_user'] : $panel['fe_ssh_user'];
    $pass = ($type === 'be') ? $panel['be_ssh_pass'] : $panel['fe_ssh_pass'];
    $service = ($type === 'be') ? $panel['be_service'] : $panel['fe_service'];

    if (empty($ip) || empty($user) || empty($service)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing server credentials.']);
        exit;
    }

    // Execute systemctl command via SSH
    $cmd = "sudo systemctl {$action} " . escapeshellarg($service);
    $ssh_result = execute_ssh_command($ip, $port, $user, $pass, $cmd);

    if (!$ssh_result['success']) {
        echo json_encode(['status' => 'error', 'message' => $ssh_result['output']]);
        exit;
    }

    // Update DB with new state
    $new_state = ($action === 'stop') ? 'offline' : 'online';
    try {
        $status_col = ($type === 'be') ? 'be_status' : 'fe_status';
        $pdo->prepare("UPDATE panel_details SET {$status_col} = ? WHERE panel_id = ?")
            ->execute([$new_state, $panel_id]);

        echo json_encode([
            'status' => 'success', 
            'message' => "Service successfully " . strtoupper($action) . "ED.",
            'new_state' => $new_state
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Action executed, but DB update failed.']);
    }
    exit;
}

// ==========================================
// 5. REAL-TIME LOG POLLING (GET)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    $type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $ip = $port = $user = $pass = $cmd = null;

    // ---------------------------------------------------------
    // REVERSE PROXY NGINX LOGS
    // ---------------------------------------------------------
    if ($type === 'rp_access' || $type === 'rp_error') {
        $ip = $panel['rp_server_ip'];
        $port = $panel['rp_ssh_port'];
        $user = $panel['rp_ssh_user'];
        $pass = $panel['rp_ssh_pass'];
        
        // Dynamically build the log path based on the panel's domain
        $access_path = escapeshellarg("/var/log/nginx/{$panel['domain']}.access.log");
        $error_path  = escapeshellarg("/var/log/nginx/{$panel['domain']}.error.log");

        // Use 'tail -n 100' instead of journalctl for raw log files
        if ($type === 'rp_access') {
            $cmd = "sudo tail -n 100 $access_path";
        } else {
            $cmd = "sudo tail -n 100 $error_path";
        }
        
        if (empty($ip) || empty($user)) {
            echo json_encode(['log' => "Configuration error: Reverse proxy credentials missing."]); exit;
        }

    // ---------------------------------------------------------
    // BACKEND & FRONTEND SYSTEMD LOGS
    // ---------------------------------------------------------
    } else {
        $ip = ($type === 'be') ? $panel['be_server_ip'] : $panel['fe_server_ip'];
        $port = ($type === 'be') ? $panel['be_ssh_port'] : $panel['fe_ssh_port'];
        $user = ($type === 'be') ? $panel['be_ssh_user'] : $panel['fe_ssh_user'];
        $pass = ($type === 'be') ? $panel['be_ssh_pass'] : $panel['fe_ssh_pass'];
        $service = ($type === 'be') ? $panel['be_service'] : $panel['fe_service'];

        if (empty($ip) || empty($user) || empty($service)) {
            echo json_encode(['log' => "Configuration error: Missing credentials or service name."]); exit;
        }

        // Use journalctl for systemd services
        $cmd = "sudo journalctl -u " . escapeshellarg($service) . " -n 100 --no-pager";
    }

    // Execute the appropriate SSH command
    $ssh_result = execute_ssh_command($ip, $port, $user, $pass, $cmd);

    if (!$ssh_result['success']) {
        echo json_encode(['log' => "[SSH ERROR] " . $ssh_result['output']]); exit;
    }

    $log_output = $ssh_result['output'];
    if (empty(trim($log_output))) {
        $log_output = "[".date('Y-m-d H:i:s')."] Connected to $ip successfully.\nFile is empty or waiting for logs...";
    }

    echo json_encode(['log' => $log_output]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request payload']);
