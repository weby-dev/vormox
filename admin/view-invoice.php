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
$invoice_num = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$invoice_num) { header("Location: invoices.php"); exit; }

// --- UPDATE INVOICE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
    $valid_statuses = ['Paid', 'Unpaid', 'Cancelled', 'Refunded'];
    
    if (in_array($new_status, $valid_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE invoices SET status = :status WHERE invoice_number = :id");
            $stmt->execute(['status' => $new_status, 'id' => $invoice_num]);
            $success = "Invoice status updated to " . strtoupper($new_status) . ".";
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

// --- FETCH SPECIFIC INVOICE ---
try {
    $stmt = $pdo->prepare("
        SELECT i.*, u.first_name, u.last_name, u.email 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        WHERE i.invoice_number = :id LIMIT 1
    ");
    $stmt->execute(['id' => $invoice_num]);
    $invoice = $stmt->fetch();

    if (!$invoice) { die("Invoice not found."); }
} catch (PDOException $e) {
    die("Database error while loading invoice.");
}

$page_title = 'Invoice ' . $invoice['invoice_number'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;700&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1000px; margin: 0 auto; width: 100%; }

    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; margin-bottom: 24px; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }
    
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; background: var(--surface); padding: 20px 24px; border: 1px solid var(--border); border-radius: 14px; }
    
    .btn { padding: 10px 20px; font-family: var(--font-body); font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer; transition: 0.2s; border: none; display: inline-flex; align-items: center; gap: 8px; color: var(--text); background: var(--surface2); }
    .btn:hover { background: var(--border); }
    .btn-primary { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-primary:hover { filter: brightness(1.1); background: var(--accent2); color: #fff; }

    .status-select { padding: 10px 16px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-mono); font-size: 13px; outline: none; text-transform: uppercase; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }

    /* INVOICE DOCUMENT STYLES */
    .invoice-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 64px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
    [data-theme="light"] .invoice-card { background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    
    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 64px; }
    .inv-brand { font-family: var(--font-head); font-size: 28px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 12px; }
    .inv-brand .icon { width: 40px; height: 40px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
    .inv-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; text-align: right; }
    
    .inv-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-bottom: 64px; }
    .meta-block h4 { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 12px; }
    .meta-block p { color: var(--text); font-size: 15px; line-height: 1.6; margin-bottom: 4px; }
    .meta-block strong { font-weight: 600; }
    
    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 48px; }
    .inv-table th { padding: 16px; font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); text-transform: uppercase; border-bottom: 2px solid var(--border-strong); text-align: left; }
    .inv-table th.right { text-align: right; }
    .inv-table td { padding: 24px 16px; border-bottom: 1px solid var(--border); font-size: 15px; color: var(--text); }
    .inv-table td.right { text-align: right; font-family: var(--font-mono); font-weight: 600; }
    
    .inv-summary { width: 300px; margin-left: auto; }
    .summary-row { display: flex; justify-content: space-between; padding: 12px 0; font-size: 15px; color: var(--text-muted); }
    .summary-row.total { border-top: 2px solid var(--border-strong); margin-top: 12px; padding-top: 20px; font-size: 20px; font-weight: 700; color: var(--text); font-family: var(--font-head); }
    
    .badge { padding: 6px 12px; border-radius: 100px; font-size: 11px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); display: inline-block; letter-spacing: 0.05em; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-Refunded { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }
    .badge-Cancelled { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    /* Print Styles */
    @media print {
        body { background: #fff; color: #000; }
        aside, header, .toolbar, .btn-back, #toast-container { display: none !important; }
        main { height: auto; overflow: visible; }
        .content-area { padding: 0; max-width: 100%; }
        .invoice-card { box-shadow: none; border: none; padding: 0; background: #fff; }
        .inv-brand, .inv-title, .meta-block p, .inv-table td, .summary-row { color: #000 !important; }
        .meta-block h4, .inv-table th { color: #555 !important; border-color: #ddd !important; }
        .inv-table td { border-color: #eee !important; }
        .summary-row.total { border-color: #000 !important; }
        .badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
    }

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
    <a href="invoices.php" class="nav-item <?= in_array($current_page, ['invoices.php', 'view-invoice.php']) ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
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
    <div class="header-title">Invoice Viewer</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <a href="invoices.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Ledger</a>

    <div class="toolbar">
        <form method="POST" action="view-invoice.php?id=<?= urlencode($invoice_num) ?>" style="display: flex; gap: 12px; align-items: center; margin: 0;">
            <label style="font-size: 12px; font-family: var(--font-mono); color: var(--text-muted); text-transform: uppercase;">Payment Status:</label>
            <select name="status" class="status-select">
                <option value="Unpaid" <?= $invoice['status']=='Unpaid'?'selected':'' ?>>Unpaid</option>
                <option value="Paid" <?= $invoice['status']=='Paid'?'selected':'' ?>>Paid</option>
                <option value="Refunded" <?= $invoice['status']=='Refunded'?'selected':'' ?>>Refunded</option>
                <option value="Cancelled" <?= $invoice['status']=='Cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
            <button type="submit" name="update_status" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </form>

        <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Print / PDF</button>
    </div>

    <div class="invoice-card">
        
        <div class="inv-header">
            <div class="inv-brand">
                <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
                Vormox
            </div>
            <div class="inv-title">Invoice</div>
        </div>

        <div class="inv-meta-grid">
            <div class="meta-block">
                <h4>Billed To</h4>
                <p><strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong></p>
                <p style="font-family: var(--font-mono); font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($invoice['email']) ?></p>
            </div>
            
            <div class="meta-block" style="text-align: right;">
                <h4>Invoice Details</h4>
                <p><strong>Invoice No:</strong> <span style="font-family: var(--font-mono);"><?= htmlspecialchars($invoice['invoice_number']) ?></span></p>
                <p><strong>Date Issued:</strong> <?= date('F j, Y', strtotime($invoice['created_at'])) ?></p>
                <p style="margin-top: 12px;"><span class="badge badge-<?= htmlspecialchars($invoice['status']) ?>"><?= htmlspecialchars($invoice['status']) ?></span></p>
            </div>
        </div>

        <table class="inv-table">
            <thead>
                <tr>
                    <th width="70%">Description</th>
                    <th width="30%" class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Infrastructure / Service Provisioning</strong><br>
                        <span style="font-size: 13px; color: var(--text-muted);">Standard billing charge for Vormox services.</span>
                    </td>
                    <td class="right">$<?= number_format($invoice['amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="inv-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span style="font-family: var(--font-mono);">$<?= number_format($invoice['amount'], 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Tax (0%)</span>
                <span style="font-family: var(--font-mono);">$0.00</span>
            </div>
            <div class="summary-row total">
                <span>Total</span>
                <span>$<?= number_format($invoice['amount'], 2) ?></span>
            </div>
        </div>

        <div style="margin-top: 80px; padding-top: 32px; border-top: 1px solid var(--border); text-align: center; color: var(--text-dim); font-size: 12px;">
            Thank you for doing business with Vormox. <br>
            If you have any questions regarding this invoice, please open a support ticket.
        </div>
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
