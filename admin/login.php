<?php
session_start();
require_once '../config.php';

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

if (isset($_SESSION['admin_id']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                $updateStmt = $pdo->prepare("UPDATE admins SET last_login = NOW(), last_ip = :ip WHERE id = :id");
                $updateStmt->execute(['ip' => $user_ip, 'id' => $admin['id']]);

                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_logged_in'] = true;

                header("Location: index.php");
                exit;
            } else {
                $error = "Invalid admin credentials.";
            }
        } catch (PDOException $e) {
            error_log("Admin Login error: " . $e->getMessage());
            $error = "A system error occurred during sign in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Portal — Vormox Automation Cloud</title>
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
      --accent-red: #f87171;
      --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68;
      --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace;
      --font-body: 'Instrument Sans', sans-serif;
    }

    [data-theme="light"] {
      --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0;
      --border: #e2e8f0; --border-strong: #cbd5e1;
      --accent: #7c3aed; --accent2: #6d28d9; --accent-glow: rgba(124,58,237,0.15);
      --accent-red: #dc2626;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
    }

    body { 
      background: var(--bg); color: var(--text); font-family: var(--font-body); 
      min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden;
      transition: background 0.3s, color 0.3s;
    }
    
    .grid-bg {
      position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background-image: linear-gradient(var(--border) 1px,transparent 1px), linear-gradient(90deg,var(--border) 1px,transparent 1px);
      background-size: 60px 60px;
    }
    .hero-glow {
      position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
      width: 800px; height: 800px; pointer-events: none; z-index: 0;
      background: radial-gradient(circle at center, var(--accent-glow) 0%, transparent 60%);
    }

    nav { position: relative; z-index: 10; padding: 24px; display: flex; justify-content: center; }
    .logo { display: flex; align-items: center; gap: 10px; font-family: var(--font-head); font-size: 24px; font-weight: 800; color: var(--text); }
    .logo-icon { width: 36px; height: 36px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    .logo span { color: var(--accent2); }

    main { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; padding: 40px 24px; }
    .auth-card { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 48px 40px; width: 100%; max-width: 440px; box-shadow: 0 24px 64px rgba(0,0,0,0.4); transition: background 0.3s, border-color 0.3s, box-shadow 0.3s; }
    [data-theme="light"] .auth-card { box-shadow: 0 24px 64px rgba(0,0,0,0.05); }
    
    .auth-header { text-align: center; margin-bottom: 32px; }
    .auth-title { font-family: var(--font-head); font-size: 28px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
    .auth-sub { font-size: 15px; color: var(--text-muted); font-family: var(--font-mono); text-transform: uppercase; letter-spacing: 0.1em; }

    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 8px; }
    
    input { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 15px; outline: none; transition: background 0.3s, border-color 0.3s, color 0.3s; }
    input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

    .btn-submit { width: 100%; padding: 14px; margin-top: 8px; background: var(--accent); color: #fff; font-family: var(--font-body); font-size: 15px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 20px var(--accent-glow); }
    .btn-submit:hover { background: var(--accent2); transform: translateY(-1px); box-shadow: 0 4px 28px var(--accent-glow); }

    .error-box { background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--accent-red); padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
  </style>
</head>
<body>

<div class="grid-bg"></div>
<div class="hero-glow"></div>

<nav>
  <div class="logo">
    <div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
    Vormox <span>Admin</span>
  </div>
</nav>

<main>
  <div class="auth-card">
    <div class="auth-header">
      <h1 class="auth-title">System Access</h1>
      <p class="auth-sub">Restricted Area</p>
    </div>

    <?php if ($error): ?>
      <div class="error-box">
        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <div class="form-group">
        <label for="email">Admin Email</label>
        <input type="email" id="email" name="email" placeholder="admin@vormox.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••••••" required>
      </div>
      
      <button type="submit" class="btn-submit">Authenticate <i class="fa-solid fa-lock" style="margin-left: 6px; font-size: 12px;"></i></button>
    </form>

  </div>
</main>

</body>
</html>