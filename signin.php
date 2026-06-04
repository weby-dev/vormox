<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $ip_address = $_SERVER['REMOTE_ADDR'];
                
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_ip = :ip WHERE id = :id");
                $updateStmt->execute(['ip' => $ip_address, 'id' => $user['id']]);

                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['logged_in'] = true;

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "An error occurred during sign in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In — Vormox Automation Cloud</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    const initialTheme = savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', initialTheme);
    localStorage.setItem('theme', initialTheme);
  </script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    :root, [data-theme="dark"] {
      --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35;
      --border: rgba(99,179,237,0.12); --border-strong: rgba(99,179,237,0.25);
      --accent: #3b82f6; --accent2: #60a5fa; --accent-glow: rgba(59,130,246,0.35);
      --accent-green: #22d3ee; --accent-red: #f87171;
      --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68;
      --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace;
      --font-body: 'Instrument Sans', sans-serif; --radius: 16px;
    }

    [data-theme="light"] {
      --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0;
      --border: #e2e8f0; --border-strong: #cbd5e1;
      --accent: #2563eb; --accent2: #3b82f6; --accent-glow: rgba(37,99,235,0.15);
      --accent-green: #0891b2; --accent-red: #dc2626;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
    }

    body { 
      background: var(--bg); color: var(--text); font-family: var(--font-body); 
      min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden;
      transition: background 0.3s, color 0.3s;
    }
    
    body::before {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0; opacity: .55;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    }
    .grid-bg {
      position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background-image: linear-gradient(rgba(59,130,246,.04) 1px,transparent 1px), linear-gradient(90deg,rgba(59,130,246,.04) 1px,transparent 1px);
      background-size: 60px 60px;
    }
    .hero-glow {
      position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
      width: 800px; height: 800px; pointer-events: none; z-index: 0;
      background: radial-gradient(circle at center, rgba(59,130,246,0.1) 0%, transparent 60%);
    }

    nav {
      position: relative; z-index: 10; padding: 24px clamp(24px,5vw,80px);
      display: flex; align-items: center; justify-content: space-between;
    }
    .logo {
      display: flex; align-items: center; gap: 10px; text-decoration: none;
      font-family: var(--font-head); font-size: 20px; font-weight: 800;
      color: var(--text); letter-spacing: -.5px;
    }
    .logo-icon {
      width: 32px; height: 32px;
      background: linear-gradient(135deg,var(--accent),var(--accent-green));
      border-radius: 8px; display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow);
    }
    .logo span { color: var(--accent2); }
    .back-link {
      font-size: 14px; color: var(--text-muted); text-decoration: none;
      font-weight: 500; transition: color 0.2s; display: flex; align-items: center; gap: 6px;
    }
    .back-link:hover { color: var(--text); }

    main {
      flex: 1; display: flex; align-items: center; justify-content: center;
      position: relative; z-index: 1; padding: 40px 24px;
    }
    .auth-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 18px; padding: 48px 40px; width: 100%; max-width: 440px;
      position: relative; box-shadow: 0 24px 64px rgba(0,0,0,0.4);
    }
    [data-theme="light"] .auth-card { box-shadow: 0 24px 64px rgba(0,0,0,0.05); }

    .auth-header { text-align: center; margin-bottom: 32px; }
    .auth-title {
      font-family: var(--font-head); font-size: 28px; font-weight: 700;
      color: var(--text); letter-spacing: -.02em; margin-bottom: 8px;
    }
    .auth-sub { font-size: 15px; color: var(--text-muted); }

    .form-group { margin-bottom: 20px; text-align: left; }
    .label-wrapper { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
    label { font-size: 13px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; }
    .forgot-link { font-size: 13px; color: var(--accent2); text-decoration: none; font-weight: 500; transition: color 0.2s; }
    .forgot-link:hover { color: var(--accent-green); }
    
    input {
      width: 100%; padding: 14px 16px; background: var(--bg2);
      border: 1px solid var(--border-strong); border-radius: 8px;
      color: var(--text); font-family: var(--font-body); font-size: 15px;
      transition: all 0.2s; outline: none;
    }
    input:focus {
      border-color: var(--accent); background: var(--bg);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    }
    input::placeholder { color: var(--text-dim); }

    .btn-submit {
      width: 100%; padding: 14px; margin-top: 8px;
      background: var(--accent); color: #fff; font-family: var(--font-body);
      font-size: 15px; font-weight: 600; border: none; border-radius: 8px;
      cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 20px rgba(59,130,246,.3);
    }
    .btn-submit:hover {
      background: #2563eb; transform: translateY(-1px);
      box-shadow: 0 4px 28px rgba(59,130,246,.5);
    }

    .error-box {
      background: rgba(220, 38, 38, 0.1); border: 1px solid rgba(220, 38, 38, 0.3);
      color: #fca5a5; padding: 12px 16px; border-radius: 8px; font-size: 14px;
      margin-bottom: 24px; display: flex; align-items: center; gap: 8px;
    }
    [data-theme="light"] .error-box { color: var(--accent-red); }

    .auth-footer {
      margin-top: 32px; text-align: center; font-size: 14px; color: var(--text-muted);
      padding-top: 24px; border-top: 1px solid var(--border);
    }
    .auth-footer a { color: var(--text); text-decoration: none; font-weight: 600; transition: color 0.2s; }
    .auth-footer a:hover { color: var(--accent2); }

  </style>
</head>
<body>

<div class="grid-bg"></div>
<div class="hero-glow"></div>

<nav>
  <a href="index.php" class="logo" aria-label="Vormox Home">
    <div class="logo-icon" aria-hidden="true"><i class="fa-solid fa-bolt"></i></div>
    Vorm<span>ox</span>
  </a>
  <a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>
</nav>

<main>
  <div class="auth-card">
    <div class="auth-header">
      <h1 class="auth-title">Welcome back</h1>
      <p class="auth-sub">Sign in to manage your Proxmox clusters</p>
    </div>

    <?php if ($error): ?>
      <div class="error-box">
        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="signin.php">
      <div class="form-group">
        <div class="label-wrapper">
          <label for="email">Email Address</label>
        </div>
        <input type="email" id="email" name="email" placeholder="admin@yourcompany.com" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      
      <div class="form-group">
        <div class="label-wrapper">
          <label for="password">Password</label>
          <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
        </div>
        <input type="password" id="password" name="password" placeholder="••••••••••••" required autocomplete="current-password">
      </div>
      
      <button type="submit" class="btn-submit">Sign In to Console <i class="fa-solid fa-arrow-right" style="margin-left: 6px; font-size: 12px;"></i></button>
    </form>

    <div class="auth-footer">
      Don't have an account? <a href="signup.php">Create a new account</a>
    </div>
  </div>
</main>

</body>
</html>