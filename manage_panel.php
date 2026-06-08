<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';
require_once 'includes/notifications.php';
require_once 'includes/pricing.php';

$user_id = $_SESSION['user_id'];
$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$panel_id) { header("Location: panels.php"); exit; }

$upgrade_error = '';
$upgrade_success = '';

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

// Any unpaid invoice attached to this panel (renewal, original order, pending upgrade, …)
// is enough to block a new upgrade attempt.
$pendStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE panel_id = ? AND status = 'Unpaid'");
$pendStmt->execute([$panel_id]);
$hasPendingInvoice = ((int) $pendStmt->fetchColumn()) > 0;

// --- HANDLE PLAN UPGRADE (add more nodes) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade_plan'])) {
    csrf_require();
    $new_nodes = filter_input(INPUT_POST, 'new_nodes', FILTER_VALIDATE_INT);
    $current_nodes = (int) $panel['nodes_count'];

    if (!$new_nodes || $new_nodes <= $current_nodes) {
        $upgrade_error = "New node count must be greater than your current ({$current_nodes}).";
    } elseif ($new_nodes > VORMOX_MAX_NODES) {
        $upgrade_error = "For more than " . VORMOX_MAX_NODES . " nodes, please open a support ticket with the enterprise team.";
    } elseif ($panel['status'] !== 'active') {
        $upgrade_error = "Only active panels can be upgraded.";
    } elseif (empty($panel['expiry_date']) || strtotime($panel['expiry_date']) <= time()) {
        $upgrade_error = "Your plan must be active with a future expiry date to upgrade.";
    } elseif ($hasPendingInvoice) {
        $upgrade_error = "Already invoice pending.";
    } else {
        $cycle_months    = vormox_cycle_months($panel['billing_cycle']);
        $new_monthly     = $new_nodes * vormox_price_per_node($new_nodes);
        $old_monthly     = $current_nodes * vormox_price_per_node($current_nodes);
        $monthly_diff    = max(0, $new_monthly - $old_monthly);
        $days_remaining  = max(0, (int) ceil((strtotime($panel['expiry_date']) - time()) / 86400));
        // Prorated: monthly difference * fraction of one month remaining
        $prorated = round($monthly_diff * ($days_remaining / 30), 2);

        if ($prorated <= 0) {
            $upgrade_error = "Calculated upgrade cost is zero. Try a larger jump or wait for renewal.";
        } else {
            try {
                $pdo->beginTransaction();

                $invoice_number = 'UPG-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $due_date = date('Y-m-d', strtotime('+3 days'));

                $pdo->prepare("
                    INSERT INTO invoices (user_id, panel_id, invoice_number, amount, type, status, due_date, created_at)
                    VALUES (?, ?, ?, ?, 'renew', 'Unpaid', ?, NOW())
                ")->execute([$user_id, $panel_id, $invoice_number, $prorated, $due_date]);

                $pdo->prepare("UPDATE user_panels SET pending_nodes_count = ? WHERE id = ?")
                    ->execute([$new_nodes, $panel_id]);

                $pdo->commit();

                try {
                    $u = $pdo->prepare("SELECT email, first_name FROM users WHERE id = ? LIMIT 1");
                    $u->execute([$user_id]);
                    if ($row = $u->fetch()) {
                        notify_invoice_created(
                            $row['email'],
                            $row['first_name'],
                            $invoice_number,
                            $prorated,
                            $due_date,
                            "Plan upgrade for <strong>{$panel['domain']}</strong> to {$new_nodes} nodes. Nodes will be added once the invoice is paid."
                        );
                    }
                } catch (PDOException $e) { error_log("Upgrade email lookup failed: " . $e->getMessage()); }

                header("Location: view-invoice.php?id=" . urlencode($invoice_number));
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $upgrade_error = "Failed to generate upgrade invoice. Please try again.";
            }
        }
    }
    // Re-fetch panel so we display the latest state
    $stmt->execute(['id' => $panel_id, 'uid' => $user_id]);
    $panel = $stmt->fetch();
}

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
            <div class="card-title">
                <span><i class="fa-solid fa-globe" style="color: var(--accent2);"></i> Account Limits & Billing</span>
                <?php if (!$hasPendingInvoice && $panel['status'] === 'active'): ?>
                    <button type="button" id="openUpgradeBtn" style="background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.3); padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-arrow-up-right-dots"></i> Upgrade</button>
                <?php elseif ($hasPendingInvoice && $panel['status'] === 'active'): ?>
                    <span title="Pay the existing invoice before requesting an upgrade." style="background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: not-allowed;"><i class="fa-solid fa-lock"></i> Upgrade locked</span>
                <?php endif; ?>
            </div>

            <?php if ($upgrade_error): ?>
                <div style="background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.2); color: var(--accent-red); padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($upgrade_error) ?>
                </div>
            <?php endif; ?>

            <?php if ($hasPendingInvoice): ?>
                <div style="background: rgba(251,146,60,0.08); border: 1px solid rgba(251,146,60,0.3); color: var(--accent-orange); padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;">
                    <i class="fa-solid fa-hourglass-half"></i>
                    <?php if (!empty($panel['pending_nodes_count'])): ?>
                        Upgrade to <?= (int)$panel['pending_nodes_count'] ?> nodes pending payment.
                    <?php else: ?>
                        Invoice pending payment on this panel.
                    <?php endif; ?>
                    <a href="invoices.php" style="color: var(--accent-orange); font-weight: 600; margin-left: 6px;">View &rarr;</a>
                </div>
            <?php endif; ?>

            <div class="data-row"><span class="data-label">Domain</span><span class="data-value"><?= htmlspecialchars($panel['domain']) ?></span></div>
            <div class="data-row"><span class="data-label">Nodes</span><span class="data-value"><?= htmlspecialchars($panel['nodes_count']) ?><?php if (!empty($panel['pending_nodes_count'])): ?> <span style="color: var(--text-dim); font-weight: 400;">→ <?= (int)$panel['pending_nodes_count'] ?></span><?php endif; ?></span></div>
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
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('ajax_action', action);
    formData.append('service_type', type);

    fetch(`ajax_service_handler.php?id=${panelId}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: formData
    })
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
    let logInFlight = false;
    let logFailures = 0;
    const LOG_MAX_FAILURES  = 3;
    const LOG_FETCH_TIMEOUT = 12000;

    const logSource     = document.getElementById('logSource');
    const logOutput     = document.getElementById('logOutput');
    const terminalBody  = document.getElementById('terminalBody');
    const liveIndicator = document.getElementById('liveIndicator');

    function stopLogStream(reason) {
        clearInterval(pollInterval);
        pollInterval = null;
        liveIndicator.style.display = 'none';
        logOutput.textContent =
            `[!] Log stream stopped after ${logFailures} consecutive errors.\n` +
            `[!] Last error: ${reason}\n\n` +
            `Pick a log source again to retry.`;
    }

    function fetchLogs() {
        const type = logSource.value;
        if (!type) return;
        if (logInFlight) return; // skip this tick if a previous fetch is still in flight

        logInFlight = true;
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), LOG_FETCH_TIMEOUT);

        fetch(`ajax_service_handler.php?id=${panelId}&ajax=logs&type=${encodeURIComponent(type)}`, {
            signal: controller.signal
        })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then(data => {
                logFailures = 0;
                if (data.log !== undefined) {
                    logOutput.textContent = data.log;
                    terminalBody.scrollTop = terminalBody.scrollHeight;
                }
            })
            .catch(err => {
                logFailures++;
                const reason = err.name === 'AbortError'
                    ? `request timed out after ${LOG_FETCH_TIMEOUT / 1000}s`
                    : (err.message || 'fetch failed');
                if (logFailures >= LOG_MAX_FAILURES) {
                    stopLogStream(reason);
                }
            })
            .finally(() => {
                clearTimeout(timeoutId);
                logInFlight = false;
            });
    }

    logSource.addEventListener('change', (e) => {
        clearInterval(pollInterval);
        pollInterval = null;
        logInFlight  = false;
        logFailures  = 0;

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

<!-- Upgrade Plan Modal -->
<?php if (!$hasPendingInvoice && $panel['status'] === 'active'): ?>
<div id="upgradeModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 480px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div style="font-family: var(--font-head); font-size: 22px; font-weight: 700;">Upgrade Plan</div>
            <button type="button" id="closeUpgradeBtn" style="background: transparent; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">
            Add more nodes to <strong style="color: var(--text);"><?= htmlspecialchars($panel['domain']) ?></strong>.
            You'll be charged a prorated amount for the remaining days of your current
            <strong style="color: var(--text); text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', '-', $panel['billing_cycle'])) ?></strong> cycle.
        </p>

        <form method="POST" action="manage_panel.php?id=<?= (int)$panel_id ?>">
            <?= csrf_field() ?>
            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                <label style="font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase;">Current Nodes</label>
                <input type="text" value="<?= (int)$panel['nodes_count'] ?>" disabled style="width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; color: var(--text-muted); font-family: var(--font-mono); font-size: 16px;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                <label for="new_nodes" style="font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase;">New Node Count</label>
                <input type="number" id="new_nodes" name="new_nodes" min="<?= (int)$panel['nodes_count'] + 1 ?>" max="<?= VORMOX_MAX_NODES ?>" value="<?= (int)$panel['nodes_count'] + 1 ?>" required style="width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 16px; outline: none;">
            </div>

            <div id="upgradePreview" style="background: rgba(59,130,246,0.05); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px; padding: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">Prorated upgrade cost</div>
                    <div id="upgradeBreakdown" style="font-size: 11px; color: var(--text-dim); font-family: var(--font-mono);"></div>
                </div>
                <div id="upgradeCost" style="font-family: var(--font-head); font-size: 22px; font-weight: 700; color: var(--accent2);">$0.00</div>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" id="cancelUpgradeBtn" style="padding: 12px 20px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-weight: 600;">Cancel</button>
                <button type="submit" name="upgrade_plan" class="btn-primary" style="padding: 12px 24px; border: none; border-radius: 8px; background: var(--accent2); color: white; cursor: pointer; font-weight: 600;"><i class="fa-solid fa-file-invoice-dollar"></i> Generate Invoice</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var currentNodes = <?= (int)$panel['nodes_count'] ?>;
    var cycleMonths  = <?= vormox_cycle_months($panel['billing_cycle']) ?>;
    var daysRemaining = <?= !empty($panel['expiry_date']) ? max(0, (int) ceil((strtotime($panel['expiry_date']) - time()) / 86400)) : 0 ?>;

    function tier(n) { return (n >= 11) ? 8 : ((n >= 5) ? 9 : 10); }

    function compute() {
        var n = parseInt(document.getElementById('new_nodes').value, 10) || 0;
        if (n <= currentNodes) {
            document.getElementById('upgradeCost').textContent = '$0.00';
            document.getElementById('upgradeBreakdown').textContent = 'Must exceed current nodes';
            return;
        }
        var newMonthly = n * tier(n);
        var oldMonthly = currentNodes * tier(currentNodes);
        var diff = Math.max(0, newMonthly - oldMonthly);
        var cost = +(diff * (daysRemaining / 30)).toFixed(2);
        document.getElementById('upgradeCost').textContent = '$' + cost.toFixed(2);
        document.getElementById('upgradeBreakdown').textContent =
            '+$' + diff.toFixed(2) + '/mo  ×  ' + daysRemaining + ' day' + (daysRemaining === 1 ? '' : 's') + ' left';
    }

    var modal = document.getElementById('upgradeModal');
    document.getElementById('openUpgradeBtn').addEventListener('click', function () {
        modal.style.display = 'flex';
        compute();
    });
    function close() { modal.style.display = 'none'; }
    document.getElementById('closeUpgradeBtn').addEventListener('click', close);
    document.getElementById('cancelUpgradeBtn').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.getElementById('new_nodes').addEventListener('input', compute);
})();
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
