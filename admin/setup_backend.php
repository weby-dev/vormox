<?php
// admin/setup_backend.php
//
// Kicks off the first-time backend installer for a panel. Generates a bash
// script with the panel's specific credentials, SCPs it onto the backend
// host, and runs it in the background with output to
//   /var/log/vormox/<domain>-task.log
//
// The admin watches progress live via the existing "Backend Build & Task
// Progress" channel on manage_panel.php (which tails that same file).
//
// PHP sets panel_details.be_status = 'installing' before kickoff. The bash
// script can't reach the Vormox DB (it only has the customer's credentials),
// so it drops a marker at /var/log/vormox/<domain>-task.status containing
// either 'installed' or 'error'. Admins read this from the terminal pane.
// A future lifecycle cron tick is the right place to reflect this into the
// Vormox DB if you want automated status transitions.

session_start();
require_once '../config.php';

header('Content-Type: application/json');

// --- Admin auth (matches the rest of /admin) ---
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

// --- Load the panel + details, fail loudly on missing required fields ---
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.domain, p.status,
               pd.be_server_ip, pd.be_ssh_port, pd.be_ssh_user, pd.be_ssh_pass,
               pd.be_service, pd.be_git_url, pd.be_git_user, pd.be_git_pass,
               pd.db_server_ip, pd.db_name, pd.db_user, pd.db_pass
          FROM user_panels  p
          JOIN panel_details pd ON pd.panel_id = p.id
         WHERE p.id = ? LIMIT 1
    ");
    $stmt->execute([$panel_id]);
    $panel = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error loading panel.']); exit;
}

if (!$panel) {
    echo json_encode(['success' => false, 'message' => 'Panel not found, or details have not been saved yet.']); exit;
}

$required = [
    'domain'       => 'Domain',
    'be_server_ip' => 'Backend Server IP',
    'be_ssh_user'  => 'Backend SSH User',
    'be_ssh_pass'  => 'Backend SSH Password',
    'be_service'   => 'Backend Service Name',
    'be_git_url'   => 'Backend Git URL',
    'db_server_ip' => 'DB Server IP',
    'db_name'      => 'DB Name',
    'db_user'      => 'DB User',
    'db_pass'      => 'DB Password',
];
$missing = [];
foreach ($required as $col => $label) {
    if (empty($panel[$col])) $missing[] = $label;
}
if ($missing) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missing),
    ]);
    exit;
}

// --- Generate per-install secrets so two panels never share a JWT key ---
// 16-byte AES-128 key + 32-byte JWT secret (base64).
$app_enc_key  = bin2hex(random_bytes(8));               // 16 hex chars = 16 bytes when used as a string
$jwt_key_b64  = base64_encode(random_bytes(48));        // 48 random bytes → 64 base64 chars

// --- Build the auth-injected git URL ---
$git_url = $panel['be_git_url'];
if (!empty($panel['be_git_user'])) {
    $auth = rawurlencode($panel['be_git_user']);
    if (!empty($panel['be_git_pass'])) {
        $auth .= ':' . rawurlencode($panel['be_git_pass']);
    }
    $git_url = preg_replace('|^(https?://)|i', '\\1' . $auth . '@', $git_url, 1);
}

// --- Build the bash script ---
$ssh_port = (int) ($panel['be_ssh_port'] ?: 22);

// Normalize the service name. The bash script ALWAYS writes the unit to
// /etc/systemd/system/<service>.service, so if the admin typed the suffix
// already (e.g. "somani-one.service") we strip it here — otherwise the
// file lands at /etc/systemd/system/somani-one.service.service and
// `systemctl enable somani-one.service` fails to find it.
$service  = preg_replace('/[^A-Za-z0-9._-]/', '', $panel['be_service']) ?: 'vormox-backend';
$service  = preg_replace('/\.service$/i', '', $service);

// Render the script. ALL placeholders are substituted PHP-side; nothing inside
// the heredoc depends on $variable interpolation by bash unless prefixed with
// the literal $ that we want to survive into bash.
$script = <<<BASH
#!/bin/bash
# ---------------------------------------------------------------------------
# Auto-generated backend installer for {$panel['domain']}
# ---------------------------------------------------------------------------
set -u   # error on undefined vars (but NOT -e — we handle errors explicitly)

DOMAIN="{$panel['domain']}"
SERVICE="{$service}"
BACKEND_DIR="/root/somaniOne-main"
LOG_DIR="/var/log/vormox"
LOG="\${LOG_DIR}/\${DOMAIN}-task.log"
PANEL_ID="{$panel_id}"

mkdir -p "\$LOG_DIR" /var/lock
touch "\$LOG"
chmod 600 "\$LOG"

ts() { date '+[%Y-%m-%d %H:%M:%S]'; }
log() { echo "\$(ts) \$*" >> "\$LOG"; }

# Concurrency guard. The script touches /root/somaniOne-main which is shared
# across every backend install on this host — two scripts running at once
# corrupt the clone. Acquire an exclusive lock on /var/lock/vormox-backend.lock
# and bail cleanly if a sibling already holds it.
exec 200>/var/lock/vormox-backend.lock
if ! flock -n 200; then
    log "Another backend setup is already running on this host. Exiting."
    exit 0
fi

fail() {
    log "FATAL: \$*"
    log "==========================================="
    log "FAILURE: Backend setup aborted for \$DOMAIN"
    log "==========================================="
    # Drop a marker the admin (or a future lifecycle cron tick) can detect.
    echo "error" > "/var/log/vormox/\${DOMAIN}-task.status" 2>/dev/null || true
    exit 1
}

run() {
    log "+ \$*"
    if ! eval "\$* >> \\"\$LOG\\" 2>&1"; then
        fail "Step failed: \$*"
    fi
}

log "==========================================="
log "Starting backend setup for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$BACKEND_DIR"
log "==========================================="

# -----------------------------------------------------------------
# Step 1: prerequisites
# (apt update → apt upgrade → set timezone → install our build deps)
# -----------------------------------------------------------------
log ""
log ">>> Step 1/5: system prep + install prerequisites"
export DEBIAN_FRONTEND=noninteractive
# --force-conf* keeps existing config files instead of prompting on upgrade,
# which would hang the script under noninteractive mode.
APT_OPTS='-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold'
run "apt-get update -y"
run "apt-get \$APT_OPTS upgrade -y"
run "timedatectl set-timezone Asia/Kolkata"
run "apt-get install -y git maven openjdk-21-jdk default-mysql-client"

# -----------------------------------------------------------------
# Step 2: clone repo (clean slate)
# -----------------------------------------------------------------
log ""
log ">>> Step 2/5: cloning backend repo"
if [ -d "\$BACKEND_DIR" ]; then
    log "Removing existing \$BACKEND_DIR"
    rm -rf "\$BACKEND_DIR"
fi
# git URL has auth pre-embedded if needed
run "git clone '{$git_url}' '\$BACKEND_DIR'"

# -----------------------------------------------------------------
# Step 3: maven build
# -----------------------------------------------------------------
log ""
log ">>> Step 3/5: mvn clean package -DskipTests (this can take several minutes)"
cd "\$BACKEND_DIR" || fail "cd into \$BACKEND_DIR"
run "mvn -q clean package -DskipTests"

# Discover the produced jar — works regardless of project version suffix.
JAR=\$(ls "\$BACKEND_DIR"/target/*.jar 2>/dev/null | grep -v -- '-original' | grep -v 'sources' | grep -v 'javadoc' | head -1)
if [ -z "\$JAR" ]; then
    fail "Build did not produce a runnable jar under \$BACKEND_DIR/target"
fi
log "Built jar: \$JAR"

# -----------------------------------------------------------------
# Step 4: systemd unit
# -----------------------------------------------------------------
log ""
log ">>> Step 4/5: writing /etc/systemd/system/\${SERVICE}.service"
cat > "/etc/systemd/system/\${SERVICE}.service" <<UNIT
[Unit]
Description=Vormox Backend (\$DOMAIN)
After=network.target mysql.service
Wants=network-online.target
StartLimitIntervalSec=60
StartLimitBurst=3

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=\$BACKEND_DIR

Environment="APP_ENCRYPTION_KEY={$app_enc_key}"
Environment="APP_FRONTEND_URL=https://{$panel['domain']}"
Environment="JDBC_DATABASE_URL=jdbc:mysql://{$panel['db_server_ip']}:3306/{$panel['db_name']}?useSSL=false&serverTimezone=UTC&allowPublicKeyRetrieval=true"
Environment="JDBC_DATABASE_USERNAME={$panel['db_user']}"
Environment="JDBC_DATABASE_PASSWORD={$panel['db_pass']}"
Environment="JWT_SECRET_KEY={$jwt_key_b64}"
Environment="DB_NAME={$panel['db_name']}"

ExecStart=/usr/bin/java -jar \$JAR

StandardOutput=journal
StandardError=journal
SyslogIdentifier=\$SERVICE

Restart=on-failure
RestartSec=10
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
UNIT

# -----------------------------------------------------------------
# Step 5: enable + start, wait for healthy
# -----------------------------------------------------------------
log ""
log ">>> Step 5/5: systemctl reload + enable + restart + wait"
run "systemctl daemon-reload"
run "systemctl enable \$SERVICE"
run "systemctl restart \$SERVICE"

# Java apps take ~2-3 minutes to fully start. Watch up to 5 minutes total,
# checking once per 5s. We need TWO consecutive 'active' samples (5s apart)
# because Spring Boot can briefly look healthy before crashing on config.
log "Waiting for \$SERVICE to become healthy (up to 5 min)…"
HEALTHY=0
for i in \$(seq 1 60); do
    if systemctl is-active --quiet "\$SERVICE"; then
        sleep 5
        if systemctl is-active --quiet "\$SERVICE"; then
            HEALTHY=1
            break
        fi
    fi
    if [ \$((i % 6)) -eq 0 ]; then log "  …still waiting (\$((i * 5))s elapsed)"; fi
    sleep 5
done

if [ "\$HEALTHY" -eq 1 ]; then
    log ""
    log "==========================================="
    log "SUCCESS: Backend setup complete for \$DOMAIN"
    log "Service: \$SERVICE is active"
    log "==========================================="
    echo "installed" > "/var/log/vormox/\${DOMAIN}-task.status" 2>/dev/null || true
    exit 0
else
    log ""
    log "Service did not become healthy. Last 40 lines of journal:"
    journalctl -u "\$SERVICE" -n 40 --no-pager >> "\$LOG" 2>&1 || true
    fail "Service \$SERVICE never reached active state"
fi
BASH;

// --- Ship + execute ---
$b64 = base64_encode($script);
$script_path = "/tmp/vormox-setup-{$panel_id}.sh";

if (!function_exists('ssh2_connect')) {
    echo json_encode(['success' => false, 'message' => 'PHP ssh2 extension missing on web server.']); exit;
}

// Fast TCP pre-check so we don't hang the request for 60s on an unreachable IP
$errno = 0; $errstr = '';
$probe = @stream_socket_client("tcp://{$panel['be_server_ip']}:{$ssh_port}", $errno, $errstr, 5);
if (!$probe) {
    echo json_encode(['success' => false, 'message' => "Backend host unreachable: {$panel['be_server_ip']}:{$ssh_port} ({$errstr})"]);
    exit;
}
fclose($probe);

$conn = @ssh2_connect($panel['be_server_ip'], $ssh_port);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'SSH handshake failed.']); exit;
}
if (!@ssh2_auth_password($conn, $panel['be_ssh_user'], $panel['be_ssh_pass'])) {
    echo json_encode(['success' => false, 'message' => 'SSH authentication failed.']); exit;
}

// Mark installing BEFORE we kick the script off — that way the UI status
// flips immediately and if the kickoff itself fails we'll see "installing"
// stuck forever (which is a useful signal too).
try {
    $pdo->prepare("UPDATE panel_details SET be_status = 'installing' WHERE panel_id = ?")
        ->execute([$panel_id]);
} catch (PDOException $e) { /* non-fatal */ }

// Concurrency precheck — `flock -n` exits 1 immediately if the lock is
// already held. We pre-test so the admin gets a clear "already running"
// error instead of a silent "Launched" toast for a script that promptly
// exits. (The bash script also re-acquires the lock as a backup in case
// the race fires between this test and the kickoff.)
$precheck = "mkdir -p /var/lock && flock -n /var/lock/vormox-backend.lock -c true && echo OK || echo LOCKED";
$pstream = @ssh2_exec($conn, $precheck);
if ($pstream) {
    stream_set_blocking($pstream, true);
    $presult = trim((string) @stream_get_contents(@ssh2_fetch_stream($pstream, SSH2_STREAM_STDIO)));
    @fclose($pstream);
    if (stripos($presult, 'LOCKED') !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'A backend install is already running on this host. Watch the task log; if it looks stuck, wait a few minutes or remove /var/lock/vormox-backend.lock manually.',
        ]);
        exit;
    }
}

// Write the script, then nohup it. Detach all stdio so ssh2_exec returns fast.
$kickoff = "mkdir -p /var/log/vormox && "
        . "printf '%s' '{$b64}' | base64 -d > {$script_path} && "
        . "chmod +x {$script_path} && "
        . "(nohup bash {$script_path} </dev/null >/dev/null 2>&1 &)";

$stream = @ssh2_exec($conn, $kickoff);
if (!$stream) {
    echo json_encode(['success' => false, 'message' => 'Could not launch installer over SSH.']); exit;
}
stream_set_blocking($stream, true);
@stream_get_contents(@ssh2_fetch_stream($stream, SSH2_STREAM_STDIO));
@fclose($stream);

echo json_encode([
    'success' => true,
    'message' => 'Backend setup launched. Watch progress in the panel terminal ("Backend Build & Task Progress").',
    'panel_id' => $panel_id,
    'log_path' => "/var/log/vormox/{$panel['domain']}-task.log",
    'manage_url' => "manage_panel.php?id={$panel_id}",
]);
