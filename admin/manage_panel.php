<?php
session_start();
require_once '../config.php'; 

// --- SECURITY BOILERPLATE ---
$user_ip = $_SERVER['REMOTE_ADDR'];
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    if ($countStmt->fetchColumn() > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) { header("Location: ../dashboard.php"); exit; }
    }
} catch (PDOException $e) { die("Security verification failed."); }

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) { header("Location: login.php"); exit; }

$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$panel_id) { header("Location: panels.php"); exit; }

$current_page = basename($_SERVER['PHP_SELF']);
try {
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
} catch (PDOException $e) { $pendingOrdersCount = 0; }

// --- FETCH PANEL & INFRASTRUCTURE DATA ---
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email, 
               pd.be_service, pd.fe_service, pd.be_status, pd.fe_status,
               pd.be_server_ip, pd.be_ssh_port, pd.be_ssh_user, pd.be_ssh_pass,
               pd.be_git_url, pd.fe_server_ip, pd.db_name, pd.db_user, pd.status as details_status 
        FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN panel_details pd ON p.id = pd.panel_id 
        WHERE p.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $panel_id]);
    $panel = $stmt->fetch();
    if (!$panel) { die("Panel not found."); }
} catch (PDOException $e) { die("Database error."); }

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

$page_title = 'Manage: ' . $panel['domain'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    const initialTheme = savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', initialTheme);
  </script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root, [data-theme="dark"] { --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35; --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3); --accent: #a78bfa; --accent2: #8b5cf6; --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68; --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace; --font-body: 'Instrument Sans', sans-serif; --accent-glow: rgba(139,92,246,0.35); --accent-red: #f87171; --accent-green: #22d3ee; --accent-orange: #fb923c; }
    [data-theme="light"] { --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0; --border: #e2e8f0; --border-strong: #cbd5e1; --accent: #7c3aed; --accent2: #6d28d9; --text: #0f172a; --text-muted: #475569; --text-dim: #64748b; --accent-glow: rgba(124,58,237,0.15); --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626; }
    
    body { background: var(--bg); color: var(--text); font-family: var(--font-body); display: flex; min-height: 100vh; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    aside { width: 260px; background: rgba(5,8,16,.95); border-right: 1px solid var(--border); padding: 24px; display: flex; flex-direction: column; z-index: 10; flex-shrink: 0; transition: background 0.3s; }
    [data-theme="light"] aside { background: var(--bg); }
    
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 48px; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    
    .nav-label { margin: 24px 0 8px 16px; font-size: 11px; font-family: var(--font-mono); color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.2s; margin-bottom: 4px; }
    .nav-item i { width: 20px; text-align: center; }
    .nav-item:hover { background: rgba(139,92,246,.08); color: var(--text); }
    .nav-item.active { background: var(--accent2); color: #fff; box-shadow: 0 4px 12px rgba(139,92,246,.3); }
    .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 24px; }

    main { flex: 1; display: flex; flex-direction: column; position: relative; height: 100vh; overflow-y: auto; }
    .grid-bg { position: absolute; inset: 0; pointer-events: none; z-index: 0; background-image: linear-gradient(var(--border) 1px,transparent 1px), linear-gradient(90deg,var(--border) 1px,transparent 1px); background-size: 60px 60px; }
    
    header { padding: 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 99; background: rgba(5,8,16,.65); backdrop-filter: blur(20px); position: sticky; top: 0; transition: background 0.3s; }
    [data-theme="light"] header { background: rgba(255,255,255,0.8); }
    .header-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    
    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    [data-theme="dark"] .fa-moon { display: none; } [data-theme="light"] .fa-sun { display: none; }

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; }
    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; margin-bottom: 24px; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }
    
    .top-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 32px; }
    .service-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 48px; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 32px; transition: background 0.3s, border-color 0.3s; display: flex; flex-direction: column; }
    .card-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; margin-bottom: 24px; color: var(--text); display: flex; align-items: center; gap: 10px; }
    
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
    
    .btn-create { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); margin-top: 16px; }
    .btn-create:hover:not(:disabled) { background: #10b981; color: #fff; box-shadow: 0 4px 15px rgba(16,185,129,0.3); }
    
    .btn-update { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.2); margin-top: 16px; }
    .btn-update:hover:not(:disabled) { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    
    .btn-remove { background: transparent; color: var(--accent-red); border: 1px dashed var(--accent-red); margin-top: 8px; }
    .btn-remove:hover:not(:disabled) { background: rgba(248,113,113,0.1); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); display: inline-block; letter-spacing: 0.05em; }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-offline { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-other { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .terminal-wrapper { background: #000; border: 1px solid #333; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
    .terminal-header { background: #1a1a1a; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; }
    .terminal-dots { display: flex; gap: 8px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .dot.r { background: #ff5f56; }
    .dot.y { background: #ffbd2e; }
    .dot.g { background: #27c93f; }
    .terminal-select { background: #000; color: #0f0; border: 1px solid #333; padding: 6px 12px; border-radius: 6px; font-family: var(--font-mono); font-size: 12px; outline: none; cursor: pointer; }
    .terminal-body { padding: 24px; height: 400px; overflow-y: auto; color: #0f0; font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.6; position: relative; }
    .terminal-body pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
    .pulse { animation: pulse 1.5s infinite; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }

    #toast-container { position: fixed; bottom: 32px; right: 32px; z-index: 9999; display: flex; flex-direction: column; gap: 12px; }
    .toast { padding: 16px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 12px; color: var(--text); box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: slideIn 0.3s ease forwards; min-width: 300px; font-family: var(--font-body); background: var(--surface); }
    .toast.success { border: 1px solid rgba(34,211,238,0.3); border-left: 4px solid var(--accent-green); }
    .toast.error { border: 1px solid rgba(248,113,113,0.3); border-left: 4px solid var(--accent-red); }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
  </style>
</head>
<body>

<div id="toast-container"></div>

<aside>
  <a href="index.php" class="logo"><div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>Vormox <span>Admin</span></a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if($pendingOrdersCount > 0): ?><span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span><?php endif; ?>
    </a>
    <a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item active"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Global Settings</a>
  </nav>
  <div class="sidebar-footer">
    <?php if($admin): ?><div style="padding: 0 16px 16px; font-size: 13px; color: var(--text-muted);">Logged in as<br><strong style="color: var(--text);"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong></div><?php endif; ?>
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);"><i class="fa-solid fa-arrow-right-from-bracket"></i> End Session</a>
  </div>
</aside>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title">Service Control Plane</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <a href="panels.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Panels</a>

    <div class="top-grid">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-globe" style="color: var(--accent2);"></i> Panel Information</div>
            <div class="data-row"><span class="data-label">Domain</span><span class="data-value"><?= htmlspecialchars($panel['domain']) ?></span></div>
            <div class="data-row"><span class="data-label">Client Name</span><span class="data-value"><?= htmlspecialchars($panel['first_name'] . ' ' . $panel['last_name']) ?></span></div>
            <div class="data-row"><span class="data-label">Status</span><span class="badge <?= $panel['status'] == 'active' ? 'badge-active' : 'badge-other' ?>"><?= htmlspecialchars(str_replace('_', ' ', $panel['status'])) ?></span></div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-database" style="color: var(--accent-orange);"></i> Database Details</div>
            <?php if($panel['db_name']): ?>
                <div class="data-row"><span class="data-label">DB Name</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_name']) ?></span></div>
                <div class="data-row"><span class="data-label">DB User</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_user']) ?></span></div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">No database provisioned.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="service-grid">
        
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-server" style="color: var(--accent-green);"></i> Backend Infrastructure</div>
            <?php if($panel['be_service']): 
                $isOnline = ($panel['be_status'] === 'online');
                $isOffline = ($panel['be_status'] === 'offline');
            ?>
                <div class="data-row">
                    <span class="data-label">Current Status</span>
                    <span id="be-status-badge" class="badge <?= $isOnline ? 'badge-active' : ($isOffline ? 'badge-offline' : 'badge-other') ?>">
                        <?= htmlspecialchars(strtoupper($panel['be_status'] ?? 'UNKNOWN')) ?>
                    </span>
                </div>
                <div class="data-row"><span class="data-label">Service Name</span><span class="data-value data-mono"><?= htmlspecialchars($panel['be_service']) ?></span></div>
                
                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.1em;">Daemon Controls</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" onclick="runAction('start', 'be', this)" class="control-btn btn-start" style="margin: 0;" <?= $isOnline ? 'disabled' : '' ?>><i class="fa-solid fa-play"></i> Start</button>
                        <button type="button" onclick="runAction('stop', 'be', this)" class="control-btn btn-stop" style="margin: 0;" <?= $isOffline ? 'disabled' : '' ?>><i class="fa-solid fa-stop"></i> Stop</button>
                    </div>
                    <button type="button" onclick="runAction('restart', 'be', this)" class="control-btn btn-restart" style="margin-top: 8px;"><i class="fa-solid fa-rotate-right"></i> Soft Restart</button>
                </div>

                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.1em;">Build & Deployment</div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" onclick="runAction('create', 'be', this)" class="control-btn btn-create" style="margin: 0;"><i class="fa-solid fa-hammer"></i> Create Backend</button>
                        <button type="button" onclick="runAction('update', 'be', this)" class="control-btn btn-update" style="margin: 0;"><i class="fa-brands fa-git-alt"></i> Update Code</button>
                    </div>
                    
                    <button type="button" onclick="runAction('remove', 'be', this)" class="control-btn btn-remove"><i class="fa-solid fa-trash-can"></i> Delete Backend</button>
                </div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">No backend mapped.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-window-maximize" style="color: #3b82f6;"></i> Frontend Service</div>
            <?php if($panel['fe_service']): 
                $feOnline = ($panel['fe_status'] === 'online');
                $feOffline = ($panel['fe_status'] === 'offline');
            ?>
                <div class="data-row">
                    <span class="data-label">Current Status</span>
                    <span id="fe-status-badge" class="badge <?= $feOnline ? 'badge-active' : ($feOffline ? 'badge-offline' : 'badge-other') ?>">
                        <?= htmlspecialchars(strtoupper($panel['fe_status'] ?? 'UNKNOWN')) ?>
                    </span>
                </div>
                <div class="data-row"><span class="data-label">Service Name</span><span class="data-value data-mono"><?= htmlspecialchars($panel['fe_service']) ?></span></div>
                
                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.1em;">Daemon Controls</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" onclick="runAction('start', 'fe', this)" class="control-btn btn-start" style="margin: 0;" <?= $feOnline ? 'disabled' : '' ?>><i class="fa-solid fa-play"></i> Start</button>
                        <button type="button" onclick="runAction('stop', 'fe', this)" class="control-btn btn-stop" style="margin: 0;" <?= $feOffline ? 'disabled' : '' ?>><i class="fa-solid fa-stop"></i> Stop</button>
                    </div>
                    <button type="button" onclick="runAction('restart', 'fe', this)" class="control-btn btn-restart" style="margin-top: 8px;"><i class="fa-solid fa-rotate-right"></i> Restart</button>
                </div>
            <?php else: ?>
                <div style="color: var(--text-dim); text-align: center; padding: 20px;">No frontend mapped.</div>
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
                    <option value="">-- Select Service to Monitor --</option>
                    <?php if($panel['be_service']): ?>
                        <option value="be">Backend Daemon Logs (journalctl)</option>
                        <option value="be_task">Backend Build & Task Progress</option>
                    <?php endif; ?>
                    <?php if($panel['fe_service']): ?>
                        <option value="fe">Frontend Daemon Logs</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
        <div class="terminal-body" id="terminalBody">
            <pre id="logOutput">Select a log channel above to initialize secure SSH stream...</pre>
        </div>
    </div>

  </div>
</main>

<script>
const panelId = <?= json_encode($panel_id) ?>;

function showToast(type, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = type === 'success' 
        ? `<i class="fa-solid fa-check-circle" style="color: var(--accent-green); font-size: 18px;"></i> ${message}` 
        : `<i class="fa-solid fa-circle-exclamation" style="color: var(--accent-red); font-size: 18px;"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 5000);
}

function runAction(action, type, btn) {
    let confirmMsg = `Are you sure you want to ${action.toUpperCase()} this service?`;
    if (action === 'create') {
        confirmMsg = "WARNING: This will wipe existing backend files, clone the repository, install Maven/Java, and rebuild from scratch. It takes 3-5 minutes. Proceed?";
    } else if (action === 'update') {
        confirmMsg = "This will stop the service, pull latest git changes, and run a fresh Maven build. Proceed?";
    } else if (action === 'remove') {
        confirmMsg = "DANGER: This will STOP the service, delete the systemd file, and completely ERASE the application folder. Proceed?";
    }
    
    if (!confirm(confirmMsg)) return;

    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Executing...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', action);
    formData.append('service_type', type);

    // Call the dedicated AJAX handler with robust JSON parsing
    fetch(`ajax_service_handler.php?id=${panelId}`, { method: 'POST', body: formData })
        .then(async res => {
            if (!res.ok) throw new Error("HTTP error " + res.status);
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch(e) {
                console.error("Raw server response:", text);
                throw new Error("Server returned invalid JSON. Check console.");
            }
        })
        .then(data => {
            showToast(data.status || 'error', data.message || 'Unknown response');
            
            if (data.status === 'success') {
                // Update Badge Visually instantly
                if (data.new_state) {
                    const badge = document.getElementById(type + '-status-badge');
                    if (badge) {
                        badge.textContent = data.new_state.toUpperCase();
                        badge.className = data.new_state === 'online' ? 'badge badge-active' : 'badge badge-offline';
                    }
                }

                // Automatically switch terminal to view background tasks
                if (['create', 'update', 'remove'].includes(action)) {
                    const logSelect = document.getElementById('logSource');
                    logSelect.value = type + '_task';
                    logSelect.dispatchEvent(new Event('change'));
                }

                // If starting or stopping, reload after a short delay to refresh button disabled states
                if (['start', 'stop'].includes(action)) {
                    setTimeout(() => location.reload(), 1500);
                    return; 
                }
            }
            
            // Always restore button state for background tasks or errors
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Execution failed. Check browser console.');
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}

document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('adminThemeToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            const body = document.documentElement;
            const currentTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            body.setAttribute('data-theme', currentTheme);
            localStorage.setItem('admin_theme', currentTheme);
        });
    }

    let pollInterval;
    const logSource = document.getElementById('logSource');
    const logOutput = document.getElementById('logOutput');
    const terminalBody = document.getElementById('terminalBody');
    const liveIndicator = document.getElementById('liveIndicator');

    function fetchLogs() {
        const type = logSource.value;
        if (!type) return;

        // Fetch logs via the dedicated handler
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
</body>
</html>
