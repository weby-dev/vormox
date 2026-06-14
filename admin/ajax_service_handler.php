<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// ==========================================
// 1. VERIFY ADMIN LOGIN & SECURITY
// ==========================================
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Admin Access']);
    exit;
}

$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$panel_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Panel ID']);
    exit;
}

// ==========================================
// 2. FETCH FULL PANEL CREDENTIALS
// ==========================================
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pd.* FROM user_panels p 
        LEFT JOIN panel_details pd ON p.id = pd.panel_id 
        WHERE p.id = ? LIMIT 1
    ");
    $stmt->execute([$panel_id]);
    $panel = $stmt->fetch();
    
    if (!$panel) {
        echo json_encode(['status' => 'error', 'message' => 'Panel not found.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
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

    // PRE-CHECK: is the host reachable on the SSH port? A private-network IP
    // that doesn't route from the web server makes ssh2_connect() block for
    // 60+ seconds. With JS polling every 4s, workers stack up and the site
    // becomes unresponsive. Fail fast at the TCP layer instead.
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client(
        "tcp://{$ip}:{$port}",
        $errno, $errstr,
        $timeout
    );
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

    // Cap the read so a runaway command can't pin the worker indefinitely.
    stream_set_blocking($stream, true);
    stream_set_timeout($stream, $timeout * 2);

    $stdio = @ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
    if (!$stdio) {
        return ['success' => false, 'output' => "SSH stream open failed."];
    }
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
    $type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_SPECIAL_CHARS); 

    // ---------------------------------------------------------
    // DANGER ZONE: EXTREME TERMINATION LOGIC
    // ---------------------------------------------------------
    if ($action === 'terminate') {
        $warnings = [];

        try {
            $pdo->beginTransaction();

            // STEP 1: CANCEL PENDING INVOICES
            $pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE panel_id = ? AND status = 'Unpaid'")
                ->execute([$panel_id]);

            // STEP 2: FETCH RESELLER DOMAINS FROM CLIENT DB
            $domains_to_remove = [$panel['domain']]; // Always start with the main panel domain

            if (!empty($panel['db_server_ip']) && !empty($panel['db_name']) && !empty($panel['db_user'])) {
                try {
                    // Connect to the client's isolated database with a 3-second timeout
                    $client_pdo = new PDO(
                        "mysql:host={$panel['db_server_ip']};dbname={$panel['db_name']};charset=utf8mb4", 
                        $panel['db_user'], 
                        $panel['db_pass'], 
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
                    );
                    
                    // Check if the reseller_configs table exists
                    $tableExists = $client_pdo->query("SHOW TABLES LIKE 'reseller_configs'")->rowCount() > 0;
                    if ($tableExists) {
                        // Extract all domain_urls
                        $resellers = $client_pdo->query("SELECT domain_url FROM reseller_configs WHERE domain_url IS NOT NULL AND domain_url != ''")->fetchAll(PDO::FETCH_COLUMN);
                        $domains_to_remove = array_merge($domains_to_remove, $resellers);
                    }
                } catch (PDOException $e) {
                    $warnings[] = "Could not connect to Client DB to fetch reseller domains.";
                }
            }

            // STEP 3: REMOVE CONFIGS FROM REVERSE PROXY
            if (!empty($panel['rp_server_ip'])) {
                $rp_commands = [];
                foreach ($domains_to_remove as $dom) {
                    // Strict escaping to prevent shell injection from malicious client domains
                    $safe_dom = escapeshellarg(trim($dom)); 
                    $rp_commands[] = "sudo rm -f /etc/nginx/sites-available/{$safe_dom} /etc/nginx/sites-enabled/{$safe_dom}";
                }
                
                $rp_service = escapeshellarg($panel['rp_service'] ?: 'nginx');
                $rp_commands[] = "sudo systemctl reload {$rp_service}";
                
                $cmd = implode(" && ", $rp_commands);
                $ssh_rp = execute_ssh_command($panel['rp_server_ip'], $panel['rp_ssh_port'], $panel['rp_ssh_user'], $panel['rp_ssh_pass'], $cmd);
                
                if (!$ssh_rp['success']) {
                    $warnings[] = "Reverse Proxy Wipe Failed: " . $ssh_rp['output'];
                }
            } else {
                $warnings[] = "No Reverse Proxy configured. Config removal skipped.";
            }

            // STEP 4: SHUTDOWN ACTUAL SERVICES
            if (!empty($panel['be_server_ip'])) {
                execute_ssh_command($panel['be_server_ip'], $panel['be_ssh_port'], $panel['be_ssh_user'], $panel['be_ssh_pass'], "sudo systemctl stop " . escapeshellarg($panel['be_service']));
            }
            if (!empty($panel['fe_server_ip'])) {
                execute_ssh_command($panel['fe_server_ip'], $panel['fe_ssh_port'], $panel['fe_ssh_user'], $panel['fe_ssh_pass'], "sudo systemctl stop " . escapeshellarg($panel['fe_service']));
            }

            // STEP 5: MARK AS TERMINATED IN MAIN DATABASE
            $pdo->prepare("UPDATE user_panels SET status = 'terminated', auto_renew = 0 WHERE id = ?")->execute([$panel_id]);
            
            // Mark details as offline
            $pdo->prepare("UPDATE panel_details SET be_status = 'offline', fe_status = 'offline' WHERE panel_id = ?")->execute([$panel_id]);

            $pdo->commit();

            $msg = "Service fully terminated. Invoices cancelled and Reverse Proxy wiped.";
            if (count($warnings) > 0) $msg .= " (With warnings: " . implode(" | ", $warnings) . ")";

            echo json_encode(['status' => 'success', 'message' => $msg, 'new_state' => 'terminated']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------------------------------------------------------
    // STANDARD START/STOP/RESTART LOGIC
    // ---------------------------------------------------------
    $allowed_actions = ['start', 'stop', 'restart'];
    if (!in_array($action, $allowed_actions)) {
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']); exit;
    }

    $ip = ($type === 'be') ? $panel['be_server_ip'] : $panel['fe_server_ip'];
    $port = ($type === 'be') ? $panel['be_ssh_port'] : $panel['fe_ssh_port'];
    $user = ($type === 'be') ? $panel['be_ssh_user'] : $panel['fe_ssh_user'];
    $pass = ($type === 'be') ? $panel['be_ssh_pass'] : $panel['fe_ssh_pass'];
    $service = ($type === 'be') ? $panel['be_service'] : $panel['fe_service'];

    if (empty($ip) || empty($user) || empty($service)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing server credentials.']); exit;
    }

    $cmd = "sudo systemctl {$action} " . escapeshellarg($service);
    $ssh_result = execute_ssh_command($ip, $port, $user, $pass, $cmd);

    if (!$ssh_result['success']) {
        echo json_encode(['status' => 'error', 'message' => $ssh_result['output']]); exit;
    }

    $new_state = ($action === 'stop') ? 'offline' : 'online';
    try {
        $status_col = ($type === 'be') ? 'be_status' : 'fe_status';
        $pdo->prepare("UPDATE panel_details SET {$status_col} = ? WHERE panel_id = ?")->execute([$new_state, $panel_id]);
        echo json_encode(['status' => 'success', 'message' => "Service " . strtoupper($action) . "ED.", 'new_state' => $new_state]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Action executed, but DB update failed.']);
    }
    exit;
}

// ==========================================
// 4b. TASK COMPLETION CHECK (GET)
//   The setup_* scripts drop a terminal marker (installed / error / removed) at
//   /var/log/vormox/<domain>[-fe]-task.status when a create/update/delete run
//   finishes. The kickoff removes any stale marker first, so "no file yet" means
//   the job is still running. This endpoint reads that marker, reflects the final
//   state into panel_details, and tells the UI whether it can stop polling.
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'task_status') {
    $type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS);
    $side = ($type === 'be') ? 'be' : (($type === 'fe') ? 'fe' : null);
    if (!$side) {
        echo json_encode(['status' => 'error', 'message' => 'Unknown service type.', 'done' => false]); exit;
    }

    $ip   = $panel["{$side}_server_ip"];
    $port = $panel["{$side}_ssh_port"];
    $user = $panel["{$side}_ssh_user"];
    $pass = $panel["{$side}_ssh_pass"];
    if (empty($ip) || empty($user)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing SSH credentials for ' . strtoupper($side) . '.', 'done' => false]); exit;
    }

    $suffix      = ($side === 'fe') ? '-fe-task.status' : '-task.status';
    $status_file = escapeshellarg('/var/log/vormox/' . $panel['domain'] . $suffix);
    $cmd = "cat {$status_file} 2>/dev/null || echo RUNNING";
    $ssh = execute_ssh_command($ip, $port, $user, $pass, $cmd);

    if (!$ssh['success']) {
        // Host unreachable / transient — let the UI keep polling rather than fail.
        echo json_encode(['status' => 'error', 'state' => 'unknown', 'done' => false, 'message' => $ssh['output']]); exit;
    }

    $marker = strtolower(trim((string) $ssh['output']));
    if (strpos($marker, 'installed') !== false)  { $state = 'installed'; }
    elseif (strpos($marker, 'removed') !== false) { $state = 'removed'; }
    elseif (strpos($marker, 'error') !== false)   { $state = 'error'; }
    else                                          { $state = 'running'; }

    // Reflect terminal states into the DB so badges + the panels dashboard match reality.
    $status_col  = ($side === 'be') ? 'be_status' : 'fe_status';
    $service_col = ($side === 'be') ? 'be_service' : 'fe_service';
    $db_state = ['installed' => 'online', 'error' => 'error', 'removed' => 'offline'][$state] ?? null;
    try {
        if ($state === 'removed') {
            // Delete tore down the systemd unit on the host — drop the service mapping
            // too, so the card shows the "not deployed" state instead of a phantom
            // OFFLINE service. Server IP + SSH creds are kept so it can be re-created.
            $pdo->prepare("UPDATE panel_details SET {$status_col} = 'offline', {$service_col} = NULL WHERE panel_id = ?")
                ->execute([$panel_id]);
        } elseif ($db_state !== null) {
            $pdo->prepare("UPDATE panel_details SET {$status_col} = ? WHERE panel_id = ?")
                ->execute([$db_state, $panel_id]);
        }
    } catch (PDOException $e) { /* non-fatal */ }

    echo json_encode([
        'status'   => 'success',
        'state'    => $state,
        'done'     => in_array($state, ['installed', 'error', 'removed'], true),
        'db_state' => $db_state,
    ]);
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
    // BACKEND & FRONTEND SYSTEMD LOGS  +  BACKEND BUILD/TASK LOG
    // ---------------------------------------------------------
    } else {
        // Map dropdown type → which side's SSH credentials to use.
        // The old ternary $type === 'be' ? be : fe silently routed `be_task`
        // (Backend Build & Task Progress) to the FRONTEND side because the
        // string wasn't exactly 'be'. That's why backend selections were
        // showing frontend logs.
        $side = null;
        if ($type === 'be' || $type === 'be_task') { $side = 'be'; }
        elseif ($type === 'fe' || $type === 'fe_task') { $side = 'fe'; }
        else {
            echo json_encode(['log' => "Unknown log source: " . htmlspecialchars((string)$type)]); exit;
        }

        $ip      = $panel["{$side}_server_ip"];
        $port    = $panel["{$side}_ssh_port"];
        $user    = $panel["{$side}_ssh_user"];
        $pass    = $panel["{$side}_ssh_pass"];
        $service = $panel["{$side}_service"];

        if (empty($ip) || empty($user)) {
            echo json_encode(['log' => "Configuration error: Missing SSH credentials for " . strtoupper($side) . " server."]); exit;
        }

        if ($type === 'be_task' || $type === 'fe_task') {
            // Per-domain build/task log written by setup_backend.php / setup_frontend.php.
            // BE side writes to <domain>-task.log; FE side writes to <domain>-fe-task.log.
            $suffix = ($type === 'fe_task') ? '-fe-task.log' : '-task.log';
            $task_log = escapeshellarg('/var/log/vormox/' . $panel['domain'] . $suffix);
            $cmd = "sudo tail -n 100 {$task_log} 2>/dev/null || echo 'No task log yet for {$panel['domain']}.'";
        } else {
            if (empty($service)) {
                echo json_encode(['log' => "Configuration error: Missing service name for " . strtoupper($side) . "."]); exit;
            }
            // systemd journalctl for the long-running daemon
            $cmd = "sudo journalctl -u " . escapeshellarg($service) . " -n 100 --no-pager";
        }
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
