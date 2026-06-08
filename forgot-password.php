<?php
session_start();
require_once 'config.php';
require_once 'includes/notifications.php';

// Already logged in? Bounce to dashboard.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
    header("Location: dashboard.php"); exit;
}

$error = '';
$notice = '';

// Stages: 'request' → 'verify' (OTP only) → 'reset' (new password) → 'done'
$stage = $_SESSION['fp_stage'] ?? 'request';

function fp_reset_state() {
    unset($_SESSION['fp_stage'], $_SESSION['fp_user_id'], $_SESSION['fp_email'],
          $_SESSION['fp_otp'], $_SESSION['fp_otp_expires_at'], $_SESSION['fp_attempts'],
          $_SESSION['fp_last_sent_at'], $_SESSION['fp_verified'], $_SESSION['fp_reset_expires_at']);
}

if (isset($_GET['restart'])) { fp_reset_state(); header("Location: forgot-password.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // ---------- STAGE 1: request reset for an email ----------
    if (isset($_POST['request_reset'])) {
        $email = trim((string) filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!empty($_SESSION['fp_last_sent_at']) && (time() - $_SESSION['fp_last_sent_at']) < 60) {
            $error = "Please wait a moment before requesting another code.";
        } else {
            try {
                $st = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ? LIMIT 1");
                $st->execute([$email]);
                $u = $st->fetch();

                // Always advance to the verify stage so the response shape can't
                // be used to enumerate which addresses have accounts.
                $otp = random_int(100000, 999999);
                $_SESSION['fp_stage']           = 'verify';
                $_SESSION['fp_email']           = $email;
                $_SESSION['fp_user_id']         = $u['id'] ?? null;
                $_SESSION['fp_otp']             = (string) $otp;
                $_SESSION['fp_otp_expires_at']  = time() + 900; // 15 min
                $_SESSION['fp_attempts']        = 0;
                $_SESSION['fp_last_sent_at']    = time();
                $_SESSION['fp_verified']        = false;

                if ($u) {
                    notify_password_reset_otp($email, $u['first_name'], $otp, 15);
                }

                $stage  = 'verify';
                $notice = "If an account exists for {$email}, we've sent a 6-digit code. Check your inbox (and spam folder).";
            } catch (PDOException $e) {
                error_log("forgot-password lookup failed: " . $e->getMessage());
                $error = "Something went wrong. Please try again.";
            }
        }
    }

    // ---------- Resend code (verify stage only) ----------
    if (isset($_POST['resend_code']) && ($_SESSION['fp_stage'] ?? '') === 'verify') {
        if (!empty($_SESSION['fp_last_sent_at']) && (time() - $_SESSION['fp_last_sent_at']) < 60) {
            $error = "Please wait at least 60 seconds before requesting a new code.";
        } else {
            $otp = random_int(100000, 999999);
            $_SESSION['fp_otp']            = (string) $otp;
            $_SESSION['fp_otp_expires_at'] = time() + 900;
            $_SESSION['fp_attempts']       = 0;
            $_SESSION['fp_last_sent_at']   = time();

            if (!empty($_SESSION['fp_user_id'])) {
                try {
                    $st = $pdo->prepare("SELECT first_name FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$_SESSION['fp_user_id']]);
                    $u = $st->fetch();
                    notify_password_reset_otp($_SESSION['fp_email'], $u['first_name'] ?? '', $otp, 15);
                } catch (PDOException $e) { error_log("forgot-password resend failed: " . $e->getMessage()); }
            }
            $notice = "If an account exists for that email, a new code has been sent.";
        }
        $stage = 'verify';
    }

    // ---------- STAGE 2: verify OTP only ----------
    if (isset($_POST['verify_code']) && ($_SESSION['fp_stage'] ?? '') === 'verify') {
        $code = trim((string) ($_POST['otp'] ?? ''));
        $_SESSION['fp_attempts'] = ($_SESSION['fp_attempts'] ?? 0) + 1;

        if ($_SESSION['fp_attempts'] > 5) {
            fp_reset_state();
            $error = "Too many incorrect attempts. Please start over.";
            $stage = 'request';
        } elseif (empty($code) || !preg_match('/^\d{6}$/', $code)) {
            $error = "Please enter the 6-digit code.";
            $stage = 'verify';
        } elseif (empty($_SESSION['fp_otp']) || empty($_SESSION['fp_otp_expires_at']) || time() > $_SESSION['fp_otp_expires_at']) {
            $error = "The code has expired. Please request a new one.";
            $stage = 'verify';
        } elseif (!hash_equals((string) $_SESSION['fp_otp'], $code)) {
            $error = "Incorrect code. Please try again.";
            $stage = 'verify';
        } else {
            // Code is good. Burn the OTP, mark verified, advance to reset stage.
            unset($_SESSION['fp_otp'], $_SESSION['fp_otp_expires_at']);
            $_SESSION['fp_verified']           = true;
            $_SESSION['fp_attempts']           = 0;
            // Give the user 15 minutes to set a password after verification.
            $_SESSION['fp_reset_expires_at']   = time() + 900;
            $_SESSION['fp_stage']              = 'reset';
            $stage = 'reset';
        }
    }

    // ---------- STAGE 3: set new password (must be verified) ----------
    if (isset($_POST['set_password']) && ($_SESSION['fp_stage'] ?? '') === 'reset') {
        if (empty($_SESSION['fp_verified'])) {
            fp_reset_state();
            $error = "Session expired. Please start over.";
            $stage = 'request';
        } elseif (empty($_SESSION['fp_reset_expires_at']) || time() > $_SESSION['fp_reset_expires_at']) {
            fp_reset_state();
            $error = "Your verification expired. Please start over.";
            $stage = 'request';
        } else {
            $newPassword     = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (empty($newPassword) || empty($confirmPassword)) {
                $error = "Please fill in both password fields.";
                $stage = 'reset';
            } elseif (strlen($newPassword) < 8) {
                $error = "New password must be at least 8 characters.";
                $stage = 'reset';
            } elseif ($newPassword !== $confirmPassword) {
                $error = "Passwords do not match.";
                $stage = 'reset';
            } elseif (empty($_SESSION['fp_user_id'])) {
                // Verified email had no real account behind it — show generic success.
                fp_reset_state();
                $_SESSION['fp_stage'] = 'done';
                $stage = 'done';
                $notice = "Your password has been reset. You can sign in now.";
            } else {
                try {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                        ->execute([$hash, $_SESSION['fp_user_id']]);
                    fp_reset_state();
                    $_SESSION['fp_stage'] = 'done';
                    $stage = 'done';
                    $notice = "Your password has been reset. You can sign in now.";
                } catch (PDOException $e) {
                    error_log("forgot-password update failed: " . $e->getMessage());
                    $error = "Failed to update password. Please try again.";
                    $stage = 'reset';
                }
            }
        }
    }
}

// If somebody hits ?stage=reset (or refreshes that page) without being verified,
// bump them back to the appropriate stage.
if ($stage === 'reset' && empty($_SESSION['fp_verified'])) {
    $stage = isset($_SESSION['fp_email']) ? 'verify' : 'request';
}

$page_title = 'Reset Password — Vormox';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head><?= csrf_meta() ?>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($page_title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

  <script>
    const savedTheme = localStorage.getItem('theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    const initialTheme = savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', initialTheme);
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
      --font-body: 'Instrument Sans', sans-serif;
    }
    [data-theme="light"] {
      --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0;
      --border: #e2e8f0; --border-strong: #cbd5e1;
      --accent: #2563eb; --accent2: #3b82f6; --accent-glow: rgba(37,99,235,0.15);
      --accent-green: #0891b2; --accent-red: #dc2626;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
    }
    body { background: var(--bg); color: var(--text); font-family: var(--font-body); min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    .grid-bg { position: fixed; inset: 0; pointer-events: none; z-index: 0; background-image: linear-gradient(rgba(59,130,246,.04) 1px,transparent 1px), linear-gradient(90deg,rgba(59,130,246,.04) 1px,transparent 1px); background-size: 60px 60px; }
    .hero-glow { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 800px; height: 800px; pointer-events: none; z-index: 0; background: radial-gradient(circle at center, rgba(59,130,246,0.1) 0%, transparent 60%); }
    nav { position: relative; z-index: 10; padding: 24px clamp(24px,5vw,80px); display: flex; align-items: center; justify-content: space-between; }
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--text); letter-spacing: -.5px; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,var(--accent),var(--accent-green)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    .logo span { color: var(--accent2); }
    .back-link { font-size: 14px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: color 0.2s; display: flex; align-items: center; gap: 6px; }
    .back-link:hover { color: var(--text); }
    main { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; padding: 40px 24px; }
    .auth-card { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 48px 40px; width: 100%; max-width: 440px; box-shadow: 0 24px 64px rgba(0,0,0,0.4); }
    [data-theme="light"] .auth-card { box-shadow: 0 24px 64px rgba(0,0,0,0.05); }
    .auth-header { text-align: center; margin-bottom: 32px; }
    .auth-title { font-family: var(--font-head); font-size: 28px; font-weight: 700; color: var(--text); margin-bottom: 8px; letter-spacing: -.02em; }
    .auth-sub { font-size: 15px; color: var(--text-muted); }
    .form-group { margin-bottom: 20px; text-align: left; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 8px; }
    input { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 15px; outline: none; transition: all 0.2s; }
    input:focus { border-color: var(--accent); background: var(--bg); box-shadow: 0 0 0 3px var(--accent-glow); }
    input.otp { font-family: var(--font-mono); font-size: 24px; text-align: center; letter-spacing: 10px; }
    .btn-submit { width: 100%; padding: 14px; background: var(--accent); color: #fff; font-family: var(--font-body); font-size: 15px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 20px rgba(59,130,246,.3); margin-top: 8px; }
    .btn-submit:hover { background: #2563eb; transform: translateY(-1px); }
    .btn-link { background: none; border: none; color: var(--accent2); cursor: pointer; padding: 0; font-size: 13px; font-weight: 500; font-family: var(--font-body); }
    .btn-link:hover { color: var(--accent-green); }
    .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
    .alert-error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: var(--accent-red); }
    .alert-info  { background: rgba(34,211,238,0.1); border: 1px solid rgba(34,211,238,0.3); color: var(--accent-green); }
    .auth-footer { margin-top: 32px; text-align: center; font-size: 14px; color: var(--text-muted); padding-top: 24px; border-top: 1px solid var(--border); }
    .auth-footer a { color: var(--text); text-decoration: none; font-weight: 600; }
    .auth-footer a:hover { color: var(--accent2); }
    .resend-row { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 13px; color: var(--text-muted); }

    .stepper { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 24px; font-size: 11px; font-family: var(--font-mono); text-transform: uppercase; color: var(--text-dim); letter-spacing: 0.08em; }
    .stepper .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border-strong); }
    .stepper .dot.active { background: var(--accent); box-shadow: 0 0 12px var(--accent-glow); }
    .stepper .dot.done { background: var(--accent-green); }
    .stepper .bar { width: 28px; height: 2px; background: var(--border-strong); }
    .stepper .bar.done { background: var(--accent-green); }
  </style>
</head>
<body>

<div class="grid-bg"></div>
<div class="hero-glow"></div>

<nav>
  <a href="index.php" class="logo"><div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>Vorm<span>ox</span></a>
  <a href="signin.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
</nav>

<main>
  <div class="auth-card">

    <?php
      // Build the step indicator
      $steps = ['request' => 0, 'verify' => 1, 'reset' => 2, 'done' => 3];
      $curStep = $steps[$stage] ?? 0;
    ?>
    <?php if ($stage !== 'done'): ?>
      <div class="stepper" aria-hidden="true">
        <span class="dot <?= $curStep === 0 ? 'active' : ($curStep > 0 ? 'done' : '') ?>"></span>
        <span class="bar <?= $curStep > 0 ? 'done' : '' ?>"></span>
        <span class="dot <?= $curStep === 1 ? 'active' : ($curStep > 1 ? 'done' : '') ?>"></span>
        <span class="bar <?= $curStep > 1 ? 'done' : '' ?>"></span>
        <span class="dot <?= $curStep === 2 ? 'active' : '' ?>"></span>
      </div>
    <?php endif; ?>

    <?php if ($stage === 'request'): ?>
      <div class="auth-header">
        <h1 class="auth-title">Reset your password</h1>
        <p class="auth-sub">We'll email a 6-digit code to verify it's you.</p>
      </div>

      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($notice) ?></div><?php endif; ?>

      <form method="POST" action="forgot-password.php"><?= csrf_field() ?>
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required autofocus
                 placeholder="you@yourcompany.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" name="request_reset" class="btn-submit">Send Reset Code <i class="fa-solid fa-paper-plane" style="margin-left: 6px; font-size: 12px;"></i></button>
      </form>

      <div class="auth-footer">
        Remembered it? <a href="signin.php">Sign in instead</a>
      </div>

    <?php elseif ($stage === 'verify'): ?>
      <div class="auth-header">
        <h1 class="auth-title">Enter your code</h1>
        <p class="auth-sub">Sent to <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>. Code expires in 15 minutes.</p>
      </div>

      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($notice) ?></div><?php endif; ?>

      <form method="POST" action="forgot-password.php"><?= csrf_field() ?>
        <div class="form-group">
          <label for="otp">6-Digit Code</label>
          <input type="text" id="otp" name="otp" class="otp" maxlength="6"
                 inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code"
                 required autofocus placeholder="000000">
        </div>
        <button type="submit" name="verify_code" class="btn-submit"><i class="fa-solid fa-shield-check"></i> Verify Code</button>

        <div class="resend-row">
          <span>Didn't receive it?</span>
          <button type="submit" name="resend_code" class="btn-link">Send a new code</button>
        </div>
      </form>

      <div class="auth-footer">
        Wrong email? <a href="forgot-password.php?restart=1">Start over</a>
      </div>

    <?php elseif ($stage === 'reset'): ?>
      <div class="auth-header">
        <h1 class="auth-title">Choose a new password</h1>
        <p class="auth-sub">Code verified for <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>. Pick a new password to finish.</p>
      </div>

      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="POST" action="forgot-password.php"><?= csrf_field() ?>
        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" required
                 minlength="8" autofocus placeholder="Min. 8 characters" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required
                 minlength="8" placeholder="Repeat password" autocomplete="new-password">
        </div>
        <button type="submit" name="set_password" class="btn-submit"><i class="fa-solid fa-lock"></i> Set New Password</button>
      </form>

      <div class="auth-footer">
        <a href="forgot-password.php?restart=1">Start over</a>
      </div>

    <?php else: /* done */ ?>
      <div class="auth-header">
        <h1 class="auth-title">All set</h1>
        <p class="auth-sub"><?= htmlspecialchars($notice ?: 'Your password has been reset.') ?></p>
      </div>
      <a href="signin.php" class="btn-submit" style="display:block; text-align:center; text-decoration:none;">Sign In <i class="fa-solid fa-arrow-right" style="margin-left: 6px; font-size: 12px;"></i></a>
    <?php endif; ?>

  </div>
</main>

</body>
</html>
