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
$success = ''; $error = '';
$valid_statuses = ['active', 'banned', 'unverified'];

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Single User Inline Update
    if (isset($_POST['update_status'])) {
        $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
        
        if ($target_user_id && in_array($new_status, $valid_statuses)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
                $stmt->execute(['status' => $new_status, 'id' => $target_user_id]);
                $success = "User account status updated to " . strtoupper($new_status) . ".";
            } catch (PDOException $e) {
                $error = "Database error while updating user status.";
            }
        }
    }
    
    // 2. Bulk Users Update
    if (isset($_POST['bulk_update_status'])) {
        $selected_users = $_POST['selected_users'] ?? [];
        $bulk_status = filter_input(INPUT_POST, 'bulk_status', FILTER_SANITIZE_SPECIAL_CHARS);
        
        if (!empty($selected_users) && in_array($bulk_status, $valid_statuses)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id IN ($placeholders)");
                
                // Merge status and IDs for the execute array
                $params = array_merge([$bulk_status], $selected_users);
                $stmt->execute($params);
                
                $success = "Successfully updated " . count($selected_users) . " user(s) to " . strtoupper($bulk_status) . ".";
            } catch (PDOException $e) {
                $error = "Failed to apply bulk update. Check your database ENUM configuration.";
            }
        } else {
            $error = "Please select at least one user and a valid status.";
        }
    }
}

// --- FETCH DATA FOR NOTIFICATIONS AND VIEWS ---
$current_page = basename($_SERVER['PHP_SELF']);
try {
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
} catch (PDOException $e) {
    $pendingOrdersCount = 0;
}

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- FETCH USER STATS ---
try {
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
    $bannedUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")->fetchColumn();
} catch (PDOException $e) {
    $totalUsers = 0; $activeUsers = 0; $bannedUsers = 0;
}

// --- FETCH USERS LIST WITH SEARCH ---
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
try {
    $query = "
        SELECT u.*, 
               (SELECT COUNT(*) FROM user_panels WHERE user_id = u.id AND status NOT IN ('cancelled', 'error')) as active_panels 
        FROM users u 
    ";
    $params = [];
    
    if ($search) {
        $query .= " WHERE u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search ";
        $params['search'] = "%$search%";
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $usersList = $stmt->fetchAll();
} catch (PDOException $e) {
    $usersList = [];
}

$page_title = 'Client Directory';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head><?= csrf_meta() ?>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <!-- Vormox favicon (global) -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    document.documentElement.setAttribute('data-theme', savedTheme === 'dark' ? 'dark' : 'light');
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 32px; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    [data-theme="light"] .stat-card { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .stat-icon { position: absolute; top: 24px; right: 24px; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .stat-label { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; }
    .stat-value { font-family: var(--font-head); font-size: 36px; font-weight: 700; color: var(--text); margin-bottom: 4px; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 24px; }
    .card-title { padding: 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.1); }
    
    .search-box { display: flex; gap: 8px; align-items: center; }
    .search-box input { padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border-strong); background: var(--bg); color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; width: 300px; transition: 0.2s; }
    .search-box input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    .btn-search { background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); padding: 10px 16px; border-radius: 8px; cursor: pointer; transition: 0.2s; font-weight: 600; }
    .btn-search:hover { background: var(--border); }

    .btn-bulk { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.3); padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; font-size: 13px; margin-left: 8px; font-family: var(--font-body); }
    .btn-bulk:hover { background: var(--accent2); color: #fff; }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    input[type="checkbox"] { appearance: none; width: 18px; height: 18px; border: 2px solid var(--border-strong); border-radius: 4px; background: var(--bg); cursor: pointer; position: relative; transition: 0.2s; }
    input[type="checkbox"]:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"]:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    /* Client Profile Link Override */
    .profile-link { text-decoration: none; display: flex; align-items: center; gap: 16px; transition: 0.2s; padding: 4px; border-radius: 8px; }
    .profile-link:hover { background: rgba(139,92,246,0.05); }
    .profile-link:hover .profile-name { color: var(--accent2); }

    .avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--surface2); border: 1px solid var(--border-strong); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--text); font-family: var(--font-head); font-size: 16px; flex-shrink: 0; transition: 0.2s; }
    .profile-link:hover .avatar { border-color: var(--accent2); color: var(--accent2); background: rgba(139,92,246,0.1); }
    
    .profile-name { font-weight: 600; font-size: 15px; margin-bottom: 2px; color: var(--text); transition: 0.2s; }

    .status-select { padding: 8px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; transition: 0.3s; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }
    
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-banned { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-unverified { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }

    .btn-edit { background: var(--surface2); border: 1px solid var(--border-strong); color: var(--text); padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .btn-edit:hover { background: var(--accent2); border-color: var(--accent2); color: #fff; }

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

<form id="bulkForm" method="POST" action="users.php" style="display:none;">
    <input type="hidden" name="bulk_update_status" value="1">
    <input type="hidden" name="bulk_status" id="hiddenBulkStatus">
    <div id="hiddenUserInputs"></div>
</form>

<aside>
  <a href="index.php" class="logo"><div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>Vormox <span>Admin</span></a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item <?= $current_page == 'index.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item <?= $current_page == 'orders.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if($pendingOrdersCount > 0): ?><span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span><?php endif; ?>
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
    <?php if($admin): ?><div style="padding: 0 16px 16px; font-size: 13px; color: var(--text-muted);">Logged in as<br><strong style="color: var(--text);"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong></div><?php endif; ?>
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);"><i class="fa-solid fa-arrow-right-from-bracket"></i> End Session</a>
  </div>
</aside>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title">Client Directory</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139,92,246,0.1); color: var(--accent2);"><i class="fa-solid fa-users"></i></div>
            <div class="stat-label">Total Registered</div>
            <div class="stat-value"><?= number_format($totalUsers) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(34,211,238,0.1); color: var(--accent-green);"><i class="fa-solid fa-user-check"></i></div>
            <div class="stat-label">Active Clients</div>
            <div class="stat-value"><?= number_format($activeUsers) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(248,113,113,0.1); color: var(--accent-red);"><i class="fa-solid fa-user-slash"></i></div>
            <div class="stat-label">Banned Accounts</div>
            <div class="stat-value"><?= number_format($bannedUsers) ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-address-book" style="color: var(--accent);"></i> User Database
            </div>
            
            <div style="display: flex; gap: 16px; align-items: center;">
                <div style="display: flex; gap: 8px; border-right: 1px solid var(--border-strong); padding-right: 16px;">
                    <select id="bulkStatusSelect" class="status-select" style="margin:0;">
                        <option value="">-- Set Status --</option>
                        <option value="active">Active</option>
                        <option value="unverified">Unverified</option>
                        <option value="banned">Banned</option>
                    </select>
                    <button type="button" class="btn-bulk" onclick="runBulkUpdate()">Apply</button>
                </div>

                <form method="GET" action="users.php" class="search-box">
                    <input type="text" name="search" placeholder="Search name or email..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i></button>
                    <?php if($search): ?>
                        <a href="users.php" class="btn-search" style="text-decoration: none; display: flex; align-items: center;"><i class="fa-solid fa-xmark"></i></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%"><input type="checkbox" id="selectAll" title="Select All"></th>
                    <th width="5%">ID</th>
                    <th width="30%">Client Profile</th>
                    <th width="15%">Account State</th>
                    <th width="15%">Infrastructure</th>
                    <th width="15%">Registration Date</th>
                    <th width="15%" style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($usersList)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">No users found matching your criteria.</td></tr>
                <?php else: foreach($usersList as $u): ?>
                    <tr>
                        <td><input type="checkbox" value="<?= $u['id'] ?>" class="user-checkbox"></td>
                        <td style="font-family: var(--font-mono); color: var(--text-muted); font-size: 13px;">#<?= htmlspecialchars($u['id']) ?></td>
                        <td>
                            <a href="edit-user.php?id=<?= $u['id'] ?>" class="profile-link">
                                <div class="avatar"><?= strtoupper(substr($u['first_name'] ?? 'U', 0, 1)) ?></div>
                                <div>
                                    <div class="profile-name"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted); font-family: var(--font-mono);"><i class="fa-regular fa-envelope" style="margin-right: 4px;"></i><?= htmlspecialchars($u['email']) ?></div>
                                </div>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($u['status'] ?? 'unverified') ?>">
                                <?= htmlspecialchars($u['status'] ?? 'Unknown') ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text);"><?= (int)$u['active_panels'] ?> Active Panel(s)</div>
                        </td>
                        <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);">
                            <?= date('M j, Y', strtotime($u['created_at'])) ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px;">
                                <form method="POST" action="users.php" style="margin: 0;"><?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['id']) ?>">
                                    <select name="status" class="status-select" onchange="this.form.submit()">
                                        <option value="active" <?= ($u['status'] ?? '') == 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="unverified" <?= ($u['status'] ?? '') == 'unverified' ? 'selected' : '' ?>>Unverified</option>
                                        <option value="banned" <?= ($u['status'] ?? '') == 'banned' ? 'selected' : '' ?>>Banned</option>
                                    </select>
                                    <input type="hidden" name="update_status" value="1">
                                </form>
                                <a href="edit-user.php?id=<?= $u['id'] ?>" class="btn-edit" title="Manage Client"><i class="fa-solid fa-pen"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
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

// Bulk Update Logic
function runBulkUpdate() {
    const status = document.getElementById('bulkStatusSelect').value;
    if (!status) {
        showToast('error', 'Please select a status from the dropdown first.');
        return;
    }
    
    const checked = document.querySelectorAll('.user-checkbox:checked');
    if (checked.length === 0) {
        showToast('error', 'Please select at least one user.');
        return;
    }
    
    if (!confirm(`Are you sure you want to change the status of ${checked.length} user(s) to ${status.toUpperCase()}?`)) {
        return;
    }

    const form = document.getElementById('bulkForm');
    document.getElementById('hiddenBulkStatus').value = status;
    
    const container = document.getElementById('hiddenUserInputs');
    container.innerHTML = ''; // clear old
    
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_users[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    // Theme Toggle
    const toggle = document.getElementById('adminThemeToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            const body = document.documentElement;
            const currentTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            body.setAttribute('data-theme', currentTheme);
            localStorage.setItem('admin_theme', currentTheme);
        });
    }

    // Master Checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>
</body>
</html>
