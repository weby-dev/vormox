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

// ==========================================
// HANDLE AUTO-SAVE (AJAX)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autosave'])) {
    header('Content-Type: application/json');
    $panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
    
    if (!$panel_id) { echo json_encode(['success' => false]); exit; }
    
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM panel_details WHERE panel_id = :pid");
        $checkStmt->execute(['pid' => $panel_id]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            $sql = "UPDATE panel_details SET 
                be_service = :besrv, be_server_ip = :beip, be_ssh_port = :beport, be_ssh_user = :beusr, be_ssh_pass = :bepass, be_git_url = :begit, be_git_user = :begitu, be_git_pass = :begitp,
                fe_service = :fesrv, fe_server_ip = :feip, fe_ssh_port = :feport, fe_ssh_user = :feusr, fe_ssh_pass = :fepass, fe_git_url = :fegit, fe_git_user = :fegitu, fe_git_pass = :fegitp,
                rp_service = :rpsrv, rp_server_ip = :rpip, rp_ssh_port = :rpport, rp_ssh_user = :rpusr, rp_ssh_pass = :rppass,
                db_server_ip = :dbip, db_name = :dbname, db_user = :dbusr, db_pass = :dbpass
                WHERE panel_id = :pid";
        } else {
            // Insert as draft (status remains 'draft' until manually accepted)
            $sql = "INSERT INTO panel_details (
                panel_id, 
                be_service, be_server_ip, be_ssh_port, be_ssh_user, be_ssh_pass, be_git_url, be_git_user, be_git_pass,
                fe_service, fe_server_ip, fe_ssh_port, fe_ssh_user, fe_ssh_pass, fe_git_url, fe_git_user, fe_git_pass,
                rp_service, rp_server_ip, rp_ssh_port, rp_ssh_user, rp_ssh_pass,
                db_server_ip, db_name, db_user, db_pass, status
            ) VALUES (
                :pid, 
                :besrv, :beip, :beport, :beusr, :bepass, :begit, :begitu, :begitp,
                :fesrv, :feip, :feport, :feusr, :fepass, :fegit, :fegitu, :fegitp,
                :rpsrv, :rpip, :rpport, :rpusr, :rppass,
                :dbip, :dbname, :dbusr, :dbpass, 'draft'
            )";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'pid' => $panel_id,
            'besrv' => $_POST['be_service'] ?? '', 'beip' => $_POST['be_server_ip'] ?? '', 'beport' => $_POST['be_ssh_port'] ?: 22, 'beusr' => $_POST['be_ssh_user'] ?? '', 'bepass' => $_POST['be_ssh_pass'] ?? '', 'begit' => $_POST['be_git_url'] ?? '', 'begitu' => $_POST['be_git_user'] ?? '', 'begitp' => $_POST['be_git_pass'] ?? '',
            'fesrv' => $_POST['fe_service'] ?? '', 'feip' => $_POST['fe_server_ip'] ?? '', 'feport' => $_POST['fe_ssh_port'] ?: 22, 'feusr' => $_POST['fe_ssh_user'] ?? '', 'fepass' => $_POST['fe_ssh_pass'] ?? '', 'fegit' => $_POST['fe_git_url'] ?? '', 'fegitu' => $_POST['fe_git_user'] ?? '', 'fegitp' => $_POST['fe_git_pass'] ?? '',
            'rpsrv' => $_POST['rp_service'] ?? '', 'rpip' => $_POST['rp_server_ip'] ?? '', 'rpport' => $_POST['rp_ssh_port'] ?: 22, 'rpusr' => $_POST['rp_ssh_user'] ?? '', 'rppass' => $_POST['rp_ssh_pass'] ?? '',
            'dbip' => $_POST['db_server_ip'] ?? '', 'dbname' => $_POST['db_name'] ?? '', 'dbusr' => $_POST['db_user'] ?? '', 'dbpass' => $_POST['db_pass'] ?? ''
        ]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$success = ''; $error = '';
$view = $_GET['action'] ?? 'list';

// --- PROCESS BULK CANCELLATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_cancel'])) {
    $selected_orders = $_POST['selected_orders'] ?? [];
    if (!empty($selected_orders)) {
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($selected_orders), '?'));
            
            $userStmt = $pdo->prepare("SELECT DISTINCT user_id FROM user_panels WHERE id IN ($placeholders)");
            $userStmt->execute($selected_orders);
            $userIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);

            $panelStmt = $pdo->prepare("UPDATE user_panels SET status = 'cancelled' WHERE id IN ($placeholders)");
            $panelStmt->execute($selected_orders);
            
            if (!empty($userIds)) {
                $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                $invStmt = $pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE status = 'Unpaid' AND user_id IN ($userPlaceholders)");
                $invStmt->execute($userIds);
            }
            
            $pdo->commit();
            $success = "Successfully cancelled " . count($selected_orders) . " order(s) and voided their pending invoices.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to cancel orders.";
        }
    } else {
        $error = "Please select at least one order to cancel.";
    }
}

// --- PROCESS ORDER ACCEPTANCE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_order'])) {
    $panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
    
    if (empty($_POST['be_server_ip']) || empty($_POST['fe_server_ip']) || empty($_POST['db_server_ip'])) {
        $error = "Critical Error: You must provide at least the Backend, Frontend, and Database IP addresses to accept an order.";
        $view = 'fulfill';
        $_GET['id'] = $panel_id; 
    } else {
        try {
            // Ensure status becomes active
            $pdo->prepare("UPDATE panel_details SET status = 'active' WHERE panel_id = :pid")->execute(['pid' => $panel_id]);
            $pdo->prepare("UPDATE user_panels SET status = 'active' WHERE id = :pid")->execute(['pid' => $panel_id]);
            
            $success = "Order #$panel_id has been successfully provisioned and marked as ACTIVE.";
            $view = 'list';
        } catch (PDOException $e) {
            $error = "Database error while fulfilling order: " . $e->getMessage();
        }
    }
}

// --- FETCH DATA FOR NOTIFICATIONS AND VIEWS ---
$pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();

if ($view === 'list') {
    $ordersStmt = $pdo->query("SELECT p.*, u.first_name, u.last_name, u.email FROM user_panels p JOIN users u ON p.user_id = u.id WHERE p.status IN ('pending', 'payment_pending') ORDER BY p.created_at ASC");
    $pending_orders = $ordersStmt->fetchAll();
} elseif ($view === 'fulfill') {
    $panel_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$panel_id) { header("Location: orders.php"); exit; }
    
    // JOIN to fetch any existing draft details!
    $stmt = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email, pd.* FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN panel_details pd ON p.id = pd.panel_id
        WHERE p.id = :id AND p.status IN ('pending', 'payment_pending') 
        LIMIT 1
    ");
    $stmt->execute(['id' => $panel_id]);
    $order = $stmt->fetch();
    if (!$order) { die("Order not found or already processed."); }
}

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Order Management';
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
    document.documentElement.setAttribute('data-theme', savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark');
  </script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root, [data-theme="dark"] { --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35; --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3); --accent: #a78bfa; --accent2: #8b5cf6; --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68; --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace; --font-body: 'Instrument Sans', sans-serif; --accent-glow: rgba(139,92,246,0.35); --accent-red: #f87171; --accent-green: #22d3ee; --accent-orange: #fb923c; }
    [data-theme="light"] { --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0; --border: #e2e8f0; --border-strong: #cbd5e1; --accent: #7c3aed; --accent2: #6d28d9; --text: #0f172a; --text-muted: #475569; --text-dim: #64748b; --accent-glow: rgba(124,58,237,0.15); --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626; }
    
    body { background: var(--bg); color: var(--text); font-family: var(--font-body); display: flex; min-height: 100vh; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    aside { width: 260px; background: rgba(5,8,16,.95); border-right: 1px solid var(--border); padding: 24px; display: flex; flex-direction: column; z-index: 10; flex-shrink: 0; }
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
    
    header { padding: 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 99; background: rgba(5,8,16,.65); backdrop-filter: blur(20px); position: sticky; top: 0; }
    [data-theme="light"] header { background: rgba(255,255,255,0.8); }
    .header-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    [data-theme="dark"] .fa-moon { display: none; } [data-theme="light"] .fa-sun { display: none; }

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 24px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.1); color: var(--text); }
    
    .toolbar { display: flex; gap: 12px; align-items: center; }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    .table-link { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
    .table-link:hover .link-title { color: var(--accent-orange); }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 18px; font-weight: 600; border-radius: 8px; font-size: 13px; text-decoration: none; cursor: pointer; transition: 0.2s; border: none; font-family: var(--font-body); }
    .btn-accent { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-accent:hover { transform: translateY(-1px); filter: brightness(1.1); }
    .btn-back { background: var(--surface2); color: var(--text); border: 1px solid var(--border); margin-bottom: 24px; }
    .btn-back:hover { background: var(--border); }
    
    .btn-danger { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); padding: 8px 16px; font-size: 12px; }
    .btn-danger:hover { background: var(--accent-red); color: #fff; }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; font-family: var(--font-mono); display: inline-block; }
    .badge-pending { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }
    .badge-payment { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }

    input[type="checkbox"] { appearance: none; width: 18px; height: 18px; border: 2px solid var(--border-strong); border-radius: 4px; background: var(--bg); cursor: pointer; position: relative; transition: 0.2s; }
    input[type="checkbox"]:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"]:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    /* Fulfill Form Grid */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; padding: 32px; }
    @media(max-width: 1200px) { .form-grid { grid-template-columns: 1fr; } }
    
    .form-section { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 24px; position: relative; }
    .form-section h3 { font-family: var(--font-head); font-size: 16px; margin-bottom: 20px; color: var(--text); display: flex; align-items: center; gap: 8px; }
    
    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); font-family: var(--font-mono); text-transform: uppercase; margin-bottom: 8px; }
    .input-group input { width: 100%; padding: 12px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; transition: 0.2s; }
    .input-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

    /* Auto Save UI */
    #autosaveIndicator { font-size: 12px; font-family: var(--font-mono); font-weight: 600; color: var(--text-dim); }

    /* Toasts */
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
  <a href="index.php" class="logo">
    <div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
    Vormox <span>Admin</span>
  </a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item <?= $current_page == 'index.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item <?= $current_page == 'orders.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if(isset($pendingOrdersCount) && $pendingOrdersCount > 0): ?>
            <span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span>
        <?php endif; ?>
    </a>
    <a href="users.php" class="nav-item <?= $current_page == 'users.php' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item <?= in_array($current_page, ['panels.php', 'manage_panel.php']) ? 'active' : '' ?>"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item <?= $current_page == 'invoices.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item <?= $current_page == 'gateways.php' ? 'active' : '' ?>"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item <?= $current_page == 'tickets.php' ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item <?= $current_page == 'security.php' ? 'active' : '' ?>"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Global Settings</a>
  </nav>
  <div class="sidebar-footer">
    <?php if($admin): ?>
    <div style="padding: 0 16px 16px; font-size: 13px; color: var(--text-muted);">
        Logged in as<br><strong style="color: var(--text);"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong>
    </div>
    <?php endif; ?>
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);"><i class="fa-solid fa-arrow-right-from-bracket"></i> End Session</a>
  </div>
</aside>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title">Order Management</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">

    <?php if ($view === 'list'): ?>
        
        <form method="POST" action="orders.php" class="card">
            <div class="card-title">
                <div><i class="fa-solid fa-clock" style="color: var(--accent-orange);"></i> Awaiting Provisioning</div>
                <div class="toolbar">
                    <button type="submit" name="bulk_cancel" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel all selected orders AND their unpaid invoices?');">
                        <i class="fa-solid fa-ban"></i> Cancel Selected
                    </button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="5%"><input type="checkbox" id="selectAll" title="Select All"></th>
                        <th width="5%">ID</th>
                        <th width="40%">Requested Domain</th>
                        <th width="20%">Client</th>
                        <th width="10%">Specs</th>
                        <th width="10%">Status</th>
                        <th width="10%">Order Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($pending_orders)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">No pending orders at this time.</td></tr>
                    <?php else: foreach($pending_orders as $o): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_orders[]" value="<?= $o['id'] ?>" class="order-checkbox"></td>
                            <td style="font-family: var(--font-mono); color: var(--text-muted);">#<?= htmlspecialchars($o['id']) ?></td>
                            <td>
                                <a href="orders.php?action=fulfill&id=<?= $o['id'] ?>" class="table-link">
                                    <div class="link-title" style="font-weight: 700; color: var(--accent2); font-size: 15px;">
                                        <i class="fa-solid fa-wand-magic-sparkles" style="font-size: 12px; margin-right: 6px;"></i><?= htmlspecialchars($o['domain']) ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono); margin-top: 4px;">Click to fulfill</div>
                                </a>
                            </td>
                            <td>
                                <div style="font-size: 13px; color: var(--text); font-weight: 600;"><?= htmlspecialchars($o['first_name'] . ' ' . $o['last_name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($o['email']) ?></div>
                            </td>
                            <td>
                                <div style="font-size: 13px; font-weight: 600;"><?= htmlspecialchars($o['nodes_count']) ?> Nodes</div>
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: capitalize; font-family: var(--font-mono);"><?= htmlspecialchars(str_replace('_', '-', $o['billing_cycle'])) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $o['status'] == 'pending' ? 'badge-pending' : 'badge-payment' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?>
                                </span>
                            </td>
                            <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>

    <?php elseif ($view === 'fulfill'): ?>

        <a href="orders.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Orders</a>
        
        <form method="POST" action="orders.php" class="card" id="fulfillForm">
            <input type="hidden" name="panel_id" value="<?= htmlspecialchars($order['id']) ?>">
            
            <div class="card-title" style="justify-content: space-between;">
                <div><i class="fa-solid fa-clipboard-check"></i> Fulfilling Order #<?= htmlspecialchars($order['id']) ?> — <?= htmlspecialchars($order['domain']) ?></div>
                <div style="font-size: 13px; font-weight: 500; font-family: var(--font-body); color: var(--text-muted);">Client: <?= htmlspecialchars($order['email']) ?></div>
            </div>

            <div class="form-grid">
                
                <div class="form-section">
                    <h3 style="color: var(--accent-green);"><i class="fa-solid fa-server"></i> Backend Configuration</h3>
                    <div class="input-row">
                        <div class="input-group"><label>Server IP *</label><input type="text" name="be_server_ip" placeholder="e.g. 10.0.0.12" value="<?= htmlspecialchars($order['be_server_ip'] ?? '') ?>"></div>
                        <div class="input-group"><label>Service Name</label><input type="text" name="be_service" placeholder="e.g. backend.service" value="<?= htmlspecialchars($order['be_service'] ?? '') ?>"></div>
                    </div>
                    <div class="input-row">
                        <div class="input-group"><label>SSH Port</label><input type="number" name="be_ssh_port" value="<?= htmlspecialchars($order['be_ssh_port'] ?? '22') ?>"></div>
                        <div class="input-group"><label>SSH User</label><input type="text" name="be_ssh_user" value="<?= htmlspecialchars($order['be_ssh_user'] ?? 'root') ?>"></div>
                    </div>
                    <div class="input-group"><label>SSH Password</label><input type="password" name="be_ssh_pass" placeholder="Root Password" value="<?= htmlspecialchars($order['be_ssh_pass'] ?? '') ?>"></div>
                    <hr style="border: none; border-top: 1px solid var(--border); margin: 24px 0;">
                    <div class="input-group"><label>Git Repository URL</label><input type="url" name="be_git_url" placeholder="https://github.com/user/repo.git" value="<?= htmlspecialchars($order['be_git_url'] ?? '') ?>"></div>
                    <div class="input-row">
                        <div class="input-group"><label>Git Username</label><input type="text" name="be_git_user" placeholder="Username" value="<?= htmlspecialchars($order['be_git_user'] ?? '') ?>"></div>
                        <div class="input-group"><label>Git Access Token</label><input type="password" name="be_git_pass" placeholder="ghp_xxxxxxxxxxxx" value="<?= htmlspecialchars($order['be_git_pass'] ?? '') ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 style="color: #3b82f6;"><i class="fa-solid fa-window-maximize"></i> Frontend Configuration</h3>
                    <div class="input-row">
                        <div class="input-group"><label>Server IP *</label><input type="text" name="fe_server_ip" placeholder="e.g. 10.0.0.13" value="<?= htmlspecialchars($order['fe_server_ip'] ?? '') ?>"></div>
                        <div class="input-group"><label>Service Name</label><input type="text" name="fe_service" placeholder="e.g. frontend.service" value="<?= htmlspecialchars($order['fe_service'] ?? '') ?>"></div>
                    </div>
                    <div class="input-row">
                        <div class="input-group"><label>SSH Port</label><input type="number" name="fe_ssh_port" value="<?= htmlspecialchars($order['fe_ssh_port'] ?? '22') ?>"></div>
                        <div class="input-group"><label>SSH User</label><input type="text" name="fe_ssh_user" value="<?= htmlspecialchars($order['fe_ssh_user'] ?? 'root') ?>"></div>
                    </div>
                    <div class="input-group"><label>SSH Password</label><input type="password" name="fe_ssh_pass" placeholder="Root Password" value="<?= htmlspecialchars($order['fe_ssh_pass'] ?? '') ?>"></div>
                    <hr style="border: none; border-top: 1px solid var(--border); margin: 24px 0;">
                    <div class="input-group"><label>Git Repository URL</label><input type="url" name="fe_git_url" placeholder="https://github.com/user/repo.git" value="<?= htmlspecialchars($order['fe_git_url'] ?? '') ?>"></div>
                    <div class="input-row">
                        <div class="input-group"><label>Git Username</label><input type="text" name="fe_git_user" placeholder="Username" value="<?= htmlspecialchars($order['fe_git_user'] ?? '') ?>"></div>
                        <div class="input-group"><label>Git Access Token</label><input type="password" name="fe_git_pass" placeholder="ghp_xxxxxxxxxxxx" value="<?= htmlspecialchars($order['fe_git_pass'] ?? '') ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 style="color: var(--accent-orange);"><i class="fa-solid fa-database"></i> Database Configuration</h3>
                    <div class="input-group"><label>Database Host IP *</label><input type="text" name="db_server_ip" placeholder="e.g. 10.0.0.10" value="<?= htmlspecialchars($order['db_server_ip'] ?? '') ?>"></div>
                    <div class="input-group"><label>Database Name</label><input type="text" name="db_name" placeholder="e.g. vormox_db" value="<?= htmlspecialchars($order['db_name'] ?? '') ?>"></div>
                    <div class="input-row">
                        <div class="input-group"><label>DB User</label><input type="text" name="db_user" placeholder="e.g. admin" value="<?= htmlspecialchars($order['db_user'] ?? '') ?>"></div>
                        <div class="input-group"><label>DB Password</label><input type="password" name="db_pass" placeholder="Strong Password" value="<?= htmlspecialchars($order['db_pass'] ?? '') ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 style="color: var(--accent);"><i class="fa-solid fa-network-wired"></i> Reverse Proxy (Optional)</h3>
                    <div class="input-row">
                        <div class="input-group"><label>Server IP</label><input type="text" name="rp_server_ip" placeholder="e.g. 10.0.0.11" value="<?= htmlspecialchars($order['rp_server_ip'] ?? '') ?>"></div>
                        <div class="input-group"><label>Service Name</label><input type="text" name="rp_service" placeholder="nginx" value="<?= htmlspecialchars($order['rp_service'] ?? '') ?>"></div>
                    </div>
                    <div class="input-row">
                        <div class="input-group"><label>SSH Port</label><input type="number" name="rp_ssh_port" value="<?= htmlspecialchars($order['rp_ssh_port'] ?? '22') ?>"></div>
                        <div class="input-group"><label>SSH User</label><input type="text" name="rp_ssh_user" value="<?= htmlspecialchars($order['rp_ssh_user'] ?? 'root') ?>"></div>
                    </div>
                    <div class="input-group"><label>SSH Password</label><input type="password" name="rp_ssh_pass" placeholder="Root Password" value="<?= htmlspecialchars($order['rp_ssh_pass'] ?? '') ?>"></div>
                </div>

            </div>

            <div style="padding: 0 32px 32px; display: flex; justify-content: flex-end; align-items: center; gap: 24px;">
                <div id="autosaveIndicator"></div>
                <button type="submit" name="accept_order" class="btn btn-accent" style="padding: 16px 32px; font-size: 15px;">
                    <i class="fa-solid fa-check-double"></i> Provision & Accept Order
                </button>
            </div>
        </form>

    <?php endif; ?>
  </div>
</main>

<script>
// Handle PHP Post-back Toasts
<?php if ($success): ?>
    showToast('success', <?= json_encode($success) ?>);
<?php endif; ?>
<?php if ($error): ?>
    showToast('error', <?= json_encode($error) ?>);
<?php endif; ?>

function showToast(type, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = type === 'success' 
        ? `<i class="fa-solid fa-check-circle" style="color: var(--accent-green); font-size: 18px;"></i> ${message}` 
        : `<i class="fa-solid fa-circle-exclamation" style="color: var(--accent-red); font-size: 18px;"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
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

    // Master Checkbox Logic
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            let checkboxes = document.querySelectorAll('.order-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }

    // Auto-Save Logic
    const form = document.getElementById('fulfillForm');
    if (form) {
        let timeoutId;
        const indicator = document.getElementById('autosaveIndicator');
        
        form.addEventListener('input', () => {
            clearTimeout(timeoutId);
            indicator.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving draft...';
            indicator.style.color = 'var(--text-muted)';
            
            timeoutId = setTimeout(() => {
                const formData = new FormData(form);
                formData.append('autosave', '1');
                
                fetch('orders.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        indicator.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Draft saved';
                        indicator.style.color = 'var(--accent-green)';
                    } else {
                        indicator.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Save failed';
                        indicator.style.color = 'var(--accent-red)';
                    }
                })
                .catch(() => {
                    indicator.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Network error';
                    indicator.style.color = 'var(--accent-orange)';
                });
            }, 1000); // 1 second debounce
        });
    }
});
</script>
</body>
</html>
