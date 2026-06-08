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


csrf_require();
$panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$panel_id) { header("Location: panels.php"); exit; }

$success = ''; $error = '';

// --- HANDLE FULL CONFIGURATION UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_panel_config'])) {
    try {
        $pdo->beginTransaction();

        // 1. Update user_panels
        $domain = filter_input(INPUT_POST, 'domain', FILTER_SANITIZE_SPECIAL_CHARS);
        $nodes = filter_input(INPUT_POST, 'nodes_count', FILTER_VALIDATE_INT);
        $billing = filter_input(INPUT_POST, 'billing_cycle', FILTER_SANITIZE_SPECIAL_CHARS);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
        $auto_renew        = isset($_POST['auto_renew']) ? 1 : 0;
        $bypass_suspension = isset($_POST['bypass_suspension']) ? 1 : 0;

        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        $upStmt = $pdo->prepare("
            UPDATE user_panels
               SET domain = ?, nodes_count = ?, billing_cycle = ?, status = ?,
                   expiry_date = ?, auto_renew = ?, bypass_suspension = ?
             WHERE id = ?
        ");
        $upStmt->execute([$domain, $nodes, $billing, $status, $expiry_date, $auto_renew, $bypass_suspension, $panel_id]);

        // 2. Update panel_details
        $checkPd = $pdo->prepare("SELECT id FROM panel_details WHERE panel_id = ?");
        $checkPd->execute([$panel_id]);
        $pdExists = $checkPd->fetch();

        if ($pdExists) {
            $pdSql = "UPDATE panel_details SET 
                be_service = ?, be_server_ip = ?, be_ssh_port = ?, be_ssh_user = ?, be_ssh_pass = ?, be_git_url = ?, be_git_user = ?, be_git_pass = ?,
                fe_service = ?, fe_server_ip = ?, fe_ssh_port = ?, fe_ssh_user = ?, fe_ssh_pass = ?, fe_git_url = ?, fe_git_user = ?, fe_git_pass = ?,
                db_server_ip = ?, db_name = ?, db_user = ?, db_pass = ?,
                rp_service = ?, rp_server_ip = ?, rp_ssh_port = ?, rp_ssh_user = ?, rp_ssh_pass = ?
                WHERE panel_id = ?";
            
            $pdo->prepare($pdSql)->execute([
                $_POST['be_service'], $_POST['be_server_ip'], $_POST['be_ssh_port'] ?: 22, $_POST['be_ssh_user'], $_POST['be_ssh_pass'], $_POST['be_git_url'], $_POST['be_git_user'], $_POST['be_git_pass'],
                $_POST['fe_service'], $_POST['fe_server_ip'], $_POST['fe_ssh_port'] ?: 22, $_POST['fe_ssh_user'], $_POST['fe_ssh_pass'], $_POST['fe_git_url'], $_POST['fe_git_user'], $_POST['fe_git_pass'],
                $_POST['db_server_ip'], $_POST['db_name'], $_POST['db_user'], $_POST['db_pass'],
                $_POST['rp_service'], $_POST['rp_server_ip'], $_POST['rp_ssh_port'] ?: 22, $_POST['rp_ssh_user'], $_POST['rp_ssh_pass'],
                $panel_id
            ]);
        } else {
            // be_status / fe_status are NOT NULL with no DB default — supply them
            // on first INSERT or strict mode rejects the row.
            $pdSql = "INSERT INTO panel_details (
                panel_id, status,
                be_service, be_server_ip, be_ssh_port, be_ssh_user, be_ssh_pass, be_git_url, be_git_user, be_git_pass, be_status,
                fe_service, fe_server_ip, fe_ssh_port, fe_ssh_user, fe_ssh_pass, fe_git_url, fe_git_user, fe_git_pass, fe_status,
                db_server_ip, db_name, db_user, db_pass,
                rp_service, rp_server_ip, rp_ssh_port, rp_ssh_user, rp_ssh_pass
            ) VALUES (?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $pdo->prepare($pdSql)->execute([
                $panel_id,
                $_POST['be_service'], $_POST['be_server_ip'], $_POST['be_ssh_port'] ?: 22, $_POST['be_ssh_user'], $_POST['be_ssh_pass'], $_POST['be_git_url'], $_POST['be_git_user'], $_POST['be_git_pass'],
                $_POST['fe_service'], $_POST['fe_server_ip'], $_POST['fe_ssh_port'] ?: 22, $_POST['fe_ssh_user'], $_POST['fe_ssh_pass'], $_POST['fe_git_url'], $_POST['fe_git_user'], $_POST['fe_git_pass'],
                $_POST['db_server_ip'], $_POST['db_name'], $_POST['db_user'], $_POST['db_pass'],
                $_POST['rp_service'], $_POST['rp_server_ip'], $_POST['rp_ssh_port'] ?: 22, $_POST['rp_ssh_user'], $_POST['rp_ssh_pass']
            ]);
        }

        $pdo->commit();
        $success = "Configuration updated successfully.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Database error while updating configuration.";
    }
}

// --- FETCH DATA FOR NOTIFICATIONS ---
$current_page = basename($_SERVER['PHP_SELF']);
try {
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
} catch (PDOException $e) { $pendingOrdersCount = 0; }

// --- FETCH PANEL & INFRASTRUCTURE DATA ---
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email, 
               pd.be_service, pd.fe_service, pd.be_status, pd.fe_status,
               pd.be_server_ip, pd.be_ssh_port, pd.be_ssh_user, pd.be_ssh_pass, pd.be_git_url, pd.be_git_user, pd.be_git_pass,
               pd.fe_server_ip, pd.fe_ssh_port, pd.fe_ssh_user, pd.fe_ssh_pass, pd.fe_git_url, pd.fe_git_user, pd.fe_git_pass,
               pd.rp_server_ip, pd.rp_service, pd.rp_ssh_port, pd.rp_ssh_user, pd.rp_ssh_pass,
               pd.db_server_ip, pd.db_name, pd.db_user, pd.db_pass, pd.status as details_status 
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
<head><?= csrf_meta() ?>
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
    
    .btn-create { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); margin-top: 16px; }
    .btn-create:hover:not(:disabled) { background: #10b981; color: #fff; box-shadow: 0 4px 15px rgba(16,185,129,0.3); }
    
    .btn-update { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.2); margin-top: 16px; }
    .btn-update:hover:not(:disabled) { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    
    .btn-remove { background: transparent; color: var(--accent-red); border: 1px dashed var(--accent-red); margin-top: 8px; }
    .btn-remove:hover:not(:disabled) { background: rgba(248,113,113,0.1); }

    .btn-edit-config { background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); padding: 6px 12px; border-radius: 6px; font-size: 12px; font-family: var(--font-body); font-weight: 600; cursor: pointer; transition: 0.2s; }
    .btn-edit-config:hover { background: var(--accent2); color: #fff; border-color: var(--accent2); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); display: inline-block; letter-spacing: 0.05em; }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-offline { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-other { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .terminal-wrapper { background: #000; border: 1px solid #333; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5); margin-top: 32px; }
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

    /* Edit Modal Styles */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; overflow-y: auto; padding: 40px 20px; }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-content { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 1000px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transform: translateY(20px); transition: transform 0.3s; position: relative; margin: auto; }
    .modal-overlay.show .modal-content { transform: translateY(0); }
    .modal-header { padding: 24px 32px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-family: var(--font-head); font-size: 20px; font-weight: 700; background: rgba(0,0,0,0.2); border-radius: 16px 16px 0 0; }
    .close-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 20px; transition: 0.2s; }
    .close-btn:hover { color: var(--accent-red); }
    .modal-body { padding: 32px; }

    .form-grid-modal { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
    @media(max-width: 900px) { .form-grid-modal { grid-template-columns: 1fr; } }
    
    .form-section { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
    .form-section h3 { font-family: var(--font-head); font-size: 15px; margin-bottom: 16px; color: var(--text); display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
    
    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); font-family: var(--font-mono); text-transform: uppercase; margin-bottom: 6px; }
    .input-group input, .input-group select { width: 100%; padding: 10px 14px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-body); font-size: 13px; outline: none; transition: 0.2s; }
    .input-group input:focus, .input-group select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .btn-save { background: var(--accent2); color: #fff; padding: 14px 32px; border: none; border-radius: 8px; font-family: var(--font-body); font-weight: 600; cursor: pointer; transition: 0.2s; font-size: 15px; display: block; width: 100%; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-save:hover { filter: brightness(1.1); transform: translateY(-1px); }

    #toast-container { position: fixed; bottom: 32px; right: 32px; z-index: 99999; display: flex; flex-direction: column; gap: 12px; }
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
            <div class="card-title">
                <div><i class="fa-solid fa-globe" style="color: var(--accent2);"></i> Panel Information</div>
                <button class="btn-edit-config" onclick="openConfigModal()"><i class="fa-solid fa-pen-to-square"></i> Edit Config</button>
            </div>
            <div class="data-row"><span class="data-label">Domain</span><span class="data-value"><?= htmlspecialchars($panel['domain']) ?></span></div>
            <div class="data-row"><span class="data-label">Client Name</span><span class="data-value"><?= htmlspecialchars($panel['first_name'] . ' ' . $panel['last_name']) ?></span></div>
            <div class="data-row"><span class="data-label">Expiry Date</span><span class="data-value data-mono"><?= $panel['expiry_date'] ? date('M j, Y', strtotime($panel['expiry_date'])) : 'N/A' ?></span></div>
            <div class="data-row"><span class="data-label">Status</span><span class="badge <?= $panel['status'] == 'active' ? 'badge-active' : 'badge-other' ?>"><?= htmlspecialchars(str_replace('_', ' ', $panel['status'])) ?></span></div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-database" style="color: var(--accent-orange);"></i> Database Details</div>
            <?php if($panel['db_name']): ?>
                <div class="data-row"><span class="data-label">DB Host</span><span class="data-value data-mono"><?= htmlspecialchars($panel['db_server_ip']) ?></span></div>
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
                <div class="data-row"><span class="data-label">Server IP</span><span class="data-value data-mono"><?= htmlspecialchars($panel['be_server_ip']) ?></span></div>
                
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
                <div class="data-row"><span class="data-label">Server IP</span><span class="data-value data-mono"><?= htmlspecialchars($panel['fe_server_ip']) ?></span></div>
                
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

    <div class="card" style="border-color: rgba(248,113,113,0.3); background: rgba(248,113,113,0.02); margin-top: 32px;">
        <div class="card-title" style="color: var(--accent-red);">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> Danger Zone: Terminate Service</div>
        </div>
        <div style="font-size: 14px; color: var(--text-dim); margin-bottom: 24px; line-height: 1.6;">
            Terminating this service is an irreversible action. It will instantly execute the following procedures:
            <ul style="margin-top: 8px; padding-left: 24px; color: var(--text-muted);">
                <li>Cancel all pending and unpaid invoices for this service.</li>
                <li>Connect to the client's database to extract all Reseller configurations.</li>
                <li>Access the Reverse Proxy edge nodes to wipe routing for the primary domain and all reseller domains.</li>
                <li>Issue shutdown commands to the backend and frontend nodes.</li>
            </ul>
        </div>
        <div>
            <button class="control-btn btn-stop" onclick="confirmTermination()" style="width: auto; padding: 12px 32px;"><i class="fa-solid fa-skull"></i> Execute Service Teardown</button>
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
                    
                    <?php if(!empty($panel['be_server_ip'])): ?>
                        <option value="be">Backend Daemon Logs (journalctl)</option>
                        <option value="be_task">Backend Build & Task Progress</option>
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
            <pre id="logOutput">Select a log channel above to initialize secure SSH stream...</pre>
        </div>
    </div>

  </div>
</main>

<div class="modal-overlay" id="configModal">
    <div class="modal-content">
        <div class="modal-header">
            Edit Full Panel Configuration
            <button class="close-btn" onclick="closeConfigModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="manage_panel.php?id=<?= (int)$panel_id ?>">
                <?= csrf_field() ?>
                <div class="form-grid-modal">
                    
                    <div class="form-section">
                        <h3><i class="fa-solid fa-globe" style="color: var(--accent);"></i> General Configuration</h3>
                        <div class="input-group"><label>Domain</label><input type="text" name="domain" value="<?= htmlspecialchars($panel['domain']) ?>" required></div>
                        <div class="input-row">
                            <div class="input-group"><label>Nodes Count</label><input type="number" name="nodes_count" value="<?= htmlspecialchars($panel['nodes_count']) ?>" required></div>
                            <div class="input-group">
                                <label>Billing Cycle</label>
                                <select name="billing_cycle">
                                    <option value="monthly" <?= $panel['billing_cycle'] == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="quarterly" <?= $panel['billing_cycle'] == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                    <option value="semi_annually" <?= $panel['billing_cycle'] == 'semi_annually' ? 'selected' : '' ?>>Semi-Annually</option>
                                    <option value="annually" <?= $panel['billing_cycle'] == 'annually' ? 'selected' : '' ?>>Annually</option>
                                </select>
                            </div>
                        </div>
                        <div class="input-row">
                            <div class="input-group">
                                <label>Panel Status</label>
                                <select name="status">
                                    <option value="payment_pending" <?= $panel['status'] == 'payment_pending' ? 'selected' : '' ?>>Payment Pending</option>
                                    <option value="pending" <?= $panel['status'] == 'pending' ? 'selected' : '' ?>>Pending Build</option>
                                    <option value="creating" <?= $panel['status'] == 'creating' ? 'selected' : '' ?>>Creating</option>
                                    <option value="active" <?= $panel['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="restarting" <?= $panel['status'] == 'restarting' ? 'selected' : '' ?>>Restarting</option>
                                    <option value="suspended" <?= $panel['status'] == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="error" <?= $panel['status'] == 'error' ? 'selected' : '' ?>>Error</option>
                                </select>
                            </div>
                            <div class="input-group"><label>Expiry Date</label><input type="datetime-local" name="expiry_date" value="<?= $panel['expiry_date'] ? date('Y-m-d\TH:i', strtotime($panel['expiry_date'])) : '' ?>"></div>
                        </div>
                        <div class="input-group" style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="auto_renew" value="1" <?= $panel['auto_renew'] ? 'checked' : '' ?> style="width: auto;"> <span style="font-size: 13px; font-weight: 500;">Enable Auto-Renew</span>
                        </div>
                        <div class="input-group" style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="bypass_suspension" value="1" <?= !empty($panel['bypass_suspension']) ? 'checked' : '' ?> style="width: auto;">
                            <span style="font-size: 13px; font-weight: 500;">
                                Bypass Automatic Suspension
                                <span style="display: block; font-size: 11px; color: var(--text-muted); font-weight: 400; margin-top: 2px;">
                                    Exempt this panel from any automated suspension job (overdue invoices, expired plan, etc). Admin can still suspend manually via the Status dropdown.
                                </span>
                            </span>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fa-solid fa-database" style="color: var(--accent-orange);"></i> Database Mapping</h3>
                        <div class="input-group"><label>DB Server IP</label><input type="text" name="db_server_ip" value="<?= htmlspecialchars($panel['db_server_ip'] ?? '') ?>"></div>
                        <div class="input-group"><label>DB Name</label><input type="text" name="db_name" value="<?= htmlspecialchars($panel['db_name'] ?? '') ?>"></div>
                        <div class="input-row">
                            <div class="input-group"><label>DB User</label><input type="text" name="db_user" value="<?= htmlspecialchars($panel['db_user'] ?? '') ?>"></div>
                            <div class="input-group"><label>DB Password</label><input type="text" name="db_pass" value="<?= htmlspecialchars($panel['db_pass'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fa-solid fa-server" style="color: var(--accent-green);"></i> Backend Node</h3>
                        <div class="input-row">
                            <div class="input-group"><label>Server IP</label><input type="text" name="be_server_ip" value="<?= htmlspecialchars($panel['be_server_ip'] ?? '') ?>"></div>
                            <div class="input-group"><label>Service Name</label><input type="text" name="be_service" value="<?= htmlspecialchars($panel['be_service'] ?? '') ?>"></div>
                        </div>
                        <div class="input-row">
                            <div class="input-group"><label>SSH Port</label><input type="number" name="be_ssh_port" value="<?= htmlspecialchars($panel['be_ssh_port'] ?? '22') ?>"></div>
                            <div class="input-group"><label>SSH User</label><input type="text" name="be_ssh_user" value="<?= htmlspecialchars($panel['be_ssh_user'] ?? 'root') ?>"></div>
                        </div>
                        <div class="input-group"><label>SSH Password</label><input type="text" name="be_ssh_pass" value="<?= htmlspecialchars($panel['be_ssh_pass'] ?? '') ?>"></div>
                        <div class="input-group"><label>Git URL</label><input type="text" name="be_git_url" value="<?= htmlspecialchars($panel['be_git_url'] ?? '') ?>"></div>
                        <div class="input-row">
                            <div class="input-group"><label>Git User</label><input type="text" name="be_git_user" value="<?= htmlspecialchars($panel['be_git_user'] ?? '') ?>"></div>
                            <div class="input-group"><label>Git Token</label><input type="text" name="be_git_pass" value="<?= htmlspecialchars($panel['be_git_pass'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fa-solid fa-window-maximize" style="color: #3b82f6;"></i> Frontend Node</h3>
                        <div class="input-row">
                            <div class="input-group"><label>Server IP</label><input type="text" name="fe_server_ip" value="<?= htmlspecialchars($panel['fe_server_ip'] ?? '') ?>"></div>
                            <div class="input-group"><label>Service Name</label><input type="text" name="fe_service" value="<?= htmlspecialchars($panel['fe_service'] ?? '') ?>"></div>
                        </div>
                        <div class="input-row">
                            <div class="input-group"><label>SSH Port</label><input type="number" name="fe_ssh_port" value="<?= htmlspecialchars($panel['fe_ssh_port'] ?? '22') ?>"></div>
                            <div class="input-group"><label>SSH User</label><input type="text" name="fe_ssh_user" value="<?= htmlspecialchars($panel['fe_ssh_user'] ?? 'root') ?>"></div>
                        </div>
                        <div class="input-group"><label>SSH Password</label><input type="text" name="fe_ssh_pass" value="<?= htmlspecialchars($panel['fe_ssh_pass'] ?? '') ?>"></div>
                        <div class="input-group"><label>Git URL</label><input type="text" name="fe_git_url" value="<?= htmlspecialchars($panel['fe_git_url'] ?? '') ?>"></div>
                        <div class="input-row">
                            <div class="input-group"><label>Git User</label><input type="text" name="fe_git_user" value="<?= htmlspecialchars($panel['fe_git_user'] ?? '') ?>"></div>
                            <div class="input-group"><label>Git Token</label><input type="text" name="fe_git_pass" value="<?= htmlspecialchars($panel['fe_git_pass'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-section" style="grid-column: 1 / -1;">
                        <h3><i class="fa-solid fa-network-wired" style="color: var(--accent2);"></i> Reverse Proxy (Optional)</h3>
                        <div class="input-row">
                            <div class="input-group"><label>Server IP</label><input type="text" name="rp_server_ip" value="<?= htmlspecialchars($panel['rp_server_ip'] ?? '') ?>"></div>
                            <div class="input-group"><label>Service Name</label><input type="text" name="rp_service" value="<?= htmlspecialchars($panel['rp_service'] ?? 'nginx') ?>"></div>
                        </div>
                        <div class="input-row">
                            <div class="input-group"><label>SSH Port</label><input type="number" name="rp_ssh_port" value="<?= htmlspecialchars($panel['rp_ssh_port'] ?? '22') ?>"></div>
                            <div class="input-group"><label>SSH User</label><input type="text" name="rp_ssh_user" value="<?= htmlspecialchars($panel['rp_ssh_user'] ?? 'root') ?>"></div>
                        </div>
                        <div class="input-group"><label>SSH Password</label><input type="text" name="rp_ssh_pass" value="<?= htmlspecialchars($panel['rp_ssh_pass'] ?? '') ?>"></div>
                    </div>

                </div>

                <div style="text-align: right; margin-top: 16px;">
                    <button type="submit" name="update_panel_config" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Save Configuration</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const panelId = <?= json_encode($panel_id) ?>;

// --- MODAL LOGIC ---
function openConfigModal() {
    document.getElementById('configModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeConfigModal() {
    document.getElementById('configModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
document.getElementById('configModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfigModal();
});

// --- TOAST LOGIC ---
<?php if ($success): ?> showToast('success', <?= json_encode($success) ?>); <?php endif; ?>
<?php if ($error): ?> showToast('error', <?= json_encode($error) ?>); <?php endif; ?>

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

// --- TERMINATE LOGIC (Danger Zone) ---
function confirmTermination() {
    const confirmation = prompt("DANGER: This will tear down routing and cancel billing. Type 'TERMINATE' to proceed:");
    if (confirmation !== "TERMINATE") {
        showToast('error', "Termination aborted.");
        return;
    }

    const btn = document.querySelector('.btn-stop .fa-skull').parentElement;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Executing Teardown Protocol...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('csrf_token', (document.querySelector('meta[name="csrf-token"]')||{}).content || '');
    formData.append('ajax_action', 'terminate');
    formData.append('service_type', 'all');

    fetch(`ajax_service_handler.php?id=${panelId}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]')||{}).content || '' },
        body: formData
    })
        .then(async res => {
            if (!res.ok) throw new Error("HTTP error " + res.status);
            return await res.json();
        })
        .then(data => {
            showToast(data.status || 'error', data.message || 'Unknown response');
            if (data.status === 'success') {
                setTimeout(() => window.location.href = 'panels.php', 3000);
            } else {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            showToast('error', 'Execution failed. Check server connection.');
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}

// --- ACTION LOGIC ---
function runAction(action, type, btn) {
    let confirmMsg = `Are you sure you want to ${action.toUpperCase()} this service?`;
    if (action === 'create') {
        confirmMsg = "WARNING: This will wipe existing backend files, clone the repository, install dependencies, and rebuild from scratch. Proceed?";
    } else if (action === 'update') {
        confirmMsg = "This will stop the service, pull latest git changes, and run a fresh build. Proceed?";
    } else if (action === 'remove') {
        confirmMsg = "DANGER: This will STOP the service, delete the systemd file, and completely ERASE the application folder. Proceed?";
    }
    
    if (!confirm(confirmMsg)) return;

    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Executing...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('csrf_token', (document.querySelector('meta[name="csrf-token"]')||{}).content || '');
    formData.append('ajax_action', action);
    formData.append('service_type', type);

    fetch(`ajax_service_handler.php?id=${panelId}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]')||{}).content || '' },
        body: formData
    })
        .then(async res => {
            if (!res.ok) throw new Error("HTTP error " + res.status);
            const text = await res.text();
            try { return JSON.parse(text); } catch(e) { throw new Error("Server returned invalid JSON."); }
        })
        .then(data => {
            showToast(data.status || 'error', data.message || 'Unknown response');
            
            if (data.status === 'success') {
                if (data.new_state) {
                    const badge = document.getElementById(type + '-status-badge');
                    if (badge) {
                        badge.textContent = data.new_state.toUpperCase();
                        badge.className = data.new_state === 'online' ? 'badge badge-active' : 'badge badge-offline';
                    }
                }
                if (['create', 'update', 'remove'].includes(action)) {
                    const logSelect = document.getElementById('logSource');
                    logSelect.value = type + '_task';
                    logSelect.dispatchEvent(new Event('change'));
                }
                if (['start', 'stop'].includes(action)) {
                    setTimeout(() => location.reload(), 1500);
                    return; 
                }
            }
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        })
        .catch(err => {
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
    let logInFlight = false;
    let logFailures = 0;
    const LOG_MAX_FAILURES   = 3;
    const LOG_FETCH_TIMEOUT  = 12000; // 12s — caps blocked SSH calls so polling doesn't pile up

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
            `Possible causes:\n` +
            `  • SSH host is on a private network the web server can't reach\n` +
            `  • SSH credentials are wrong (user/pass mismatch)\n` +
            `  • The target systemd service or log file does not exist\n` +
            `  • Firewall blocks port 22 between this host and the target\n\n` +
            `Re-select a log source to retry.`;
    }

    function fetchLogs() {
        const type = logSource.value;
        if (!type) return;

        // Don't pile up — if the previous request is still pending, skip this tick.
        if (logInFlight) return;
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
</body>
</html>
