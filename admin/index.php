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

$current_page = basename($_SERVER['PHP_SELF']);

// --- FETCH ADMIN DETAILS ---
$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- FETCH DASHBOARD METRICS ---
try {
    // 1. Pending Orders Badge
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();

    // 2. User Stats
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

    // 3. Infrastructure Stats
    $activePanels = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status = 'active'")->fetchColumn();
    $totalNodes = $pdo->query("SELECT SUM(nodes_count) FROM user_panels WHERE status IN ('active', 'creating', 'restarting')")->fetchColumn() ?: 0;

    // 4. Financial Stats (This Month)
    $stmtRevenue = $pdo->query("SELECT SUM(amount) FROM invoices WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $monthlyRevenue = $stmtRevenue->fetchColumn() ?: 0.00;

    $stmtPendingRev = $pdo->query("SELECT SUM(amount) FROM invoices WHERE status = 'Unpaid'");
    $pendingRevenue = $stmtPendingRev->fetchColumn() ?: 0.00;

    // 5. Support Stats
    $openTickets = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed'")->fetchColumn() ?: 0;

    // 6. Recent Deployments (Last 5)
    $recentPanels = $pdo->query("
        SELECT p.id, p.domain, p.status, p.created_at, u.first_name, u.last_name 
        FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        ORDER BY p.created_at DESC LIMIT 5
    ")->fetchAll();

    // 7. Recent Invoices (Last 5)
    $recentInvoices = $pdo->query("
        SELECT i.invoice_number, i.amount, i.status, i.created_at, u.first_name, u.last_name 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        ORDER BY i.created_at DESC LIMIT 5
    ")->fetchAll();

    // 8. Recent Tickets (Last 5)
    $recentTickets = $pdo->query("
        SELECT t.id, t.subject, t.status, t.created_at, u.first_name, u.last_name 
        FROM tickets t 
        JOIN users u ON t.user_id = u.id 
        ORDER BY t.created_at DESC LIMIT 5
    ")->fetchAll();

    // 9. Chart Data (Revenue Last 6 Months)
    $chartLabels = [];
    $chartData = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('m', strtotime("-$i month"));
        $y = date('Y', strtotime("-$i month"));
        $chartLabels[] = date('M', strtotime("-$i month"));
        
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM invoices WHERE status = 'Paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $stmt->execute([$m, $y]);
        $chartData[] = (float)($stmt->fetchColumn() ?: 0);
    }

} catch (PDOException $e) {
    die("Database error while loading dashboard metrics.");
}

$page_title = 'Command Center';
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  
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

    /* Dashboard Specific Styles */
    .welcome-text { font-size: 16px; color: var(--text-muted); margin-bottom: 32px; }
    .welcome-text strong { color: var(--text); font-weight: 600; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-bottom: 32px; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.15); border-color: var(--accent); }
    .stat-icon { position: absolute; top: 24px; right: 24px; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .stat-label { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; font-weight: 600; }
    .stat-value { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
    .stat-sub { font-size: 13px; color: var(--text-dim); }
    .stat-sub strong { color: var(--text); font-weight: 600; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; margin-bottom: 32px; }
    .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; color: var(--text); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.1); }
    .card-body { padding: 24px; flex: 1; }

    .chart-container { position: relative; height: 350px; width: 100%; }

    /* Feed Grid (3 Columns) */
    .feed-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 32px; }
    .feed-list { list-style: none; padding: 0; margin: 0; }
    .feed-item { display: flex; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--border); }
    .feed-item:last-child { border-bottom: none; padding-bottom: 0; }
    .feed-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--surface2); border: 1px solid var(--border-strong); display: flex; align-items: center; justify-content: center; font-size: 14px; color: var(--text); flex-shrink: 0; }
    .feed-details { flex: 1; min-width: 0; }
    .feed-title { font-weight: 600; font-size: 14px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }
    .feed-time { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); }
    
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 10px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); letter-spacing: 0.05em; display: inline-block; white-space: nowrap; }
    .badge-active, .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-payment_pending, .badge-pending, .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-creating, .badge-Open { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.2); }
    .badge-error, .badge-Closed, .badge-Cancelled { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .btn-small { padding: 6px 12px; background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; transition: 0.2s; display: inline-block; }
    .btn-small:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
  </style>
</head>
<body>

<aside>
  <a href="index.php" class="logo"><div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>Vormox <span>Admin</span></a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item active"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if($pendingOrdersCount > 0): ?><span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span><?php endif; ?>
    </a>
    <a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    <a href="backups.php" class="nav-item <?= $current_page == 'backups.php' ? 'active' : '' ?>"><i class="fa-solid fa-clock-rotate-left"></i> Backups</a>
    
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
    <div class="header-title">Command Center</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    
    <div class="welcome-text">
        Welcome back, <strong><?= htmlspecialchars($admin['first_name']) ?></strong>. Here's what's happening with Vormox Infrastructure today.
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
        <!-- Revenue -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(34,211,238,0.1); color: var(--accent-green);"><i class="fa-solid fa-vault"></i></div>
            <div class="stat-label">Monthly Revenue</div>
            <div class="stat-value">$<?= number_format($monthlyRevenue, 2) ?></div>
            <div class="stat-sub"><strong>$<?= number_format($pendingRevenue, 2) ?></strong> pending in unpaid invoices</div>
        </div>

        <!-- Infrastructure -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139,92,246,0.1); color: var(--accent2);"><i class="fa-solid fa-server"></i></div>
            <div class="stat-label">Active Deployments</div>
            <div class="stat-value"><?= number_format($activePanels) ?></div>
            <div class="stat-sub">Across <strong><?= number_format($totalNodes) ?></strong> allocated compute nodes</div>
        </div>

        <!-- Users -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: #3b82f6;"><i class="fa-solid fa-users"></i></div>
            <div class="stat-label">Total Clients</div>
            <div class="stat-value"><?= number_format($totalUsers) ?></div>
            <div class="stat-sub"><strong><?= number_format($activeUsers) ?></strong> accounts actively verified</div>
        </div>

        <!-- Actions -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(251,146,60,0.1); color: var(--accent-orange);"><i class="fa-solid fa-bell"></i></div>
            <div class="stat-label">Attention Required</div>
            <div class="stat-value"><?= number_format($pendingOrdersCount + $openTickets) ?></div>
            <div class="stat-sub"><strong><?= $pendingOrdersCount ?></strong> orders / <strong><?= $openTickets ?></strong> open tickets</div>
        </div>
    </div>

    <!-- CHART SECTION -->
    <div class="card">
        <div class="card-header">
            <div><i class="fa-solid fa-chart-line" style="margin-right: 8px; color: var(--accent);"></i> Revenue Growth (6 Months)</div>
            <a href="invoices.php" class="btn-small">View All</a>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 3-COLUMN ACTIVITY CENTER -->
    <div class="feed-grid">
        
        <!-- RECENT PANELS -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <div><i class="fa-solid fa-server" style="margin-right: 8px; color: var(--accent-green);"></i> Deployments</div>
                <a href="panels.php" class="btn-small">Manage</a>
            </div>
            <div class="card-body" style="padding: 16px 24px;">
                <ul class="feed-list">
                    <?php if(empty($recentPanels)): ?>
                        <div style="text-align: center; color: var(--text-dim); padding: 32px 0;">No recent activity.</div>
                    <?php else: foreach($recentPanels as $rp): ?>
                        <li class="feed-item">
                            <div class="feed-icon">
                                <?php if($rp['status'] == 'active') echo '<i class="fa-solid fa-check" style="color: var(--accent-green);"></i>';
                                      elseif(in_array($rp['status'], ['pending', 'payment_pending'])) echo '<i class="fa-solid fa-hourglass-half" style="color: var(--accent-orange);"></i>';
                                      else echo '<i class="fa-solid fa-terminal" style="color: var(--accent2);"></i>';
                                ?>
                            </div>
                            <div class="feed-details">
                                <div class="feed-title"><?= htmlspecialchars($rp['domain']) ?></div>
                                <div class="feed-time">By <?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']) ?> • <?= date('M j', strtotime($rp['created_at'])) ?></div>
                            </div>
                            <div><span class="badge badge-<?= $rp['status'] ?>"><?= htmlspecialchars(str_replace('_', ' ', $rp['status'])) ?></span></div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>

        <!-- RECENT INVOICES -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <div><i class="fa-solid fa-file-invoice-dollar" style="margin-right: 8px; color: var(--accent2);"></i> Billing</div>
                <a href="invoices.php" class="btn-small">View All</a>
            </div>
            <div class="card-body" style="padding: 16px 24px;">
                <ul class="feed-list">
                    <?php if(empty($recentInvoices)): ?>
                        <div style="text-align: center; color: var(--text-dim); padding: 32px 0;">No recent invoices.</div>
                    <?php else: foreach($recentInvoices as $ri): ?>
                        <li class="feed-item">
                            <div class="feed-icon">
                                <?php if($ri['status'] == 'Paid') echo '<i class="fa-solid fa-dollar-sign" style="color: var(--accent-green);"></i>';
                                      elseif($ri['status'] == 'Unpaid') echo '<i class="fa-solid fa-clock" style="color: var(--accent-orange);"></i>';
                                      else echo '<i class="fa-solid fa-xmark" style="color: var(--accent-red);"></i>';
                                ?>
                            </div>
                            <div class="feed-details">
                                <div class="feed-title"><?= htmlspecialchars($ri['invoice_number']) ?> — <span style="font-family: var(--font-mono); color: var(--text);">$<?= number_format($ri['amount'], 2) ?></span></div>
                                <div class="feed-time">To <?= htmlspecialchars($ri['first_name'] . ' ' . $ri['last_name']) ?> • <?= date('M j', strtotime($ri['created_at'])) ?></div>
                            </div>
                            <div><span class="badge badge-<?= $ri['status'] ?>"><?= htmlspecialchars($ri['status']) ?></span></div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>

        <!-- RECENT TICKETS -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <div><i class="fa-solid fa-headset" style="margin-right: 8px; color: var(--accent-orange);"></i> Support Queue</div>
                <a href="tickets.php" class="btn-small">Reply</a>
            </div>
            <div class="card-body" style="padding: 16px 24px;">
                <ul class="feed-list">
                    <?php if(empty($recentTickets)): ?>
                        <div style="text-align: center; color: var(--text-dim); padding: 32px 0;">No recent tickets.</div>
                    <?php else: foreach($recentTickets as $rt): ?>
                        <li class="feed-item">
                            <div class="feed-icon">
                                <?php if($rt['status'] == 'Closed') echo '<i class="fa-solid fa-lock" style="color: var(--text-muted);"></i>';
                                      else echo '<i class="fa-solid fa-envelope-open" style="color: var(--accent2);"></i>';
                                ?>
                            </div>
                            <div class="feed-details">
                                <div class="feed-title">#<?= htmlspecialchars($rt['id']) ?> - <?= htmlspecialchars($rt['subject']) ?></div>
                                <div class="feed-time">By <?= htmlspecialchars($rt['first_name'] . ' ' . $rt['last_name']) ?> • <?= date('M j', strtotime($rt['created_at'])) ?></div>
                            </div>
                            <div><span class="badge badge-<?= str_replace('-', '', $rt['status']) ?>"><?= htmlspecialchars(str_replace('-', ' ', $rt['status'])) ?></span></div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>

    </div>

  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Theme Toggle Logic
    const toggle = document.getElementById('adminThemeToggle');
    const htmlElement = document.documentElement;

    function getThemeColor() {
        return htmlElement.getAttribute('data-theme') === 'light' ? '#cbd5e1' : 'rgba(139,92,246,0.15)';
    }
    function getTextColor() {
        return htmlElement.getAttribute('data-theme') === 'light' ? '#64748b' : '#7a8aa8';
    }

    if (toggle) {
        toggle.addEventListener('click', function() {
            const currentTheme = htmlElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            htmlElement.setAttribute('data-theme', currentTheme);
            localStorage.setItem('admin_theme', currentTheme);
            
            // Update Chart Colors Dynamically
            if (window.revenueChart) {
                window.revenueChart.options.scales.x.grid.color = getThemeColor();
                window.revenueChart.options.scales.y.grid.color = getThemeColor();
                window.revenueChart.options.scales.x.ticks.color = getTextColor();
                window.revenueChart.options.scales.y.ticks.color = getTextColor();
                window.revenueChart.update();
            }
        });
    }

    // Chart.js Setup
    const ctx = document.getElementById('revenueChart').getContext('2d');
    
    // Gradient Fill for Chart
    let gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(139, 92, 246, 0.4)'); // var(--accent-glow)
    gradient.addColorStop(1, 'rgba(139, 92, 246, 0.0)');

    window.revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Paid Revenue ($)',
                data: <?= json_encode($chartData) ?>,
                borderColor: '#8b5cf6', // var(--accent2)
                backgroundColor: gradient,
                borderWidth: 3,
                pointBackgroundColor: '#050810',
                pointBorderColor: '#8b5cf6',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4 // Smooth curves
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(13, 20, 38, 0.9)',
                    titleFont: { family: 'JetBrains Mono', size: 13 },
                    bodyFont: { family: 'Instrument Sans', size: 14, weight: 'bold' },
                    padding: 12,
                    borderColor: 'rgba(139, 92, 246, 0.3)',
                    borderWidth: 1,
                    displayColors: false,
                    callbacks: {
                        label: function(context) { return '$' + context.parsed.y.toFixed(2); }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: getThemeColor(), drawBorder: false },
                    ticks: { color: getTextColor(), font: { family: 'JetBrains Mono', size: 11 }, callback: function(value) { return '$' + value; } }
                },
                x: {
                    grid: { color: getThemeColor(), drawBorder: false },
                    ticks: { color: getTextColor(), font: { family: 'JetBrains Mono', size: 11 } }
                }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });
});
</script>
</body>
</html>
