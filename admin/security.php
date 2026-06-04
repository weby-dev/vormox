<?php
session_start();
require_once '../config.php'; 

// --- SECURITY BOILERPLATE ---
$user_ip = $_SERVER['REMOTE_ADDR'];
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    $whitelist_count = $countStmt->fetchColumn();
    
    if ($whitelist_count > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) { header("Location: ../dashboard.php"); exit; }
    }
} catch (PDOException $e) { die("Security verification failed."); }

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) { header("Location: login.php"); exit; }

$success = ''; $error = '';

// --- HANDLE POST ACTIONS (ADD / REMOVE IP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Action 1: Add IP to Whitelist
    if (isset($_POST['add_ip'])) {
        $ip_address = filter_input(INPUT_POST, 'ip_address', FILTER_VALIDATE_IP);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'No description provided';
        
        if ($ip_address) {
            try {
                // Check if already exists
                $check = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip");
                $check->execute(['ip' => $ip_address]);
                
                if ($check->fetch()) {
                    $error = "This IP Address is already on the whitelist.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO admin_ip_whitelist (ip_address, description) VALUES (:ip, :desc)");
                    $stmt->execute(['ip' => $ip_address, 'desc' => $description]);
                    $success = "IP Address $ip_address has been securely whitelisted.";
                    $whitelist_count++; // Update count
                }
            } catch (PDOException $e) {
                $error = "Database error while adding IP.";
            }
        } else {
            $error = "Invalid IP Address format.";
        }
    }
    
    // Action 2: Remove IP from Whitelist
    if (isset($_POST['remove_ip'])) {
        $remove_id = filter_input(INPUT_POST, 'whitelist_id', FILTER_VALIDATE_INT);
        $target_ip = filter_input(INPUT_POST, 'target_ip', FILTER_SANITIZE_SPECIAL_CHARS);
        
        if ($remove_id) {
            if ($target_ip === $user_ip) {
                $error = "Security Block: You cannot remove your current active IP address. Doing so would lock you out.";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM admin_ip_whitelist WHERE id = :id");
                    $stmt->execute(['id' => $remove_id]);
                    $success = "IP Address removed from the whitelist.";
                    $whitelist_count--; // Update count
                } catch (PDOException $e) {
                    $error = "Database error while removing IP.";
                }
            }
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

// --- FETCH WHITELIST DATA ---
try {
    $whitelistStmt = $pdo->query("SELECT * FROM admin_ip_whitelist ORDER BY created_at DESC");
    $whitelisted_ips = $whitelistStmt->fetchAll();
} catch (PDOException $e) {
    $whitelisted_ips = [];
}

$page_title = 'Access Security';
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; }

    /* Layout specific */
    .security-grid { display: grid; grid-template-columns: 350px 1fr; gap: 32px; align-items: start; }
    @media (max-width: 1100px) { .security-grid { grid-template-columns: 1fr; } }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 24px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; background: rgba(0,0,0,0.1); color: var(--text); display: flex; justify-content: space-between; align-items: center; }
    
    .alert-box { padding: 16px 20px; background: rgba(139,92,246,0.05); border: 1px solid var(--border-strong); border-radius: 8px; margin: 24px; font-size: 14px; line-height: 1.6; color: var(--text-muted); }
    .alert-box i { color: var(--accent); margin-right: 8px; }

    /* Form Styles */
    .form-group { margin-bottom: 20px; padding: 0 24px; }
    .form-group label { display: block; font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; transition: 0.2s; }
    .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    
    .btn-submit { display: block; width: calc(100% - 48px); margin: 0 24px 24px; padding: 12px; background: var(--accent2); color: #fff; border: none; border-radius: 8px; font-family: var(--font-body); font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 15px var(--accent-glow); text-align: center; }
    .btn-submit:hover { transform: translateY(-1px); filter: brightness(1.1); }

    /* Table Styles */
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    .btn-danger { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.3); padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .btn-danger:hover { background: var(--accent-red); color: #fff; }
    .btn-disabled { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: not-allowed; opacity: 0.6; }

    .badge-current { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); padding: 4px 8px; border-radius: 100px; font-size: 10px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); margin-left: 8px; }

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
    <a href="tickets.php" class="nav-item <?= in_array($current_page, ['tickets.php', 'view-ticket.php']) ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    
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
    <div class="header-title">Access Security</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    
    <div class="security-grid">
        <div>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-shield-plus" style="color: var(--accent);"></i> Add Whitelist Entry</div>
                
                <div class="alert-box">
                    <i class="fa-solid fa-circle-info"></i> If the whitelist is entirely empty, the system allows access from anywhere. The moment one IP is added, <strong>strict IP locking</strong> is enabled for all admin panels.
                </div>

                <form method="POST" action="security.php">
                    <div class="form-group">
                        <label>IP Address (IPv4 / IPv6)</label>
                        <input type="text" name="ip_address" class="form-control" value="<?= htmlspecialchars($user_ip) ?>" required placeholder="e.g. 192.168.1.1">
                    </div>
                    <div class="form-group">
                        <label>Reference / Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Office Network, Home VPN">
                    </div>
                    <button type="submit" name="add_ip" class="btn-submit"><i class="fa-solid fa-lock"></i> Authorize IP</button>
                </form>
            </div>

            <div class="card" style="background: rgba(34,211,238,0.02); border-color: rgba(34,211,238,0.2);">
                <div style="padding: 24px;">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--accent-green); text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;">Your Current Connection</div>
                    <div style="font-size: 20px; font-weight: 700; font-family: var(--font-mono); color: var(--text);"><?= htmlspecialchars($user_ip) ?></div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title">
                    <div><i class="fa-solid fa-list-check" style="color: var(--accent-green);"></i> Active Whitelist</div>
                    <span style="font-size: 13px; font-family: var(--font-body); color: var(--text-muted); font-weight: 500;"><?= $whitelist_count ?> Authorized IPs</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th width="35%">IP Address</th>
                            <th width="30%">Description</th>
                            <th width="20%">Added On</th>
                            <th width="15%" style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($whitelisted_ips)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 48px; color: var(--text-dim);">Whitelist is currently empty. System is globally accessible.</td></tr>
                        <?php else: foreach($whitelisted_ips as $wl): 
                            $is_current = ($wl['ip_address'] === $user_ip);
                        ?>
                            <tr>
                                <td style="font-family: var(--font-mono); font-weight: 600; font-size: 15px; color: var(--text);">
                                    <i class="fa-solid fa-network-wired" style="font-size: 12px; color: var(--text-dim); margin-right: 6px;"></i>
                                    <?= htmlspecialchars($wl['ip_address']) ?>
                                    <?php if($is_current): ?><span class="badge-current">You</span><?php endif; ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 13px;">
                                    <?= htmlspecialchars($wl['description']) ?>
                                </td>
                                <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);">
                                    <?= date('M j, Y', strtotime($wl['created_at'])) ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if($is_current): ?>
                                        <button class="btn-disabled" title="Cannot remove active session IP"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <form method="POST" action="security.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to revoke access for this IP?');">
                                            <input type="hidden" name="whitelist_id" value="<?= htmlspecialchars($wl['id']) ?>">
                                            <input type="hidden" name="target_ip" value="<?= htmlspecialchars($wl['ip_address']) ?>">
                                            <button type="submit" name="remove_ip" class="btn-danger"><i class="fa-solid fa-trash-can"></i> Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
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
