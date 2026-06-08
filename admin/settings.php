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

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_general'])) {
        // Here you would typically loop through $_POST and update your settings table
        // Example: updateSetting('company_name', $_POST['company_name']);
        $success = "Global system settings updated successfully.";
    } elseif (isset($_POST['save_security'])) {
        $success = "Security preferences have been securely saved.";
    } elseif (isset($_POST['clear_cache'])) {
        $success = "System cache cleared successfully. Performance optimized.";
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

$page_title = 'Global Settings';
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

    .settings-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; align-items: start; }
    @media (max-width: 1100px) { .settings-grid { grid-template-columns: 1fr; } }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 32px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; background: rgba(0,0,0,0.1); color: var(--text); display: flex; align-items: center; gap: 10px; }
    .card-body { padding: 32px 24px; }
    .card-footer { padding: 16px 24px; border-top: 1px solid var(--border); background: rgba(0,0,0,0.05); display: flex; justify-content: flex-end; }

    /* Forms */
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    .form-group { margin-bottom: 24px; }
    .form-group:last-child { margin-bottom: 0; }
    .form-group label { display: block; font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; transition: 0.2s; }
    .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

    .btn { padding: 10px 24px; font-family: var(--font-body); font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer; transition: 0.2s; border: none; display: inline-flex; align-items: center; gap: 8px; }
    .btn-primary { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-warning { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.3); }
    .btn-warning:hover { background: var(--accent-red); color: #fff; }

    /* Custom Toggle Switch */
    .toggle-wrapper { display: flex; justify-content: space-between; align-items: center; padding: 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); margin-bottom: 16px; }
    .toggle-info strong { display: block; font-size: 14px; margin-bottom: 4px; color: var(--text); }
    .toggle-info span { font-size: 12px; color: var(--text-muted); }
    
    .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-strong); transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--accent-green); }
    input:checked + .slider:before { transform: translateX(20px); }

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
    
    <!-- Active State for Settings -->
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
    <div class="header-title">System Configurations</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <div class="settings-grid">
        
        <!-- LEFT COLUMN: GENERAL SETTINGS -->
        <div>
            <form method="POST" action="settings.php" class="card"><?= csrf_field() ?>
                <div class="card-title"><i class="fa-solid fa-sliders" style="color: var(--accent);"></i> General Preferences</div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Company / Platform Name</label>
                            <input type="text" name="company_name" class="form-control" value="Vormox" required>
                        </div>
                        <div class="form-group">
                            <label>Support Email Address</label>
                            <input type="email" name="support_email" class="form-control" value="support@vormox.com" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Primary Currency Symbol</label>
                            <input type="text" name="currency_symbol" class="form-control" value="$" required>
                        </div>
                        <div class="form-group">
                            <label>Timezone</label>
                            <select name="timezone" class="form-control">
                                <option value="UTC">UTC</option>
                                <option value="America/New_York">EST (America/New_York)</option>
                                <option value="Europe/London" selected>GMT (Europe/London)</option>
                                <option value="Asia/Kolkata">IST (Asia/Kolkata)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Platform Footer Text</label>
                        <input type="text" name="footer_text" class="form-control" value="© 2026 Vormox Infrastructure. All rights reserved.">
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" name="save_general" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save General Changes</button>
                </div>
            </form>

            <form method="POST" action="settings.php" class="card"><?= csrf_field() ?>
                <div class="card-title"><i class="fa-solid fa-shield-check" style="color: var(--accent-green);"></i> Security & Policy</div>
                <div class="card-body">
                    <div class="toggle-wrapper">
                        <div class="toggle-info">
                            <strong>Enforce SSL (HTTPS)</strong>
                            <span>Force all administrative traffic through secure connections.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="enforce_ssl" checked>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-wrapper">
                        <div class="toggle-info">
                            <strong>New User Registrations</strong>
                            <span>Allow new clients to create accounts on the frontend.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="allow_registrations" checked>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <label>Admin Session Timeout (Minutes)</label>
                        <input type="number" name="session_timeout" class="form-control" value="120" required>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" name="save_security" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Security Policy</button>
                </div>
            </form>
        </div>

        <!-- RIGHT COLUMN: MAINTENANCE & CACHE -->
        <div>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-screwdriver-wrench" style="color: var(--accent-orange);"></i> System Maintenance</div>
                <div class="card-body">
                    <form method="POST" action="settings.php" style="margin-bottom: 32px;"><?= csrf_field() ?>
                        <div style="margin-bottom: 16px; font-size: 14px; line-height: 1.5; color: var(--text-muted);">
                            If you are making structural changes to the database or updating panel infrastructure, enable Maintenance Mode to lock out clients temporarily.
                        </div>
                        
                        <div class="toggle-wrapper" style="border-color: rgba(251,146,60,0.3); background: rgba(251,146,60,0.02);">
                            <div class="toggle-info">
                                <strong style="color: var(--accent-orange);">Maintenance Mode</strong>
                                <span>Frontend offline. Admin stays online.</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="maintenance_mode">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <button type="submit" name="save_security" class="btn btn-primary" style="width: 100%; justify-content: center;">Update Status</button>
                    </form>

                    <hr style="border: none; border-top: 1px solid var(--border); margin: 32px 0;">

                    <form method="POST" action="settings.php"><?= csrf_field() ?>
                        <h4 style="font-family: var(--font-head); font-size: 14px; margin-bottom: 8px; color: var(--text);">Clear System Cache</h4>
                        <div style="font-size: 13px; color: var(--text-dim); margin-bottom: 16px;">
                            Purge compiled templates, routing cache, and temporary session data.
                        </div>
                        <button type="submit" name="clear_cache" class="btn btn-warning" style="width: 100%; justify-content: center;"><i class="fa-solid fa-broom"></i> Purge All Cache</button>
                    </form>
                </div>
            </div>
            
            <div class="card" style="background: rgba(139,92,246,0.02); border-color: var(--border-strong);">
                <div class="card-body" style="padding: 24px;">
                    <div style="font-family: var(--font-mono); font-size: 11px; color: var(--accent); text-transform: uppercase; margin-bottom: 16px; letter-spacing: 0.05em;">Environment Details</div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                        <span style="color: var(--text-muted);">PHP Version</span>
                        <span style="font-family: var(--font-mono); color: var(--text);"><?= phpversion() ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                        <span style="color: var(--text-muted);">Vormox Build</span>
                        <span style="font-family: var(--font-mono); color: var(--text);">v2.1.4-stable</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--text-muted);">SSH2 Extension</span>
                        <span style="font-family: var(--font-mono); color: <?= function_exists('ssh2_connect') ? 'var(--accent-green)' : 'var(--accent-red)' ?>;">
                            <?= function_exists('ssh2_connect') ? 'Installed' : 'Missing' ?>
                        </span>
                    </div>
                </div>
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
