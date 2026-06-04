<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
require_once '../config.php'; 

$user_ip = $_SERVER['REMOTE_ADDR'];
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    if ($countStmt->fetchColumn() > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) { echo json_encode(['error' => 'Security verification failed.']); exit; }
    }
} catch (PDOException $e) { echo json_encode(['error' => 'Security error.']); exit; }

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) { echo json_encode(['error' => 'Unauthorized.']); exit; }

$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$panel_id) { echo json_encode(['error' => 'Missing Panel ID.']); exit; }

try {
    $stmt = $pdo->prepare("SELECT p.*, pd.* FROM user_panels p LEFT JOIN panel_details pd ON p.id = pd.panel_id WHERE p.id = :id LIMIT 1");
    $stmt->execute(['id' => $panel_id]);
    $panel = $stmt->fetch();
    if (!$panel) { echo json_encode(['error' => 'Panel not found.']); exit; }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error.']); exit;
}

// ==========================================
// HANDLE LIVE LOGS (GET)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    header('Content-Type: application/json');
    $type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS);
    
    if (!in_array($type, ['be', 'fe', 'be_task'])) { echo json_encode(['log' => 'Invalid log type.']); exit; }

    $ssh_prefix = ($type === 'be_task') ? 'be' : $type;
    $ip = $panel[$ssh_prefix . '_server_ip'];
    $port = $panel[$ssh_prefix . '_ssh_port'] ?: 22;
    $user = $panel[$ssh_prefix . '_ssh_user'];
    $pass = $panel[$ssh_prefix . '_ssh_pass'];
    $service = $panel[$ssh_prefix . '_service'];

    if (!$ip || !$user || !$pass) { echo json_encode(['log' => "Configuration incomplete for this service."]); exit; }

    $connection = @ssh2_connect($ip, $port);
    if ($connection && @ssh2_auth_password($connection, $user, $pass)) {
        if ($type === 'be_task') {
            $cmd = "tail -n 100 /tmp/be_task.log";
        } else {
            $cmd_prefix = (strtolower($user) === 'root') ? '' : 'sudo ';
            $cmd = $cmd_prefix . "journalctl -u " . escapeshellarg($service) . " -n 50 --no-pager --no-hostname";
        }
        
        $stream = ssh2_exec($connection, $cmd);
        stream_set_blocking($stream, true);
        $logOutput = stream_get_contents(ssh2_fetch_stream($stream, SSH2_STREAM_STDIO));
        echo json_encode(['log' => $logOutput ?: "Waiting for logs..."]);
    } else {
        echo json_encode(['log' => "Authentication failed. Could not reach $ip."]);
    }
    exit;
}

// ==========================================
// HANDLE ACTIONS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'], $_POST['service_type'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $type = $_POST['service_type']; 
    
    $ip = $panel[$type . '_server_ip'];
    $port = $panel[$type . '_ssh_port'] ?: 22;
    $user = $panel[$type . '_ssh_user'];
    $pass = $panel[$type . '_ssh_pass'];
    $service = $panel[$type . '_service'];
    
    if (!$ip || !$user || !$pass) { echo json_encode(['status' => 'error', 'message' => "Missing SSH connection details in database."]); exit; } 
    if (!function_exists('ssh2_connect')) { echo json_encode(['status' => 'error', 'message' => "php-ssh2 extension missing."]); exit; } 
    
    $connection = @ssh2_connect($ip, $port);
    if (!$connection || !@ssh2_auth_password($connection, $user, $pass)) {
        echo json_encode(['status' => 'error', 'message' => "SSH Authentication failed for $ip."]); exit;
    }

    // Prepare dynamic env variables
    $git_url = $panel['be_git_url'];
    $git_user = $panel['be_git_user'];
    $git_pass = $panel['be_git_pass'];
    $repo_clean = preg_replace('#^https?://#', '', $git_url);
    $auth_repo = "https://" . urlencode($git_user) . ":" . urlencode($git_pass) . "@" . $repo_clean;
    
    $domain = $panel['domain'];
    $db_url = "jdbc:mysql://{$panel['db_server_ip']}:3306/{$panel['db_name']}?useSSL=false&serverTimezone=UTC&allowPublicKeyRetrieval=true";
    $service_clean = escapeshellarg($service);

    $background_script = "";

    // --- DEPLOYMENT SCRIPTS ---
    if ($type === 'be' && in_array($action, ['create', 'update', 'remove'])) {
        
        if ($action === 'create') {
            if (!$git_url || !$git_user || !$git_pass) { echo json_encode(['status' => 'error', 'message' => "Git credentials missing."]); exit; }
            $background_script = "
echo 'Starting Backend Installation & Build Process...'
apt update && apt upgrade -y
DEBIAN_FRONTEND=noninteractive apt install git maven openjdk-21-jdk -y

systemctl stop {$service_clean} || true
rm -rf /root/somaniOne-main
git clone " . escapeshellarg($auth_repo) . " /root/somaniOne-main
cd /root/somaniOne-main
mvn clean package -DskipTests

cat << 'SRV' > /etc/systemd/system/{$service}
[Unit]
Description=SomaniOne Spring Boot Application
After=network.target mysql.service
Wants=network-online.target
StartLimitIntervalSec=60
StartLimitBurst=3

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=/root/somaniOne-main

Environment=\"APP_ENCRYPTION_KEY=1234567890123456\"
Environment=\"APP_FRONTEND_URL={$domain}\"
Environment=\"JDBC_DATABASE_URL={$db_url}\"
Environment=\"JDBC_DATABASE_USERNAME={$panel['db_user']}\"
Environment=\"JDBC_DATABASE_PASSWORD={$panel['db_pass']}\"
Environment=\"JWT_SECRET_KEY=bXktc3VwZXItc2VjcmV0LWtleS1mb3ItdGhlLWFwcC0xMjM0NQ==\"
Environment=\"DB_NAME={$panel['db_name']}\"

ExecStart=/usr/bin/java -jar /root/somaniOne-main/target/somaniOne-0.0.1-SNAPSHOT.jar

StandardOutput=journal
StandardError=journal
SyslogIdentifier=somani-one

Restart=on-failure
RestartSec=10
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
SRV

systemctl daemon-reload
systemctl enable {$service_clean}
systemctl start {$service_clean}
echo 'Deployment Completed Successfully.'
";
        } elseif ($action === 'update') {
            if (!$git_url || !$git_user || !$git_pass) { echo json_encode(['status' => 'error', 'message' => "Git credentials missing."]); exit; }
            $background_script = "
echo 'Starting Backend Pull & Update...'
systemctl stop {$service_clean} || true
sleep 2
rm -rf /root/somaniOne-main

git clone " . escapeshellarg($auth_repo) . " /root/somaniOne-main
cd /root/somaniOne-main

export APP_ENCRYPTION_KEY='1234567890123456'
export APP_FRONTEND_URL={$domain}
export JDBC_DATABASE_URL='{$db_url}'
export JDBC_DATABASE_USERNAME='{$panel['db_user']}'
export JDBC_DATABASE_PASSWORD='{$panel['db_pass']}'
export JWT_SECRET_KEY='bXktc3VwZXItc2VjcmV0LWtleS1mb3ItdGhlLWFwcC0xMjM0NQ=='
export DB_NAME='{$panel['db_name']}'

mvn clean package -DskipTests
systemctl start {$service_clean}
echo 'Update Completed Successfully.'
";
        } elseif ($action === 'remove') {
            $background_script = "
echo 'Starting Backend Removal Process...'
systemctl stop {$service_clean} || true
systemctl disable {$service_clean} || true
rm -f /etc/systemd/system/{$service}
systemctl daemon-reload
rm -rf /root/somaniOne-main
echo 'Backend Removed Successfully. Systemd file deleted. Source folder erased.'
";
        }

        // Run the script in the background
        $wrapper = "cat << 'EOF' > /tmp/be_task.sh\n#!/bin/bash\nexec > /tmp/be_task.log 2>&1\n{$background_script}\nEOF\nchmod +x /tmp/be_task.sh\nnohup /tmp/be_task.sh > /dev/null 2>&1 &";
        ssh2_exec($connection, $wrapper);
        
        echo json_encode(['status' => 'success', 'message' => "Task '$action' initialized! Switch terminal to 'Task Progress' to watch."]);
        exit;
    }

    // --- STANDARD START / STOP / RESTART ---
    if (in_array($action, ['start', 'stop', 'restart'])) {
        $cmd_prefix = (strtolower($user) === 'root') ? '' : 'sudo ';
        $command = $cmd_prefix . "systemctl --no-block " . escapeshellarg($action) . " " . escapeshellarg($service);
        
        $stream = ssh2_exec($connection, $command);
        if ($stream) {
            stream_set_blocking($stream, true);
            $errors = stream_get_contents(ssh2_fetch_stream($stream, SSH2_STREAM_STDERR));
            fclose($stream);
            
            if (!empty($errors) && strpos(strtolower($errors), 'error') !== false) {
                echo json_encode(['status' => 'error', 'message' => htmlspecialchars($errors)]);
            } else {
                $new_state = ($action === 'stop') ? 'offline' : 'online';
                $pdo->prepare("UPDATE panel_details SET {$type}_status = ? WHERE panel_id = ?")->execute([$new_state, $panel_id]);
                echo json_encode(['status' => 'success', 'message' => "Service '$action' executed.", 'new_state' => $new_state]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => "Failed to execute command on server."]);
        }
    }
    exit;
}
