<?php
session_start();
require_once '../config.php'; 
require_once 'auth_guard.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['update_company'])) {
        $app_name = trim(filter_input(INPUT_POST, 'app_name', FILTER_SANITIZE_SPECIAL_CHARS));
        $support_email = trim(filter_input(INPUT_POST, 'support_email', FILTER_SANITIZE_EMAIL));
        $company_name = trim(filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS));
        $company_address = trim(filter_input(INPUT_POST, 'company_address', FILTER_SANITIZE_SPECIAL_CHARS));
        $maintenance = isset($_POST['maintenance_mode']) ? 1 : 0;
        
        if ($app_name && $support_email) {
            try {
                $stmt = $pdo->prepare("UPDATE system_settings SET app_name = :app, support_email = :email, company_name = :cname, company_address = :caddr, maintenance_mode = :maint WHERE id = 1");
                $stmt->execute(['app' => $app_name, 'email' => $support_email, 'cname' => $company_name, 'caddr' => $company_address, 'maint' => $maintenance]);
                $success = "Company settings updated successfully.";
            } catch (PDOException $e) {
                $error = "Failed to update company settings.";
            }
        } else {
            $error = "App Name and Support Email cannot be empty.";
        }
    }
    
    if (isset($_POST['update_password'])) {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';
        
        if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
            $error = "All password fields are required.";
        } elseif ($new_pwd !== $confirm_pwd) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_pwd) < 8) {
            $error = "New password must be at least 8 characters long.";
        } else {
            try {
                $adminStmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id LIMIT 1");
                $adminStmt->execute(['id' => $_SESSION['admin_id']]);
                $admin_data = $adminStmt->fetch();
                
                if (password_verify($current_pwd, $admin_data['password_hash'])) {
                    $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
                    $updStmt = $pdo->prepare("UPDATE admins SET password_hash = :hash WHERE id = :id");
                    $updStmt->execute(['hash' => $new_hash, 'id' => $_SESSION['admin_id']]);
                    $success = "Admin password has been changed successfully.";
                } else {
                    $error = "Current password is incorrect.";
                }
            } catch (PDOException $e) {
                $error = "Failed to update password.";
            }
        }
    }
}

try {
    $setStmt = $pdo->query("SELECT * FROM system_settings WHERE id = 1 LIMIT 1");
    $settings = $setStmt->fetch() ?: ['app_name' => 'Vormox Automation Cloud', 'support_email' => 'support@vormox.com', 'company_name' => '', 'company_address' => '', 'maintenance_mode' => 0];
} catch (Exception $e) {
    $settings = ['app_name' => 'Vormox Automation Cloud', 'support_email' => 'support@vormox.com', 'company_name' => '', 'company_address' => '', 'maintenance_mode' => 0];
}

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'System Settings';
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
    const initialTheme = savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', initialTheme);
  </script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root, [data-theme="dark"] { --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35; --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3); --accent: #a78bfa; --accent2: #8b5cf6; --accent-glow: rgba(139,92,246,0.35); --accent-red: #f87171; --accent-green: #22d3ee; --accent-orange: #fb923c; --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68; --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace; --font-body: 'Instrument Sans', sans-serif; }
    [data-theme="light"] { --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0; --border: #e2e8f0; --border-strong: #cbd5e1; --accent: #7c3aed; --accent2: #6d28d9; --accent-glow: rgba(124,58,237,0.15); --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626; --text: #0f172a; --text-muted: #475569; --text-dim: #64748b; }
    
    body { background: var(--bg); color: var(--text); font-family: var(--font-body); display: flex; min-height: 100vh; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    aside { width: 260px; background: rgba(5,8,16,.95); border-right: 1px solid var(--border); padding: 24px; display: flex; flex-direction: column; z-index: 10; flex-shrink: 0; transition: background 0.3s; }
    [data-theme="light"] aside { background: var(--bg); }
    
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 48px; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    
    .nav-label { margin: 24px 0 8px 16px; font-size: 11px; font-family: var(--font-mono); color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.2s; margin-bottom: 4px; }
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
    [data-theme="dark"] .fa-moon { display: none; }
    [data-theme="light"] .fa-sun { display: none; }

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; }
    
    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start; }
    @media (max-width: 1100px) { .settings-grid { grid-template-columns: 1fr; } }
    
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 40px; transition: background 0.3s, border-color 0.3s; }
    .card-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
    .card-title i { color: var(--accent2); }

    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 8px; }
    input[type="text"], input[type="email"], input[type="password"], textarea { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 15px; outline: none; transition: background 0.3s, border-color 0.3s, color 0.3s; }
    textarea { resize: vertical; min-height: 100px; }
    input:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    
    .btn-primary { padding: 14px 24px; background: var(--accent2); color: #fff; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: 0.2s; width: 100%; margin-top: 12px; }
    .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }

    .checkbox-wrapper { display: flex; align-items: center; gap: 12px; margin-top: 24px; padding: 16px; border: 1px dashed rgba(248,113,113,0.3); border-radius: 8px; background: rgba(248,113,113,0.05); }
    .checkbox-wrapper label { margin: 0; font-family: var(--font-body); text-transform: none; font-size: 15px; color: var(--text); letter-spacing: normal; cursor: pointer; }
    .checkbox-wrapper input[type="checkbox"] { width: 20px; height: 20px; accent-color: var(--accent-red); cursor: pointer; }
  </style>
</head>
<body>

<aside>
  <a href="index.php" class="logo"><div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>Vormox <span>Admin</span></a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item active"><i class="fa-solid fa-gear"></i> Global Settings</a>
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
    <div class="header-title">System Settings</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="settings-grid">
        
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-building"></i> Company Details</div>
            <form method="POST">
                <div class="form-group">
                    <label>Application Name</label>
                    <input type="text" name="app_name" value="<?= htmlspecialchars($settings['app_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Global Support Email</label>
                    <input type="email" name="support_email" value="<?= htmlspecialchars($settings['support_email']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Company Legal Name</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" placeholder="e.g. Vormox Inc.">
                </div>

                <div class="form-group">
                    <label>Company Address (For Invoices)</label>
                    <textarea name="company_address" placeholder="123 Cloud Ave, Suite 400&#10;San Francisco, CA 94105"><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
                </div>

                <div class="checkbox-wrapper">
                    <input type="checkbox" id="maintenance" name="maintenance_mode" <?= $settings['maintenance_mode'] ? 'checked' : '' ?>>
                    <label for="maintenance"><strong>Enable Maintenance Mode</strong><br><span style="font-size: 13px; color: var(--text-muted);">This will lock standard users out of the client dashboard until disabled.</span></label>
                </div>

                <button type="submit" name="update_company" class="btn-primary">Save Company Settings</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title"><i class="fa-solid fa-user-shield"></i> Security Profile</div>
            <form method="POST">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required placeholder="••••••••••••">
                </div>
                
                <div style="border-top: 1px solid var(--border); margin: 24px 0;"></div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required placeholder="••••••••••••">
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required placeholder="••••••••••••">
                </div>

                <button type="submit" name="update_password" class="btn-primary" style="background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2);">Change Admin Password</button>
            </form>
            
            <div style="margin-top: 24px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                <strong style="color: var(--text);">Security Notice:</strong> Changing your password will not end your current session. Please ensure your new password is at least 8 characters long and contains a mix of letters, numbers, and symbols.
            </div>
        </div>

    </div>
  </div>
</main>

<script>
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
