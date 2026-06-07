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
$invoice_number = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$invoice_number) { header("Location: invoices.php"); exit; }

// --- HANDLE POST: UPDATE INVOICE STATUS (Auto-Extend & Wallet Logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_invoice'])) {
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
    $valid_statuses = ['Paid', 'Unpaid', 'Cancelled', 'Refunded'];
    
    if (in_array($new_status, $valid_statuses)) {
        try {
            $pdo->beginTransaction();
            
            // Fetch current invoice state
            $chkStmt = $pdo->prepare("
                SELECT i.id, i.status, i.type, i.amount, i.user_id, i.panel_id, p.billing_cycle, p.expiry_date 
                FROM invoices i 
                LEFT JOIN user_panels p ON i.panel_id = p.id 
                WHERE i.invoice_number = ? LIMIT 1
            ");
            $chkStmt->execute([$invoice_number]);
            $invData = $chkStmt->fetch();
            
            if ($invData) {
                // IF MARKING AS PAID
                if ($new_status === 'Paid' && $invData['status'] !== 'Paid') {
                    
                    // ROUTE A: Wallet Top-Up
                    if ($invData['type'] === 'topup' || strpos($invoice_number, 'WAL-') === 0) {
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
                
                // Update Invoice
                $pdo->prepare("UPDATE invoices SET status = ? WHERE invoice_number = ?")->execute([$new_status, $invoice_number]);
                $pdo->commit();
                $success = "Invoice status updated to " . strtoupper($new_status) . ".";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error updating invoice.";
        }
    }
}

// --- FETCH INVOICE DETAILS ---
try {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               u.first_name, u.last_name, u.email, u.id as client_id,
               p.domain, p.nodes_count, p.billing_cycle
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        LEFT JOIN user_panels p ON i.panel_id = p.id 
        WHERE i.invoice_number = :inv LIMIT 1
    ");
    $stmt->execute(['inv' => $invoice_number]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) { die("Invoice not found."); }
} catch (PDOException $e) {
    die("Database error while loading invoice.");
}

// --- FETCH NOTIFICATIONS DATA ---
$current_page = 'invoices.php';
try { $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn(); } 
catch (PDOException $e) { $pendingOrdersCount = 0; }

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

$page_title = 'Invoice ' . $invoice['invoice_number'];
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1000px; margin: 0 auto; width: 100%; }
    
    .top-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }

    .status-select { padding: 8px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 13px; outline: none; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }
    .btn-update { background: var(--accent2); color: #fff; border: none; padding: 9px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: var(--font-body); font-size: 13px; transition: 0.2s; }
    .btn-update:hover { filter: brightness(1.1); }

    /* Invoice Paper UI */
    .invoice-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .invoice-header { padding: 40px; display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border); }
    .invoice-header-left h2 { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); margin-bottom: 8px; letter-spacing: -0.02em; }
    .invoice-header-left p { color: var(--text-muted); font-family: var(--font-mono); font-size: 13px; }
    
    .badge { padding: 6px 14px; border-radius: 100px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-flex; align-items: center; gap: 6px; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-Cancelled { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-Refunded { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }

    .invoice-meta { display: grid; grid-template-columns: 1fr 1fr; padding: 40px; gap: 32px; background: rgba(0,0,0,0.1); border-bottom: 1px solid var(--border); }
    .meta-box h4 { font-size: 12px; text-transform: uppercase; color: var(--text-dim); letter-spacing: 0.05em; margin-bottom: 12px; font-family: var(--font-mono); }
    .meta-box p { font-size: 15px; color: var(--text); line-height: 1.6; font-weight: 500; }
    .meta-box strong { color: var(--text); font-weight: 700; }
    .meta-box a { color: var(--accent2); text-decoration: none; }
    .meta-box a:hover { text-decoration: underline; }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 40px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 24px 40px; border-bottom: 1px solid var(--border); font-size: 15px; vertical-align: top; color: var(--text); }
    tr:last-child td { border-bottom: none; }

    .total-section { padding: 32px 40px; background: var(--bg2); display: flex; justify-content: flex-end; align-items: center; border-top: 1px solid var(--border-strong); }
    .total-label { font-size: 14px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-right: 24px; font-family: var(--font-mono); }
    .total-amount { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--accent2); }

    .gateway-logs { padding: 24px 40px; border-top: 1px solid var(--border); }
    .gateway-logs h4 { font-size: 12px; text-transform: uppercase; color: var(--text-dim); letter-spacing: 0.05em; margin-bottom: 12px; font-family: var(--font-mono); }
    .gateway-logs pre { background: var(--bg); padding: 16px; border-radius: 8px; border: 1px solid var(--border-strong); font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }

    /* Toasts */
    #toast-container { position: fixed; bottom: 32px; right: 32px; z-index: 9999; display: flex; flex-direction: column; gap: 12px; }
    .toast { padding: 16px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 12px; color: var(--text); box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: slideIn 0.3s ease forwards; min-width: 300px; font-family: var(--font-body); background: var(--surface); }
    .toast.success { border: 1px solid rgba(34,211,238,0.3); border-left: 4px solid var(--accent-green); }
    .toast.error { border: 1px solid rgba(248,113,113,0.3); border-left: 4px solid var(--accent-red); }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    @media print {
        aside, header, .top-actions, .theme-toggle, .gateway-logs { display: none !important; }
        body, main { background: white !important; color: black !important; }
        .invoice-card { box-shadow: none !important; border: none !important; }
        .invoice-meta { background: #f8fafc !important; }
        .total-section { background: #f1f5f9 !important; }
        * { color: black !important; }
        .badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
    }
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
    <a href="panels.php" class="nav-item"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item active"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
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
    
    <div class="top-actions">
        <a href="invoices.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Ledger</a>
        
        <div style="display: flex; gap: 16px; align-items: center;">
            <button class="btn-outline" onclick="window.print()" style="background: transparent; border: 1px solid var(--border-strong); color: var(--text); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-family: var(--font-body);"><i class="fa-solid fa-print"></i> Print PDF</button>
            
            <form method="POST" style="display: flex; gap: 8px; margin: 0;">
                <select name="status" class="status-select">
                    <option value="Paid" <?= $invoice['status'] == 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Unpaid" <?= $invoice['status'] == 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="Cancelled" <?= $invoice['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="Refunded" <?= $invoice['status'] == 'Refunded' ? 'selected' : '' ?>>Refunded</option>
                </select>
                <button type="submit" name="update_invoice" class="btn-update">Update Status</button>
            </form>
        </div>
    </div>

    <div class="invoice-card">
        <div class="invoice-header">
            <div class="invoice-header-left">
                <h2><?= htmlspecialchars($invoice['invoice_number']) ?></h2>
                <p>Issued: <?= date('F j, Y', strtotime($invoice['created_at'])) ?></p>
            </div>
            <div>
                <span class="badge badge-<?= $invoice['status'] ?>">
                    <?php if($invoice['status'] == 'Paid') echo '<i class="fa-solid fa-check"></i>';
                          elseif($invoice['status'] == 'Unpaid') echo '<i class="fa-solid fa-clock"></i>';
                          else echo '<i class="fa-solid fa-xmark"></i>';
                    ?>
                    <?= htmlspecialchars($invoice['status']) ?>
                </span>
            </div>
        </div>

        <div class="invoice-meta">
            <div class="meta-box">
                <h4>Billed To</h4>
                <p>
                    <strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong><br>
                    <a href="edit-user.php?id=<?= $invoice['client_id'] ?>"><?= htmlspecialchars($invoice['email']) ?></a><br>
                    Client ID: #<?= $invoice['client_id'] ?>
                </p>
            </div>
            <div class="meta-box">
                <h4>Invoice Details</h4>
                <p style="display: grid; grid-template-columns: 100px 1fr; gap: 8px;">
                    <span style="color: var(--text-muted);">Due Date:</span> 
                    <strong><?= date('M j, Y', strtotime($invoice['due_date'])) ?></strong>
                    
                    <?php if($invoice['type'] !== 'topup'): ?>
                    <span style="color: var(--text-muted);">Cycle:</span> 
                    <span style="text-transform: capitalize;"><?= $invoice['billing_cycle'] ? str_replace('_', '-', $invoice['billing_cycle']) : 'N/A' ?></span>
                    
                    <span style="color: var(--text-muted);">Period:</span> 
                    <span>
                        <?php 
                            if ($invoice['period_start'] && $invoice['period_end']) {
                                echo date('M j, Y', strtotime($invoice['period_start'])) . ' - ' . date('M j, Y', strtotime($invoice['period_end']));
                            } else {
                                echo "N/A";
                            }
                        ?>
                    </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="70%">Description</th>
                    <th width="30%" style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php if($invoice['type'] === 'topup' || strpos($invoice['invoice_number'], 'WAL-') === 0): ?>
                            <div style="font-weight: 600; margin-bottom: 4px; font-size: 16px;">Wallet Top-Up</div>
                            <div style="color: var(--text-muted); font-size: 14px;">Funds credited to client's account balance.</div>
                        <?php else: ?>
                            <div style="font-weight: 600; margin-bottom: 4px; font-size: 16px;">Vormox Infrastructure Panel</div>
                            <?php if($invoice['domain']): ?>
                                <div style="color: var(--text-muted); font-size: 14px;">
                                    Target Domain: <a href="manage_panel.php?id=<?= $invoice['panel_id'] ?>" style="color: var(--accent2); text-decoration: none; font-weight: 500;"><?= htmlspecialchars($invoice['domain']) ?></a><br>
                                    Allocation: <?= $invoice['nodes_count'] ?> Compute Node(s)
                                </div>
                            <?php else: ?>
                                <div style="color: var(--text-muted); font-size: 14px;">Custom Service / Allocation</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;">
                        $<?= number_format($invoice['amount'], 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="total-section">
            <span class="total-label">Total Due</span>
            <span class="total-amount">$<?= number_format($invoice['amount'], 2) ?> <span style="font-size: 16px; color: var(--text-muted);">USD</span></span>
        </div>

        <?php if(!empty($invoice['gateway_logs'])): ?>
        <div class="gateway-logs">
            <h4>Payment Gateway Transaction Logs</h4>
            <pre><?= htmlspecialchars($invoice['gateway_logs']) ?></pre>
        </div>
        <?php endif; ?>

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
});
</script>
</body>
</html>
