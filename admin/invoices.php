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

// --- HANDLE POST: BULK UPDATE INVOICE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_invoices'])) {
    $selected_invoices = $_POST['selected_invoices'] ?? [];
    $new_status = filter_input(INPUT_POST, 'invoice_status', FILTER_SANITIZE_SPECIAL_CHARS);
    $valid_inv_statuses = ['Paid', 'Unpaid', 'Cancelled'];
    
    if (!empty($selected_invoices) && in_array($new_status, $valid_inv_statuses)) {
        try {
            $processed = 0;
            foreach ($selected_invoices as $inv_id) {
                $pdo->beginTransaction();
                
                $chkStmt = $pdo->prepare("
                    SELECT i.status, i.panel_id, i.type, i.amount, i.user_id, p.billing_cycle, p.expiry_date 
                    FROM invoices i 
                    LEFT JOIN user_panels p ON i.panel_id = p.id 
                    WHERE i.id = ? FOR UPDATE
                ");
                $chkStmt->execute([$inv_id]);
                $invData = $chkStmt->fetch();
                
                // IF MARKING AS PAID
                if ($new_status === 'Paid' && $invData && $invData['status'] !== 'Paid') {
                    
                    // ROUTE A: Wallet Top-Up
                    if ($invData['type'] === 'topup' || strpos($invData['invoice_number'] ?? '', 'WAL-') === 0) {
                        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
                            ->execute([$invData['amount'], $invData['user_id']]);
                    }
                    // ROUTE B: Infrastructure Order/Renew
                    elseif (!empty($invData['panel_id'])) {
                        $cycle = $invData['billing_cycle'] ?? 'monthly';
                        $current_expiry = $invData['expiry_date'];
                        
                        $base_time = (empty($current_expiry) || strtotime($current_expiry) < time()) ? time() : strtotime($current_expiry);
                        
                        $months_to_add = 1;
                        if ($cycle === 'quarterly') $months_to_add = 3;
                        if ($cycle === 'semi_annually') $months_to_add = 6;
                        if ($cycle === 'yearly' || $cycle === 'annually') $months_to_add = 12;
                        
                        $new_expiry = date('Y-m-d H:i:s', strtotime("+$months_to_add months", $base_time));
                        
                        $pdo->prepare("UPDATE user_panels SET expiry_date = ?, status = 'active' WHERE id = ?")
                            ->execute([$new_expiry, $invData['panel_id']]);
                    }
                }
                
                $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$new_status, $inv_id]);
                $pdo->commit();
                $processed++;
            }
            
            $success = "Successfully updated $processed invoice(s). Extensions and Wallet top-ups applied where necessary.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = "Database error updating invoice statuses.";
        }
    } else {
        $error = "Please select at least one invoice and a valid status.";
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

// --- FETCH ALL INVOICES ---
try {
    $invStmt = $pdo->query("
        SELECT i.*, u.first_name, u.last_name, u.email 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        ORDER BY i.created_at DESC
    ");
    $invoicesList = $invStmt->fetchAll();
} catch (PDOException $e) {
    die("Database error while loading invoices.");
}

$page_title = 'Global Billing & Invoices';
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

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 24px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.1); color: var(--text); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    input[type="checkbox"] { appearance: none; width: 18px; height: 18px; border: 2px solid var(--border-strong); border-radius: 4px; background: var(--bg); cursor: pointer; position: relative; transition: 0.2s; }
    input[type="checkbox"]:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"]:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    .btn-bulk { background: rgba(139,92,246,0.1); color: var(--accent2); border: 1px solid rgba(139,92,246,0.3); padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; font-size: 13px; font-family: var(--font-body); }
    .btn-bulk:hover { background: var(--accent2); color: #fff; }

    .status-select { padding: 8px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; transition: 0.3s; cursor: pointer; margin-right: 8px; }
    .status-select:focus { border-color: var(--accent); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-Cancelled { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-Refunded { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }

    .badge-order { background: rgba(59,130,246,0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.2); }
    .badge-renew { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }
    .badge-topup { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }

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
    <div class="header-title">Financial Ledger</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    
    <form method="POST" action="invoices.php" class="card">
        <div class="card-title">
            <div style="color: var(--text);"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent-green); margin-right: 8px;"></i> Global Billing History</div>
            
            <div style="display: flex; align-items: center;">
                <select name="invoice_status" class="status-select" required>
                    <option value="">-- Bulk Update Status --</option>
                    <option value="Paid">Mark as Paid</option>
                    <option value="Unpaid">Mark as Unpaid</option>
                    <option value="Cancelled">Cancel Invoices</option>
                </select>
                <button type="submit" name="bulk_update_invoices" class="btn-bulk" onclick="return confirm('Update status for selected invoices? Note: Marking as Paid will automatically process wallet top-ups and panel extensions.');"><i class="fa-solid fa-check"></i> Apply</button>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="5%"><input type="checkbox" id="selectAllInvoices" title="Select All Invoices"></th>
                    <th width="15%">Invoice #</th>
                    <th width="10%">Type</th>
                    <th width="25%">Client Profile</th>
                    <th width="15%">Billing Period</th>
                    <th width="15%">Amount</th>
                    <th width="10%">Status</th>
                    <th width="5%">Issued</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($invoicesList)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 48px; color: var(--text-dim);">No invoices found in the system.</td></tr>
                <?php else: foreach($invoicesList as $inv): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_invoices[]" value="<?= $inv['id'] ?>" class="invoice-checkbox"></td>
                        <td style="font-family: var(--font-mono); font-weight: 600; color: var(--text);">
                            <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" style="color: var(--accent2); text-decoration: none; transition: 0.2s;">
                                <?= htmlspecialchars($inv['invoice_number']) ?>
                            </a>
                            <?php if(!empty($inv['panel_id'])): ?>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Panel #<?= $inv['panel_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($inv['type'] ?? 'order') ?>">
                                <?= htmlspecialchars($inv['type'] ?? 'order') ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 500; color: var(--text);"><?= htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($inv['email']) ?></div>
                        </td>
                        <td style="font-family: var(--font-mono); font-size: 11px; color: var(--text-muted);">
                            <?php 
                                if ($inv['period_start'] && $inv['period_end']) {
                                    echo date('M j, Y', strtotime($inv['period_start'])) . ' <i class="fa-solid fa-arrow-right" style="margin: 0 4px; opacity: 0.5;"></i> ' . date('M j, Y', strtotime($inv['period_end']));
                                } else {
                                    echo "N/A";
                                }
                            ?>
                        </td>
                        <td style="font-family: var(--font-mono); font-weight: 600; color: var(--text);">$<?= number_format($inv['amount'], 2) ?></td>
                        <td><span class="badge badge-<?= $inv['status'] ?>"><?= htmlspecialchars($inv['status']) ?></span></td>
                        <td style="color: var(--text-dim); font-size: 12px; font-family: var(--font-mono);"><?= date('M j, Y', strtotime($inv['created_at'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </form>

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

    // Checkboxes Check-All Logic
    const selectAllInvoices = document.getElementById('selectAllInvoices');
    if (selectAllInvoices) {
        selectAllInvoices.addEventListener('change', function() {
            document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>
</body>
</html>
