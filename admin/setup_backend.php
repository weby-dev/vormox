<?php
// admin/setup_backend.php
//
// Three actions for the Java/Maven backend, all dispatched over SSH as a
// detached bash worker on the BE host:
//   action=create  → apt deps, git clone, mvn build, write systemd unit, start
//   action=update  → stop, refresh code from git, mvn rebuild, repoint unit, restart
//   action=delete  → stop + disable + remove unit file + rm -rf the project dir
//
// All long-running work happens in a detached bash script writing progress to
//   /var/log/vormox/<domain>-task.log
// which the admin watches live via the "Backend Build & Task Progress" channel
// on manage_panel.php. This mirrors setup_frontend.php exactly.
//
// PHP sets panel_details.be_status = 'installing' before a create/update kickoff.
// The bash script can't reach the Vormox DB (it only has the customer's
// credentials), so it drops a marker at /var/log/vormox/<domain>-task.status
// containing 'installed' / 'error' / 'removed'. A future lifecycle cron tick is
// the right place to reflect that back into the Vormox DB.

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
$action   = strtolower((string) ($_POST['action'] ?? 'create'));
if (!in_array($action, ['create', 'update', 'delete'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}
if (!$panel_id) {
    echo json_encode(['success' => false, 'message' => 'Missing panel_id.']); exit;
}

// --- Load the panel + details ---
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

// --- Required fields depend on the action ---
//   • every action needs to reach the box + know which unit to control
//   • create/update need the repo URL
//   • only create writes the systemd unit, so only create needs the DB creds
$required = ['be_server_ip', 'be_ssh_user', 'be_ssh_pass'];
if (in_array($action, ['update', 'delete'], true)) {
    // update/delete act on an EXISTING unit, so the service name must already exist.
    // create derives one from the domain if it's blank (e.g. after a delete cleared it).
    $required[] = 'be_service';
}
if (in_array($action, ['create', 'update'], true)) {
    $required[] = 'be_git_url';
}
if ($action === 'create') {
    array_push($required, 'db_server_ip', 'db_name', 'db_user', 'db_pass');
}
$labels = [
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
foreach ($required as $col) {
    if (empty($panel[$col])) $missing[] = $labels[$col] ?? $col;
}
if (empty($panel['domain'])) $missing[] = 'Panel domain';
if ($missing) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missing),
    ]);
    exit;
}

// --- Generate per-install secrets so two panels never share a JWT key ---
// (Only consumed by the create body — update preserves the existing unit.)
$app_enc_key  = bin2hex(random_bytes(8));               // 16 hex chars = 16 bytes when used as a string
$jwt_key_b64  = base64_encode(random_bytes(48));        // 48 random bytes → 64 base64 chars

// --- Build the auth-injected git URL (only relevant for create/update) ---
$git_url = $panel['be_git_url'];
if (!empty($panel['be_git_user']) && in_array($action, ['create', 'update'], true)) {
    $auth = rawurlencode($panel['be_git_user']);
    if (!empty($panel['be_git_pass'])) {
        $auth .= ':' . rawurlencode($panel['be_git_pass']);
    }
    $git_url = preg_replace('|^(https?://)|i', '\\1' . $auth . '@', $git_url, 1);
}

$ssh_port = (int) ($panel['be_ssh_port'] ?: 22);
$domain   = $panel['domain'];

// Normalize the service name. The bash script ALWAYS writes the unit to
// /etc/systemd/system/<service>.service, so if the admin typed the suffix
// already (e.g. "somani-one.service") we strip it here — otherwise the file
// lands at /etc/systemd/system/somani-one.service.service and
// `systemctl enable somani-one.service` fails to find it.
$service = trim((string) $panel['be_service']);
$service = preg_replace('/\.service$/i', '', $service);           // strip suffix if admin typed it
$service = preg_replace('/[^A-Za-z0-9._-]/', '', $service);        // sanitize
if ($service === '') {
    // Blank (e.g. a prior delete cleared it) → derive be-<domain>, mirroring setup_frontend.php.
    $service = 'be-' . preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($domain));
    $service = trim($service, '-') ?: ('be-panel-' . $panel_id);
}

// ---------------------------------------------------------------------------
// Shared script header — lock, logging, fail()/run() helpers.
// All three actions log to the same per-domain file so the admin sees the
// full history. The lock is shared across every backend job on this host
// (they all touch /root/somaniOne-main), so only one can run at a time.
// ---------------------------------------------------------------------------
$header = <<<HDR
#!/bin/bash
set -u

DOMAIN="{$domain}"
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

# Concurrency guard. /root/somaniOne-main is shared across every backend job
# on this host — two scripts at once corrupt the clone. flock -n bails
# immediately if a sibling create/update/delete already holds the lock.
exec 200>/var/lock/vormox-backend.lock
if ! flock -n 200; then
    log "Another backend job is already running on this host. Exiting."
    exit 0
fi

fail() {
    log "FATAL: \$*"
    log "==========================================="
    log "FAILURE: Backend {$action} aborted for \$DOMAIN"
    log "==========================================="
    echo "error" > "\${LOG_DIR}/\${DOMAIN}-task.status" 2>/dev/null || true
    exit 1
}

run() {
    log "+ \$*"
    if ! eval "\$* >> \\"\$LOG\\" 2>&1"; then
        fail "Step failed: \$*"
    fi
}
HDR;

// ---------------------------------------------------------------------------
// Per-action script body.
// ---------------------------------------------------------------------------
if ($action === 'create') {
    $body = <<<SCRIPT
log "==========================================="
log "Starting backend setup for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$BACKEND_DIR"
log "==========================================="
run "systemctl daemon-reload"
# Step 1: prerequisites (apt update → upgrade → timezone → build deps)
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

# Step 2: clone repo (clean slate)
log ""
log ">>> Step 2/5: cloning backend repo"
if [ -d "\$BACKEND_DIR" ]; then
    log "Removing existing \$BACKEND_DIR"
    rm -rf "\$BACKEND_DIR"
fi
run "git clone '{$git_url}' '\$BACKEND_DIR'"

# Step 3: maven build
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

# Step 4: systemd unit
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
Environment="APP_FRONTEND_URL=https://{$domain}"
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

# Step 5: enable + start, wait for healthy
log ""
log ">>> Step 5/5: systemctl reload + enable + restart + wait"
run "systemctl daemon-reload"
run "systemctl enable \$SERVICE"
run "systemctl restart \$SERVICE"

# Java apps take ~2-3 minutes to fully start. Watch up to 5 minutes, checking
# every 5s. Need TWO consecutive 'active' samples because Spring Boot can
# briefly look healthy before crashing on config.
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
    echo "installed" > "\${LOG_DIR}/\${DOMAIN}-task.status" 2>/dev/null || true
    exit 0
else
    log ""
    log "Service did not become healthy. Last 40 lines of journal:"
    journalctl -u "\$SERVICE" -n 40 --no-pager >> "\$LOG" 2>&1 || true
    fail "Service \$SERVICE never reached active state"
fi
SCRIPT;

} elseif ($action === 'update') {
    $body = <<<SCRIPT
log "==========================================="
log "Updating backend for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$BACKEND_DIR"
log "==========================================="

# Stop the running service first so the rebuild doesn't race the live process.
log ""
log ">>> Step 1/5: stopping \$SERVICE"
systemctl stop "\$SERVICE" >> "\$LOG" 2>&1 || log "  (service was not running)"

# Refresh source. Prefer an in-place fast-forward of the existing checkout;
# fall back to a clean clone if the dir isn't a git repo.
log ""
log ">>> Step 2/5: wiping \$BACKEND_DIR and re-cloning"
rm -rf "\$BACKEND_DIR"
run "git clone '{$git_url}' '\$BACKEND_DIR'"
cd "\$BACKEND_DIR" || fail "cd into \$BACKEND_DIR"

# Rebuild
log ""
log ">>> Step 3/5: mvn clean package -DskipTests (this can take several minutes)"
run "mvn -q clean package -DskipTests"

JAR=\$(ls "\$BACKEND_DIR"/target/*.jar 2>/dev/null | grep -v -- '-original' | grep -v 'sources' | grep -v 'javadoc' | head -1)
if [ -z "\$JAR" ]; then
    fail "Build did not produce a runnable jar under \$BACKEND_DIR/target"
fi
log "Built jar: \$JAR"

# Point the existing unit at the freshly-built jar (the version suffix in the
# filename may have changed). Only ExecStart is rewritten — every Environment=
# secret written at create time is preserved.
log ""
log ">>> Step 4/5: pointing the systemd unit at the new jar"
UNIT_FILE="/etc/systemd/system/\${SERVICE}.service"
if [ ! -f "\$UNIT_FILE" ]; then
    fail "Unit \$UNIT_FILE not found — run Create Backend first"
fi
sed -i "s|^ExecStart=.*|ExecStart=/usr/bin/java -jar \$JAR|" "\$UNIT_FILE"

log ""
log ">>> Step 5/5: systemctl reload + restart + wait"
run "systemctl daemon-reload"
run "systemctl restart \$SERVICE"

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
    log "SUCCESS: Backend updated for \$DOMAIN"
    log "==========================================="
    echo "installed" > "\${LOG_DIR}/\${DOMAIN}-task.status" 2>/dev/null || true
    exit 0
else
    log ""
    log "Service did not become healthy. Last 40 lines of journal:"
    journalctl -u "\$SERVICE" -n 40 --no-pager >> "\$LOG" 2>&1 || true
    fail "Service \$SERVICE never reached active state after update"
fi
SCRIPT;

} else { // delete
    $body = <<<SCRIPT
log "==========================================="
log "Deleting backend for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$BACKEND_DIR"
log "==========================================="

log ""
log ">>> Step 1/4: stopping \$SERVICE"
systemctl stop "\$SERVICE" >> "\$LOG" 2>&1 || log "  (already stopped)"

log ">>> Step 2/4: disabling \$SERVICE"
systemctl disable "\$SERVICE" >> "\$LOG" 2>&1 || log "  (was not enabled)"

log ">>> Step 3/4: removing /etc/systemd/system/\${SERVICE}.service"
rm -f "/etc/systemd/system/\${SERVICE}.service"
systemctl daemon-reload >> "\$LOG" 2>&1 || true

log ">>> Step 4/4: removing \$BACKEND_DIR"
rm -rf "\$BACKEND_DIR"

log "==========================================="
log "SUCCESS: Backend removed for \$DOMAIN"
log "==========================================="
echo "removed" > "\${LOG_DIR}/\${DOMAIN}-task.status" 2>/dev/null || true
exit 0
SCRIPT;
}

$script = $header . "\n\n" . $body;

// --- Ship + execute over SSH (same pattern as setup_frontend.php) ---
$b64 = base64_encode($script);
$script_path = "/tmp/vormox-be-{$action}-{$panel_id}.sh";

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

// Mark installing BEFORE kickoff for create/update — the UI status flips
// immediately, and if the kickoff itself fails it stays "installing" (a useful
// stuck-signal). Delete leaves the stored status untouched (mirrors frontend).
if ($action === 'create') {
    // Persist the (possibly derived) service name so start/stop/logs/update/delete
    // can find the unit — parallels setup_frontend.php writing fe_service on create.
    try {
        $pdo->prepare("UPDATE panel_details SET be_service = ?, be_status = 'installing' WHERE panel_id = ?")
            ->execute([$service, $panel_id]);
    } catch (PDOException $e) { /* non-fatal */ }
} elseif ($action === 'update') {
    try {
        $pdo->prepare("UPDATE panel_details SET be_status = 'installing' WHERE panel_id = ?")
            ->execute([$panel_id]);
    } catch (PDOException $e) { /* non-fatal */ }
}

// Concurrency precheck — `flock -n` exits 1 immediately if the lock is already
// held. We pre-test so the admin gets a clear "already running" error instead
// of a silent "Launched" toast for a script that promptly exits. (The bash
// script also re-acquires the lock as a backup in case the race fires.)
$precheck = "mkdir -p /var/lock && flock -n /var/lock/vormox-backend.lock -c true && echo OK || echo LOCKED";
$pstream = @ssh2_exec($conn, $precheck);
if ($pstream) {
    stream_set_blocking($pstream, true);
    $presult = trim((string) @stream_get_contents(@ssh2_fetch_stream($pstream, SSH2_STREAM_STDIO)));
    @fclose($pstream);
    if (stripos($presult, 'LOCKED') !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'A backend job is already running on this host. Watch the task log; if it looks stuck, wait a few minutes or remove /var/lock/vormox-backend.lock manually.',
        ]);
        exit;
    }
}

// Write the script, then nohup it. Detach all stdio so ssh2_exec returns fast.
// Remove any stale status marker FIRST — the completion poller treats the
// marker's presence as "this run finished", so it must not survive a prior run.
$status_file = escapeshellarg("/var/log/vormox/{$domain}-task.status");
$kickoff = "mkdir -p /var/log/vormox && "
        . "rm -f {$status_file} && "
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

$niceVerb = ['create' => 'setup', 'update' => 'update', 'delete' => 'removal'][$action];
echo json_encode([
    'success'    => true,
    'message'    => "Backend {$niceVerb} launched. Watch progress in the panel terminal (\"Backend Build & Task Progress\").",
    'panel_id'   => $panel_id,
    'service'    => $service,
    'log_path'   => "/var/log/vormox/{$domain}-task.log",
    'manage_url' => "manage_panel.php?id={$panel_id}",
]);
