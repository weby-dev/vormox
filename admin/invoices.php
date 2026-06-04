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

$success = ''; $error = '';

// --- UPDATE INVOICE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $valid_statuses = ['Paid', 'Unpaid', 'Cancelled', 'Refunded'];
    
    if ($invoice_id && in_array($new_status, $valid_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE invoices SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $new_status, 'id' => $invoice_id]);
            $success = "Invoice status updated successfully to " . strtoupper($new_status) . ".";
        } catch (PDOException $e) {
            $error = "Failed to update invoice status.";
        }
    } else {
        $error = "Invalid status selected.";
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

// --- FETCH FINANCIAL STATS & INVOICES ---
try {
    $totalPaid = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'Paid'")->fetchColumn();
    $totalUnpaid = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'Unpaid'")->fetchColumn();
    $totalInvoices = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

    $invoicesStmt = $pdo->query("
        SELECT i.*, u.first_name, u.last_name, u.email 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        ORDER BY i.created_at DESC
    ");
    $invoices = $invoicesStmt->fetchAll();
} catch (PDOException $e) {
    die("Database error while loading invoices.");
}

$page_title = 'Financial Invoices';
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
    .stat-icon { position: absolute; top: 24px; right: 24px; width: 40px; height: 40px; border-radius: 10px; background: rgba(139,92,246,0.1); color: var(--accent2); display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .stat-label { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; }
    .stat-value { font-family: var(--font-head); font-size: 36px; font-weight: 700; color: var(--text); margin-bottom: 4px; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .card-title { padding: 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.1); }
    
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    .table-link { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
    .table-link:hover .link-title { color: var(--accent2); }

    .status-select { padding: 8px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; text-transform: uppercase; transition: 0.3s; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }
    .inline-form { display: flex; gap: 8px; align-items: center; margin: 0; }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-Refunded { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }
    .badge-Cancelled { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    /* Toast Notifications */
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
    <div class="header-title">Financial Ledgers</div>
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
            <div class="stat-icon" style="background: rgba(34,211,238,0.1); color: var(--accent-green);"><i class="fa-solid fa-wallet"></i></div>
            <div class="stat-label">Total Revenue Collected</div>
            <div class="stat-value">$<?= number_format($totalPaid, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(251,146,60,0.1); color: var(--accent-orange);"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-label">Pending Collection</div>
            <div class="stat-value">$<?= number_format($totalUnpaid, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-label">Total Invoices Generated</div>
            <div class="stat-value"><?= number_format($totalInvoices) ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent);"></i> All Invoices
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="15%">Invoice ID</th>
                    <th width="25%">Client Identity</th>
                    <th width="15%">Amount</th>
                    <th width="15%">Current Status</th>
                    <th width="15%">Update State</th>
                    <th width="15%">Date Issued</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($invoices)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 48px; color: var(--text-dim);">No invoices found in the database.</td></tr>
                <?php else: foreach($invoices as $inv): ?>
                    <tr>
                        <td>
                            <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" class="table-link">
                                <div class="link-title" style="font-weight: 600; font-family: var(--font-mono); font-size: 13px; color: var(--text);"><i class="fa-solid fa-link" style="font-size: 11px; margin-right: 6px; color: var(--text-muted);"></i><?= htmlspecialchars($inv['invoice_number']) ?></div>
                            </a>
                        </td>
                        <td>
                            <div style="font-size: 14px; font-weight: 600; color: var(--text);"><?= htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']) ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($inv['email']) ?></div>
                        </td>
                        <td style="font-family: var(--font-mono); font-weight: 700; font-size: 15px; color: <?= $inv['status'] === 'Paid' ? 'var(--accent-green)' : 'var(--text)' ?>;">
                            $<?= number_format($inv['amount'], 2) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($inv['status']) ?>">
                                <?= htmlspecialchars($inv['status']) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="invoices.php" class="inline-form">
                                <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($inv['id']) ?>">
                                <select name="status" class="status-select" onchange="this.form.submit()">
                                    <option value="Unpaid" <?= $inv['status']=='Unpaid'?'selected':'' ?>>Unpaid</option>
                                    <option value="Paid" <?= $inv['status']=='Paid'?'selected':'' ?>>Paid</option>
                                    <option value="Refunded" <?= $inv['status']=='Refunded'?'selected':'' ?>>Refunded</option>
                                    <option value="Cancelled" <?= $inv['status']=='Cancelled'?'selected':'' ?>>Cancelled</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        </td>
                        <td style="color: var(--text-dim); font-size: 13px;">
                            <?= date('M j, Y', strtotime($inv['created_at'])) ?>
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
});
</script>
</body>
</html>
