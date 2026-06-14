<?php
// admin/setup_frontend.php
//
// Three actions for the Next.js frontend, all dispatched over SSH:
//   action=create  → install Node 20, clone, install deps, build, systemd up
//   action=update  → stop, wipe dir, re-clone, re-build, restart
//   action=delete  → stop + disable + remove unit file + remove project dir
//
// All long-running work happens in a detached bash script on the FE host,
// writing progress to /var/log/vormox/<domain>-fe-task.log so admins can
// tail it from the manage_panel.php terminal.
//
// fe_service in panel_details is auto-populated on create — derived from
// the panel's domain if the admin didn't pre-fill it. That value is what
// future start/stop/restart actions key off.

session_start();
require_once '../config.php';

header('Content-Type: application/json');

// --- Admin auth (same boilerplate as the rest of /admin) ---
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

// --- Load panel + details ---
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.domain, p.status,
               pd.fe_server_ip, pd.fe_ssh_port, pd.fe_ssh_user, pd.fe_ssh_pass,
               pd.fe_service,   pd.fe_git_url, pd.fe_git_user, pd.fe_git_pass
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
    echo json_encode(['success' => false, 'message' => 'Panel not found, or details have not been saved yet.']); exit;
}

// --- Required fields depend on the action ---
$required = ['fe_server_ip', 'fe_ssh_user', 'fe_ssh_pass'];
if (in_array($action, ['create', 'update'], true)) {
    // Only create/update need the repo URL; delete just needs to reach the box.
    $required[] = 'fe_git_url';
}
$labels = [
    'fe_server_ip' => 'Frontend Server IP',
    'fe_ssh_user'  => 'Frontend SSH User',
    'fe_ssh_pass'  => 'Frontend SSH Password',
    'fe_git_url'   => 'Frontend Git URL',
];
$missing = [];
foreach ($required as $col) {
    if (empty($panel[$col])) $missing[] = $labels[$col] ?? $col;
}
if (empty($panel['domain'])) $missing[] = 'Panel domain';
if ($missing) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
    exit;
}

// --- Derive (or reuse) the systemd service name. Stored back to DB on
//     create so start/stop/restart know which unit to control. ---
$service = trim((string) $panel['fe_service']);
$service = preg_replace('/\.service$/i', '', $service);                 // strip suffix if admin typed it
$service = preg_replace('/[^A-Za-z0-9._-]/', '', $service);              // sanitize
if ($service === '') {
    // Default: fe-<sanitized-domain>
    $service = 'fe-' . preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($panel['domain']));
    $service = trim($service, '-') ?: ('fe-panel-' . $panel_id);
}

// --- Build the auth-injected git URL (only relevant for create/update) ---
$git_url = $panel['fe_git_url'];
if (!empty($panel['fe_git_user']) && in_array($action, ['create', 'update'], true)) {
    $auth = rawurlencode($panel['fe_git_user']);
    if (!empty($panel['fe_git_pass'])) {
        $auth .= ':' . rawurlencode($panel['fe_git_pass']);
    }
    $git_url = preg_replace('|^(https?://)|i', '\\1' . $auth . '@', $git_url, 1);
}

$ssh_port = (int) ($panel['fe_ssh_port'] ?: 22);
$domain   = $panel['domain'];

// ---------------------------------------------------------------------------
// Pick the right bash script for the requested action.
// All three log to the same per-domain file so the admin sees the full history.
// ---------------------------------------------------------------------------
$header = <<<HDR
#!/bin/bash
set -u

DOMAIN="{$domain}"
SERVICE="{$service}"
APP_DIR="/root/GetWebUp"
WORK_DIR="\$APP_DIR/app"
LOG_DIR="/var/log/vormox"
LOG="\${LOG_DIR}/\${DOMAIN}-fe-task.log"

mkdir -p "\$LOG_DIR" /var/lock
touch "\$LOG"
chmod 600 "\$LOG"

ts() { date '+[%Y-%m-%d %H:%M:%S]'; }
log() { echo "\$(ts) \$*" >> "\$LOG"; }

# Concurrency guard — /root/GetWebUp is hardcoded so only one frontend job
# can safely touch the FE host at a time. flock -n bails immediately if a
# sibling create/update/delete is already running.
exec 200>/var/lock/vormox-frontend.lock
if ! flock -n 200; then
    log "Another frontend job is already running on this host. Exiting."
    exit 0
fi
fail() {
    log "FATAL: \$*"
    log "==========================================="
    log "FAILURE: Frontend {$action} aborted for \$DOMAIN"
    log "==========================================="
    echo "error" > "\${LOG_DIR}/\${DOMAIN}-fe-task.status" 2>/dev/null || true
    exit 1
}
run() {
    log "+ \$*"
    if ! eval "\$* >> \\"\$LOG\\" 2>&1"; then
        fail "Step failed: \$*"
    fi
}
HDR;

if ($action === 'create') {
    $body = <<<SCRIPT
log "==========================================="
log "Starting frontend setup for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$WORK_DIR"
log "==========================================="

# Step 1: Node 20 + git via NodeSource
log ""
log ">>> Step 1/6: install Node 20 + git"
export DEBIAN_FRONTEND=noninteractive
run "apt-get update -y"
run "apt-get install -y curl ca-certificates git"
if ! command -v node >/dev/null 2>&1 || [ "\$(node -v | cut -d. -f1)" != "v20" ]; then
    run "curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"
    run "apt-get install -y nodejs"
else
    log "Node already at \$(node -v)"
fi

# Step 2: clone (clean slate)
log ""
log ">>> Step 2/6: cloning frontend repo"
if [ -d "\$APP_DIR" ]; then
    log "Removing existing \$APP_DIR"
    rm -rf "\$APP_DIR"
fi
run "git clone '{$git_url}' '\$APP_DIR'"

if [ ! -d "\$WORK_DIR" ]; then
    fail "Expected \$WORK_DIR (app subdir) after clone, but it does not exist"
fi

# Step 3: write .env
log ""
log ">>> Step 3/6: writing \$WORK_DIR/.env"
cat > "\$WORK_DIR/.env" <<ENVF
NEXT_PUBLIC_LOCAL_API_URL="https://\$DOMAIN"
NODE_TLS_REJECT_UNAUTHORIZED="0"
ENVF
chmod 600 "\$WORK_DIR/.env"
log "wrote .env"

# Step 4: npm install
log ""
log ">>> Step 4/6: npm install (this can take several minutes)"
cd "\$WORK_DIR" || fail "cd \$WORK_DIR"
run "npm install --no-audit --no-fund"

# Step 5: npm run build
log ""
log ">>> Step 5/6: npm run build"
run "npm run build"

# Step 6: systemd unit + enable + start + wait
log ""
log ">>> Step 6/6: writing /etc/systemd/system/\${SERVICE}.service"
cat > "/etc/systemd/system/\${SERVICE}.service" <<UNIT
[Unit]
Description=Vormox Frontend (\$DOMAIN)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=\$WORK_DIR
ExecStart=/usr/bin/npm start
Restart=on-failure
RestartSec=5
Environment=NODE_ENV=production
Environment=PORT=5173
EnvironmentFile=\$WORK_DIR/.env

[Install]
WantedBy=multi-user.target
UNIT

run "systemctl daemon-reload"
run "systemctl enable \$SERVICE"
run "systemctl restart \$SERVICE"

log "Waiting for \$SERVICE to become healthy (up to 3 min)…"
HEALTHY=0
for i in \$(seq 1 36); do
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
    log "SUCCESS: Frontend setup complete for \$DOMAIN"
    log "Service: \$SERVICE is active on port 5173"
    log "==========================================="
    echo "installed" > "\${LOG_DIR}/\${DOMAIN}-fe-task.status" 2>/dev/null || true
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
log "Updating frontend for \$DOMAIN"
log "==========================================="

# Stop the running service first so the rm doesn't race file handles
log ""
log ">>> Step 1/5: stopping \$SERVICE"
systemctl stop "\$SERVICE" >> "\$LOG" 2>&1 || log "  (service was not running)"

log ""
log ">>> Step 2/5: wiping \$APP_DIR and re-cloning"
rm -rf "\$APP_DIR"
run "git clone '{$git_url}' '\$APP_DIR'"

if [ ! -d "\$WORK_DIR" ]; then
    fail "Expected \$WORK_DIR after clone, but it does not exist"
fi

log ""
log ">>> Step 3/5: re-writing .env"
cat > "\$WORK_DIR/.env" <<ENVF
NEXT_PUBLIC_LOCAL_API_URL="https://\$DOMAIN"
NODE_TLS_REJECT_UNAUTHORIZED="0"
ENVF
chmod 600 "\$WORK_DIR/.env"

log ""
log ">>> Step 4/5: npm install + build"
cd "\$WORK_DIR" || fail "cd \$WORK_DIR"
run "npm install --no-audit --no-fund"
run "npm run build"

log ""
log ">>> Step 5/5: starting \$SERVICE"
run "systemctl daemon-reload"
run "systemctl restart \$SERVICE"

# Quick health probe (10 sec is enough — Next is faster to start than Java)
sleep 5
if systemctl is-active --quiet "\$SERVICE"; then
    log "==========================================="
    log "SUCCESS: Frontend updated for \$DOMAIN"
    log "==========================================="
    echo "installed" > "\${LOG_DIR}/\${DOMAIN}-fe-task.status" 2>/dev/null || true
    exit 0
else
    journalctl -u "\$SERVICE" -n 40 --no-pager >> "\$LOG" 2>&1 || true
    fail "Service \$SERVICE failed to restart"
fi
SCRIPT;

} else { // delete
    $body = <<<SCRIPT
log "==========================================="
log "Deleting frontend for \$DOMAIN"
log "Service: \$SERVICE   |   Working dir: \$APP_DIR"
log "==========================================="

log ""
log ">>> Step 1/4: stopping \$SERVICE"
systemctl stop "\$SERVICE" >> "\$LOG" 2>&1 || log "  (already stopped)"

log ">>> Step 2/4: disabling \$SERVICE"
systemctl disable "\$SERVICE" >> "\$LOG" 2>&1 || log "  (was not enabled)"

log ">>> Step 3/4: removing /etc/systemd/system/\${SERVICE}.service"
rm -f "/etc/systemd/system/\${SERVICE}.service"
systemctl daemon-reload >> "\$LOG" 2>&1 || true

log ">>> Step 4/4: removing \$APP_DIR"
rm -rf "\$APP_DIR"

log "==========================================="
log "SUCCESS: Frontend removed for \$DOMAIN"
log "==========================================="
echo "removed" > "\${LOG_DIR}/\${DOMAIN}-fe-task.status" 2>/dev/null || true
exit 0
SCRIPT;
}

$script = $header . "\n\n" . $body;

// --- Ship + execute over SSH (same pattern as setup_backend.php) ---
if (!function_exists('ssh2_connect')) {
    echo json_encode(['success' => false, 'message' => 'PHP ssh2 extension missing on web server.']); exit;
}
$errno = 0; $errstr = '';
$probe = @stream_socket_client("tcp://{$panel['fe_server_ip']}:{$ssh_port}", $errno, $errstr, 5);
if (!$probe) {
    echo json_encode(['success' => false, 'message' => "Frontend host unreachable: {$panel['fe_server_ip']}:{$ssh_port} ({$errstr})"]);
    exit;
}
fclose($probe);

$conn = @ssh2_connect($panel['fe_server_ip'], $ssh_port);
if (!$conn) { echo json_encode(['success' => false, 'message' => 'SSH handshake failed.']); exit; }
if (!@ssh2_auth_password($conn, $panel['fe_ssh_user'], $panel['fe_ssh_pass'])) {
    echo json_encode(['success' => false, 'message' => 'SSH authentication failed.']); exit;
}

// Persist the service name on create so future start/stop/restart find it.
// (For update + delete we use whatever's already stored — no overwrite.)
if ($action === 'create') {
    try {
        $pdo->prepare("UPDATE panel_details SET fe_service = ?, fe_status = 'installing' WHERE panel_id = ?")
            ->execute([$service, $panel_id]);
    } catch (PDOException $e) { /* non-fatal */ }
}

// Concurrency precheck — fail fast with a clear message if a sibling FE
// job is already in flight. The bash script itself re-acquires the lock
// as a backup safety net.
$precheck = "mkdir -p /var/lock && flock -n /var/lock/vormox-frontend.lock -c true && echo OK || echo LOCKED";
$pstream = @ssh2_exec($conn, $precheck);
if ($pstream) {
    stream_set_blocking($pstream, true);
    $presult = trim((string) @stream_get_contents(@ssh2_fetch_stream($pstream, SSH2_STREAM_STDIO)));
    @fclose($pstream);
    if (stripos($presult, 'LOCKED') !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'A frontend job is already running on this host. Watch the task log; if it looks stuck, wait a few minutes or remove /var/lock/vormox-frontend.lock manually.',
        ]);
        exit;
    }
}

$b64 = base64_encode($script);
$script_path = "/tmp/vormox-fe-{$action}-{$panel_id}.sh";
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

$niceVerb = ['create' => 'created', 'update' => 'updated', 'delete' => 'removed'][$action];
echo json_encode([
    'success'    => true,
    'message'    => "Frontend {$niceVerb} job launched. Watch progress in the panel terminal.",
    'panel_id'   => $panel_id,
    'service'    => $service,
    'log_path'   => "/var/log/vormox/{$domain}-fe-task.log",
    'manage_url' => "manage_panel.php?id={$panel_id}",
]);
