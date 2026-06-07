<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';

$user_id = $_SESSION['user_id'];
$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$panel_id) { header("Location: panels.php"); exit; }

// --- SECURE FETCH: Ensure panel belongs to logged-in user ---
try {
    // ADDED: Fetching Reverse Proxy (rp_) fields so the UI knows if it exists
    $stmt = $pdo->prepare("
        SELECT p.*, 
               pd.be_service, pd.fe_service, pd.be_status, pd.fe_status,
               pd.be_server_ip, pd.be_ssh_user, pd.be_ssh_pass, pd.be_ssh_port,
               pd.fe_server_ip, pd.fe_ssh_user, pd.fe_ssh_pass, pd.fe_ssh_port,
               pd.rp_server_ip, pd.rp_service, pd.rp_ssh_port, pd.rp_ssh_user, pd.rp_ssh_pass,
               pd.db_server_ip, pd.db_name, pd.db_user, pd.db_pass 
        FROM user_panels p 
        LEFT JOIN panel_details pd ON p.id = pd.panel_id 
        WHERE p.id = :id AND p.user_id = :uid LIMIT 1
    ");
    $stmt->execute(['id' => $panel_id, 'uid' => $user_id]);
    $panel = $stmt->fetch();
    
    if (!$panel) { die("Panel not found or access denied."); }

    // --- FETCH USER FOR HEADER ---
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $user_id]);
    $user = $userStmt->fetch();
    
} catch (PDOException $e) { die("Database error."); }

$page_title = 'Manage ' . $panel['domain'];
$header_title = 'Control Panel';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .top-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 32px; }
    .service-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 48px; }
    @media(max-width: 1000px) { .top-grid, .service-grid { grid-template-columns: 1fr; } }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 32px; display: flex; flex-direction: column; }
    .card-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; margin-bottom: 24px; color: var(--text); display: flex; align-items: center; justify-content: space-between; }
    
    .data-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 14px; align-items: center; }
    .data-row:last-child { border-bottom: none; }
    .data-label { color: var(--text-muted); font-family: var(--font-mono); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
    .data-value { font-weight: 600; color: var(--text); }
    .data-mono { font-family: var(--font-mono); font-weight: 500; }

    .control-btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-family: var(--font-body); font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: 0.2s; margin-bottom: 12px; }
    .control-btn:disabled { opacity: 0.3; cursor: not-allowed; }
    
    .btn-start { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .btn-start:hover:not(:disabled) { background: var(--accent-green); color: #fff; box-shadow: 0 4px 15px rgba(34,211,238,0.3); }
    .btn-stop { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .btn-stop:hover:not(:disabled) { background: var(--accent-red); color: #fff; box-shadow: 0 4px 15px rgba(248,113,113,0.3); }
    .btn-restart { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .btn-restart:hover:not(:disabled) { background: var(--accent-orange); color: #fff; box-shadow: 0 4px 15px rgba(251,146,60,0.3); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); display: inline-block; letter-spacing: 0.05em; }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-offline { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-other { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .blur-pass { filter: blur(5px); cursor: pointer; transition: 0.2s; user-select: none; }
    .blur-pass:hover, .blur-pass.revealed { filter: blur(0); user-select: text; }

    .terminal-wrapper { background: #000; border: 1px solid var(--border-strong); border-radius: 12px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3); margin-top: 32px; }
    .terminal-header { background: #111; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; }
    .terminal-dots { display: flex; gap: 8px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .dot.r { background: #ff5f56; }
    .dot.y { background: #ffbd2e; }
    .dot.g { background: #27c93f; }
    .terminal-select { background: #000; color: #0f0; border: 1px solid #333; padding: 6px 12px; border-radius: 6px; font-family: var(--font-mono); font-size: 12px; outline: none; cursor: pointer; }
    .terminal-body { padding: 24px; height: 350px; overflow-y: auto; color: #0f0; font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.6; }
    .terminal-body pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
    .pulse { animation: pulse 1.5s infinite; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
</style>

<div class="content-area">
    
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= htmlspecialchars($panel['domain']) ?></h1>
            <p class="page-sub">Resource allocated: <?= $panel['nodes_count'] ?> Node(s)</p>
        </div>
        <a href="panels.php" class="btn-primary" style="text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Back to Panels</a>
    </div>

    <div class="top-grid">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-globe" style="color: var(--accent2);"></i> Account Limits & Billing</div>
            <div class="data-row"><span class="data-label">Domain</span><span class="data-value"><?= htmlspecialchars($panel['domain']) ?></span></div>
            <div class="data-row"><span class="data-label">Nodes</span><span class="data-value"><?= htmlspecialchars($panel['nodes_count']) ?></span></div>
            <div class="data-row"><span class="data-label">Expiry Date</span><span class="data-value data-mono"><?= $panel['expiry_date'] ? date('M j, Y', strtotime($panel['expiry_date'])) : 'Pending' ?></span></div>
            <div class="data-row"><span class="data-label">Billing Cycle</span><span class="data-value" style="text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', '-', $panel['billing_cycle'])) ?></span></div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-database" style="color: var(--accent-orange);"></i> Database Access</div>
            <?php if($panel['db_name']): ?>
                <div class="data-row"><span class="data-label">DB Host</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_server_ip']) ?></span></div>
                <div class="data-row"><span class="data-label">DB Name</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_name']) ?></span></div>
                <div class="data-row"><span class="data-label">DB User</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_user']) ?></span></div>
                <div class="data-row"><span class="data-label">DB Password</span><span class="data-value data-mono blur-pass" title="Click to reveal"><?= htmlspecialchars($panel['db_pass']) ?></span></div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">No database provisioned.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="service-grid">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-server" style="color: var(--accent-green);"></i> Backend Engine</div>
            <?php if($panel['be_server_ip']): 
                $isOnline = ($panel['be_status'] === 'online');
                $isOffline = ($panel['be_status'] === 'offline');
            ?>
                <div class="data-row">
                    <span class="data-label">Status</span>
                    <span id="be-status-badge" class="badge <?= $isOnline ? 'badge-active' : ($isOffline ? 'badge-offline' : 'badge-other') ?>">
                        <?= htmlspecialchars(strtoupper($panel['be_status'] ?? 'UNKNOWN')) ?>
                    </span>
                </div>
                <div class="data-row"><span class="data-label">Host IP</span><span class="data-value data-mono"><?= htmlspecialchars($panel['be_server_ip']) ?></span></div>
                
                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.1em;">Power Controls</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" onclick="runAction('start', 'be', this)" class="control-btn btn-start" style="margin: 0;" <?= $isOnline ? 'disabled' : '' ?>><i class="fa-solid fa-play"></i> Start</button>
                        <button type="button" onclick="runAction('stop', 'be', this)" class="control-btn btn-stop" style="margin: 0;" <?= $isOffline ? 'disabled' : '' ?>><i class="fa-solid fa-stop"></i> Stop</button>
                    </div>
                    <button type="button" onclick="runAction('restart', 'be', this)" class="control-btn btn-restart" style="margin-top: 8px;"><i class="fa-solid fa-rotate-right"></i> Restart</button>
                </div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">Provisioning in progress...</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-window-maximize" style="color: #3b82f6;"></i> Web Interface</div>
            <?php if($panel['fe_server_ip']): 
                $feOnline = ($panel['fe_status'] === 'online');
                $feOffline = ($panel['fe_status'] === 'offline');
            ?>
                <div class="data-row">
                    <span class="data-label">Status</span>
                    <span id="fe-status-badge" class="badge <?= $feOnline ? 'badge-active' : ($feOffline ? 'badge-offline' : 'badge-other') ?>">
                        <?= htmlspecialchars(strtoupper($panel['fe_status'] ?? 'UNKNOWN')) ?>
                    </span>
                </div>
                <div class="data-row"><span class="data-label">Host IP</span><span class="data-value data-mono"><?= htmlspecialchars($panel['fe_server_ip']) ?></span></div>
                
                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.1em;">Power Controls</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" onclick="runAction('start', 'fe', this)" class="control-btn btn-start" style="margin: 0;" <?= $feOnline ? 'disabled' : '' ?>><i class="fa-solid fa-play"></i> Start</button>
                        <button type="button" onclick="runAction('stop', 'fe', this)" class="control-btn btn-stop" style="margin: 0;" <?= $feOffline ? 'disabled' : '' ?>><i class="fa-solid fa-stop"></i> Stop</button>
                    </div>
                    <button type="button" onclick="runAction('restart', 'fe', this)" class="control-btn btn-restart" style="margin-top: 8px;"><i class="fa-solid fa-rotate-right"></i> Restart</button>
                </div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">Provisioning in progress...</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="terminal-wrapper">
        <div class="terminal-header">
            <div class="terminal-dots">
                <div class="dot r"></div><div class="dot y"></div><div class="dot g"></div>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <span class="pulse" id="liveIndicator" style="color: #0f0; font-family: var(--font-mono); font-size: 11px; display: none;">● LIVE</span>
                
                <select class="terminal-select" id="logSource">
                    <option value="">-- Access System Logs --</option>
                    
                    <?php if(!empty($panel['be_server_ip'])): ?>
                        <option value="be">Backend Daemon Logs (journalctl)</option>
                    <?php endif; ?>
                    
                    <?php if(!empty($panel['fe_server_ip'])): ?>
                        <option value="fe">Frontend Daemon Logs (journalctl)</option>
                    <?php endif; ?>
                    
                    <?php if(!empty($panel['rp_server_ip'])): ?>
                        <option value="" disabled>──────────────────────</option>
                        <option value="rp_access">Reverse Proxy: Access Logs</option>
                        <option value="rp_error">Reverse Proxy: Error Logs</option>
                    <?php endif; ?>
                </select>

            </div>
        </div>
        <div class="terminal-body" id="terminalBody">
            <pre id="logOutput">Select a log channel above to securely connect to your server instance...</pre>
        </div>
    </div>

</div>

<script>
const panelId = <?= json_encode($panel_id) ?>;

// Click to reveal passwords
document.querySelectorAll('.blur-pass').forEach(el => {
    el.addEventListener('click', () => { el.classList.toggle('revealed'); });
});

// Action Runner
function runAction(action, type, btn) {
    if (!confirm(`Are you sure you want to ${action.toUpperCase()} your ${type.toUpperCase()} server?`)) return;

    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Executing...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', action);
    formData.append('service_type', type);

    fetch(`ajax_service_handler.php?id=${panelId}`, { method: 'POST', body: formData })
        .then(async res => {
            if (!res.ok) throw new Error("HTTP error " + res.status);
            return await res.json();
        })
        .then(data => {
            alert(data.message || (data.status === 'success' ? 'Success' : 'Error'));
            if (data.status === 'success') {
                setTimeout(() => location.reload(), 1500);
            } else {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert('Execution failed. Please contact support.');
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}

// Log Polling
document.addEventListener('DOMContentLoaded', () => {
    let pollInterval;
    const logSource = document.getElementById('logSource');
    const logOutput = document.getElementById('logOutput');
    const terminalBody = document.getElementById('terminalBody');
    const liveIndicator = document.getElementById('liveIndicator');

    function fetchLogs() {
        const type = logSource.value;
        if (!type) return;

        fetch(`ajax_service_handler.php?id=${panelId}&ajax=logs&type=${type}`)
            .then(res => res.json())
            .then(data => {
                if (data.log) {
                    logOutput.textContent = data.log;
                    terminalBody.scrollTop = terminalBody.scrollHeight;
                }
            }).catch(() => {});
    }

    logSource.addEventListener('change', (e) => {
        clearInterval(pollInterval);
        if (e.target.value === "") {
            logOutput.textContent = "Connection closed.";
            liveIndicator.style.display = 'none';
        } else {
            logOutput.textContent = "Establishing secure SSH stream...\n";
            liveIndicator.style.display = 'block';
            fetchLogs();
            pollInterval = setInterval(fetchLogs, 4000);
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
