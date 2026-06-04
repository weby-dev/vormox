<?php
session_start();
require_once '../config.php'; 

// ==========================================
// 1. STRICT IP WHITELIST & SECURITY CHECK
// ==========================================
$user_ip = $_SERVER['REMOTE_ADDR'];

try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    $whitelist_count = $countStmt->fetchColumn();

    if ($whitelist_count > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) {
            header("Location: ../dashboard.php");
            exit;
        }
    }
} catch (PDOException $e) {
    die("Security verification failed.");
}

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// ==========================================
// 2. AGGREGATE DATABASE STATISTICS
// ==========================================
try {
    // Admin Info
    $adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
    $adminStmt->execute(['id' => $_SESSION['admin_id']]);
    $admin = $adminStmt->fetch();

    // User Stats
    $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $recentUsers = $pdo->query("SELECT id, first_name, last_name, email, created_at, wallet_balance FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();

    // Panel Stats
    $panelsCount = $pdo->query("SELECT COUNT(*) FROM user_panels")->fetchColumn();
    $activePanels = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status = 'active'")->fetchColumn();
    $recentPanels = $pdo->query("
        SELECT p.id, p.domain, p.status, p.created_at, u.first_name, u.last_name 
        FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        ORDER BY p.created_at DESC LIMIT 5
    ")->fetchAll();

    // Financial Stats
    $totalRevenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'Paid'")->fetchColumn();
    $pendingRevenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'Unpaid'")->fetchColumn();
    $recentInvoices = $pdo->query("
        SELECT i.invoice_number, i.amount, i.status, i.created_at, u.first_name, u.last_name 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        ORDER BY i.created_at DESC LIMIT 5
    ")->fetchAll();

    // Ticket Stats
    try {
        $openTickets = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND status != 'Resolved'")->fetchColumn();
    } catch (Exception $e) { $openTickets = 0; }

    // Pending Orders Aggregation
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
    $pendingStmt = $pdo->query("
        SELECT p.id, p.domain, p.status, p.created_at, u.first_name, u.last_name 
        FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status IN ('pending', 'payment_pending') 
        ORDER BY p.created_at ASC 
        LIMIT 5
    ");
    $recent_pending = $pendingStmt->fetchAll();

} catch (PDOException $e) {
    die("Database aggregation failed: " . $e->getMessage());
}

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Admin Control Plane';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    const initialTheme = savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', initialTheme);
  </script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    :root, [data-theme="dark"] {
      --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35;
      --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3);
      --accent: #a78bfa; --accent2: #8b5cf6; --accent-glow: rgba(139,92,246,0.35);
      --accent-green: #22d3ee; --accent-orange: #fb923c; --accent-red: #f87171;
      --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68;
      --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace;
      --font-body: 'Instrument Sans', sans-serif; --radius: 14px;
    }

    [data-theme="light"] {
      --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0;
      --border: #e2e8f0; --border-strong: #cbd5e1;
      --accent: #7c3aed; --accent2: #6d28d9; --accent-glow: rgba(124,58,237,0.15);
      --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
    }

    body { background: var(--bg); color: var(--text); font-family: var(--font-body); min-height: 100vh; display: flex; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    
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
    
    header { padding: 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 9999; background: rgba(5,8,16,.65); backdrop-filter: blur(20px); position: sticky; top: 0; }
    [data-theme="light"] header { background: rgba(255,255,255,0.8); }
    .header-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    
    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    [data-theme="dark"] .fa-moon { display: none; }
    [data-theme="light"] .fa-sun { display: none; }

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    [data-theme="light"] .stat-card { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .stat-icon { position: absolute; top: 24px; right: 24px; width: 40px; height: 40px; border-radius: 10px; background: rgba(139,92,246,0.1); color: var(--accent2); display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .stat-label { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; }
    .stat-value { font-family: var(--font-head); font-size: 36px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .stat-sub { font-size: 13px; color: var(--text-dim); }

    .split-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 32px; }
    @media (max-width: 1200px) { .split-grid { grid-template-columns: 1fr; } }
    
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; }
    .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(139,92,246,0.02); }
    .card-title { font-family: var(--font-head); font-size: 16px; font-weight: 700; color: var(--text); }
    
    .btn-ghost { padding: 6px 12px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-size: 12px; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-ghost:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    
    .btn-action { padding: 8px 16px; background: var(--accent2); color: #fff; font-size: 13px; font-weight: 600; font-family: var(--font-body); text-decoration: none; border-radius: 8px; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-action:hover { transform: translateY(-1px); filter: brightness(1.1); }
    .btn-outline { background: transparent; border: 1px solid var(--border-strong); color: var(--text); box-shadow: none; }
    .btn-outline:hover { background: var(--surface2); border-color: var(--accent); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 12px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text); transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.03); }

    .table-link { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
    .table-link:hover .link-title { color: var(--accent2); }
    
    .list-item { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); transition: background 0.2s; }
    .list-item:hover { background: rgba(139,92,246,0.02); }
    .list-item:last-child { border-bottom: none; }
    .item-meta { display: flex; flex-direction: column; gap: 4px; }
    .item-title { font-weight: 600; font-size: 15px; color: var(--text); }
    .item-sub { font-size: 13px; color: var(--text-muted); font-family: var(--font-mono); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-green { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-orange { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-purple { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.2); }
    .badge-gray { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }
  </style>
</head>
<body>

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
    <a href="panels.php" class="nav-item <?= $current_page == 'panels.php' ? 'active' : '' ?>"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item <?= $current_page == 'invoices.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item <?= $current_page == 'gateways.php' ? 'active' : '' ?>"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item <?= $current_page == 'tickets.php' ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item <?= $current_page == 'security.php' ? 'active' : '' ?>"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Global Settings</a>
  </nav>
  
  <div class="sidebar-footer">
    <div style="padding: 0 16px 16px; font-size: 13px; color: var(--text-muted);">
        Logged in as<br><strong style="color: var(--text);"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong>
    </div>
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);">
      <i class="fa-solid fa-arrow-right-from-bracket"></i> End Session
    </a>
  </div>
</aside>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title">Mission Control</div>
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
              <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
              <div class="stat-label">Total Users</div>
              <div class="stat-value"><?= number_format($usersCount) ?></div>
              <div class="stat-sub">Registered accounts</div>
          </div>
          <div class="stat-card">
              <div class="stat-icon" style="background: rgba(34,211,238,0.1); color: var(--accent-green);"><i class="fa-solid fa-server"></i></div>
              <div class="stat-label">Active Panels</div>
              <div class="stat-value"><?= number_format($activePanels) ?> <span style="font-size: 18px; color: var(--text-muted);">/ <?= number_format($panelsCount) ?></span></div>
              <div class="stat-sub">Currently provisioned</div>
          </div>
          <div class="stat-card">
              <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: #10b981;"><i class="fa-solid fa-dollar-sign"></i></div>
              <div class="stat-label">Net Revenue</div>
              <div class="stat-value">$<?= number_format($totalRevenue, 2) ?></div>
              <div class="stat-sub">$<?= number_format($pendingRevenue, 2) ?> pending collection</div>
          </div>
          <div class="stat-card">
              <div class="stat-icon" style="background: rgba(251,146,60,0.1); color: var(--accent-orange);"><i class="fa-solid fa-headset"></i></div>
              <div class="stat-label">Open Tickets</div>
              <div class="stat-value"><?= number_format($openTickets) ?></div>
              <div class="stat-sub">Awaiting staff response</div>
          </div>
      </div>

      <?php if ($pendingOrdersCount > 0): ?>
      <div class="card" style="margin-bottom: 32px; border-color: rgba(251,146,60,0.4); box-shadow: 0 0 20px rgba(251,146,60,0.1);">
          <div class="card-header" style="background: rgba(251,146,60,0.05); padding: 16px 24px;">
              <div class="card-title" style="color: var(--accent-orange);"><i class="fa-solid fa-wand-magic-sparkles"></i> Action Required: Pending Orders</div>
              <a href="orders.php" class="btn-action btn-outline" style="border-color: var(--accent-orange); color: var(--accent-orange); padding: 6px 12px;">Review All (<?= $pendingOrdersCount ?>)</a>
          </div>
          <div>
              <?php foreach($recent_pending as $order): ?>
                  <div class="list-item">
                      <div class="item-meta">
                          <div class="item-title"><?= htmlspecialchars($order['domain']) ?></div>
                          <div class="item-sub">Client: <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?> &bull; Order #<?= $order['id'] ?></div>
                      </div>
                      <div style="display: flex; align-items: center; gap: 16px;">
                          <span class="badge <?= $order['status'] == 'pending' ? 'badge-purple' : 'badge-orange' ?>">
                              <?= htmlspecialchars(str_replace('_', ' ', $order['status'])) ?>
                          </span>
                          <a href="orders.php?action=fulfill&id=<?= $order['id'] ?>" class="btn-action" style="background: var(--accent-orange); box-shadow: 0 4px 15px rgba(251,146,60,0.3);"><i class="fa-solid fa-arrow-right"></i> Fulfill Order</a>
                      </div>
                  </div>
              <?php endforeach; ?>
          </div>
      </div>
      <?php endif; ?>

      <div class="split-grid">
          
          <div class="card">
              <div class="card-header">
                  <div class="card-title">Recent Signups</div>
                  <a href="users.php" class="btn-ghost">View All</a>
              </div>
              <table>
                  <thead>
                      <tr>
                          <th>Client</th>
                          <th>Joined</th>
                          <th>Wallet</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if(empty($recentUsers)): ?>
                          <tr><td colspan="3" style="text-align: center; color: var(--text-dim);">No users found.</td></tr>
                      <?php else: foreach($recentUsers as $u): ?>
                          <tr>
                              <td>
                                  <a href="users.php" class="table-link">
                                      <div class="link-title" style="font-weight: 600;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                      <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                                  </a>
                              </td>
                              <td style="color: var(--text-muted); font-size: 13px;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                              <td style="font-family: var(--font-mono); font-weight: 600;">$<?= number_format($u['wallet_balance'], 2) ?></td>
                          </tr>
                      <?php endforeach; endif; ?>
                  </tbody>
              </table>
          </div>

          <div class="card">
              <div class="card-header">
                  <div class="card-title">Recent Transactions</div>
                  <a href="invoices.php" class="btn-ghost">View All</a>
              </div>
              <table>
                  <thead>
                      <tr>
                          <th>Invoice / Client</th>
                          <th>Amount</th>
                          <th>Status</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if(empty($recentInvoices)): ?>
                          <tr><td colspan="3" style="text-align: center; color: var(--text-dim);">No transactions found.</td></tr>
                      <?php else: foreach($recentInvoices as $inv): 
                          $badgeClass = $inv['status'] === 'Paid' ? 'badge-green' : ($inv['status'] === 'Unpaid' ? 'badge-orange' : 'badge-gray');
                      ?>
                          <tr>
                              <td>
                                  <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" class="table-link">
                                      <div class="link-title" style="font-family: var(--font-mono); font-size: 12px; margin-bottom: 4px;"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                                      <div style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']) ?></div>
                                  </a>
                              </td>
                              <td style="font-family: var(--font-mono); font-weight: 600;">$<?= number_format($inv['amount'], 2) ?></td>
                              <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($inv['status']) ?></span></td>
                          </tr>
                      <?php endforeach; endif; ?>
                  </tbody>
              </table>
          </div>

      </div>

      <div class="card" style="margin-top: 32px;">
          <div class="card-header">
              <div class="card-title">Latest Provisioning Requests</div>
              <a href="panels.php" class="btn-ghost">Manage Panels</a>
          </div>
          <table>
              <thead>
                  <tr>
                      <th>Domain</th>
                      <th>Client</th>
                      <th>Status</th>
                      <th>Created</th>
                      <th style="text-align: right;">Action</th>
                  </tr>
              </thead>
              <tbody>
                  <?php if(empty($recentPanels)): ?>
                      <tr><td colspan="5" style="text-align: center; color: var(--text-dim);">No panels found.</td></tr>
                  <?php else: foreach($recentPanels as $p): 
                      $pBadge = 'badge-gray';
                      if($p['status'] == 'active') $pBadge = 'badge-green';
                      if($p['status'] == 'payment_pending') $pBadge = 'badge-orange';
                      if($p['status'] == 'creating') $pBadge = 'badge-purple';
                  ?>
                      <tr>
                          <td style="font-weight: 600;"><?= htmlspecialchars($p['domain']) ?></td>
                          <td style="color: var(--text-muted);"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></td>
                          <td><span class="badge <?= $pBadge ?>"><?= str_replace('_', ' ', htmlspecialchars($p['status'])) ?></span></td>
                          <td style="color: var(--text-muted); font-size: 13px;"><?= date('M j, g:i A', strtotime($p['created_at'])) ?></td>
                          <td style="text-align: right;">
                              <a href="panels.php" class="btn-ghost">Manage</a>
                          </td>
                      </tr>
                  <?php endforeach; endif; ?>
              </tbody>
          </table>
      </div>

  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('adminThemeToggle').addEventListener('click', function() {
        const body = document.documentElement;
        const currentTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        body.setAttribute('data-theme', currentTheme);
        localStorage.setItem('admin_theme', currentTheme);
    });
});
</script>

</body>
</html>
