<?php
// Ensure session is started and DB is connected before this runs.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($pdo)) { require_once 'config.php'; }
require_once __DIR__ . '/includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: signin.php");
    exit;
}

$auth_user_id = $_SESSION['user_id'];
// Added 'theme' to the fetched columns
$authStmt = $pdo->prepare("SELECT status, email, first_name, theme FROM users WHERE id = ?");
$authStmt->execute([$auth_user_id]);
$auth_user = $authStmt->fetch();

// If user deleted from DB, log them out
if (!$auth_user) {
    session_destroy();
    header("Location: signin.php");
    exit;
}

$user_status = $auth_user['status'];
$user_theme = $auth_user['theme'] ?? 'dark'; // Fallback to dark

// ==========================================
// HANDLE OTP AJAX REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    header('Content-Type: application/json');
    csrf_require();

    // Action: Send Email
    if ($_POST['auth_action'] === 'send_otp') {
        $otp = random_int(100000, 999999);
        $_SESSION['verify_otp']      = (string) $otp;
        $_SESSION['verify_otp_time'] = time();

        $ok = notify_email_verification_otp(
            $auth_user['email'],
            $auth_user['first_name'],
            $otp,
            15
        );

        if ($ok) {
            echo json_encode(['success' => true]);
        } else {
            // notify_email_verification_otp already logged the reason via error_log
            echo json_encode(['success' => false, 'message' => 'Failed to send the verification email. Please try again in a minute or contact support.']);
        }
        exit;
    }

    // Action: Verify OTP
    if ($_POST['auth_action'] === 'verify_otp') {
        $input_otp = $_POST['otp'] ?? '';
        
        if (isset($_SESSION['verify_otp']) && (time() - $_SESSION['verify_otp_time'] <= 900)) { // 15 mins expiry
            if ((string)$input_otp === (string)$_SESSION['verify_otp']) {
                // OTP is correct! Update DB.
                $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$auth_user_id]);
                unset($_SESSION['verify_otp']);
                unset($_SESSION['verify_otp_time']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid verification code. Try again.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        }
        exit;
    }
}

// ==========================================
// BLOCKING UI (If not active)
// ==========================================
if ($user_status === 'banned' || $user_status === 'unverified') {
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($user_theme) ?>">
<head><?= csrf_meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vormox — Security</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* Dynamic CSS Variables based on DB Theme */
        :root, [data-theme="dark"] {
            --bg: #050810;
            --surface: #0d1426;
            --border: rgba(139,92,246,0.2);
            --border-strong: rgba(139,92,246,0.3);
            --text: #e8edf8;
            --text-muted: #7a8aa8;
            --accent: #8b5cf6;
            --accent-glow: rgba(139,92,246,0.4);
            --pattern: rgba(139,92,246,0.05);
            --input-bg: #050810;
            --danger-bg: rgba(248,113,113,0.1);
            --danger-text: #f87171;
            --success-bg: rgba(34,211,238,0.1);
            --success-text: #22d3ee;
            --verify-bg: rgba(139,92,246,0.1);
            --verify-text: #a78bfa;
        }
        [data-theme="light"] {
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #cbd5e1;
            --border-strong: #94a3b8;
            --text: #0f172a;
            --text-muted: #475569;
            --accent: #7c3aed;
            --accent-glow: rgba(124,58,237,0.2);
            --pattern: rgba(124,58,237,0.05);
            --input-bg: #f1f5f9;
            --danger-bg: rgba(239,68,68,0.1);
            --danger-text: #dc2626;
            --success-bg: rgba(14,165,233,0.1);
            --success-text: #0284c7;
            --verify-bg: rgba(124,58,237,0.1);
            --verify-text: #6d28d9;
        }

        body { background: var(--bg); color: var(--text); font-family: 'Instrument Sans', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; overflow: hidden; margin: 0; transition: background 0.3s, color 0.3s; }
        
        .bg-pattern { position: absolute; inset: 0; background-image: linear-gradient(var(--pattern) 1px,transparent 1px), linear-gradient(90deg,var(--pattern) 1px,transparent 1px); background-size: 40px 40px; z-index: 0; pointer-events: none; }
        
        .modal { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 48px; width: 100%; max-width: 450px; text-align: center; position: relative; z-index: 10; box-shadow: 0 20px 50px rgba(0,0,0,0.1); }
        .modal-icon { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 24px; }
        
        .icon-banned { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-text); box-shadow: 0 0 30px var(--danger-bg); }
        .icon-verify { background: var(--verify-bg); color: var(--verify-text); border: 1px solid var(--border-strong); box-shadow: 0 0 30px var(--verify-bg); }
        
        h1 { font-family: 'Syne', sans-serif; font-size: 24px; font-weight: 700; margin-bottom: 12px; }
        p { color: var(--text-muted); font-size: 15px; line-height: 1.6; margin-bottom: 32px; }
        
        .input-group { margin-bottom: 24px; text-align: left; }
        .input-group input { width: 100%; padding: 16px; background: var(--input-bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: monospace; font-size: 24px; text-align: center; letter-spacing: 10px; outline: none; transition: 0.2s; }
        .input-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        
        .btn { width: 100%; padding: 16px; border-radius: 8px; border: none; font-family: 'Instrument Sans', sans-serif; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
        .btn-primary:hover:not(:disabled) { filter: brightness(1.1); transform: translateY(-2px); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .btn-secondary { background: transparent; color: var(--text-muted); margin-top: 16px; border: 1px solid var(--border); }
        .btn-secondary:hover { color: var(--text); border-color: var(--border-strong); }

        .alert { padding: 12px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 20px; display: none; }
        .alert.error { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-text); display: block; }
        .alert.success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-text); display: block; }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>

    <?php if ($user_status === 'banned'): ?>
        <div class="modal">
            <div class="modal-icon icon-banned"><i class="fa-solid fa-ban"></i></div>
            <h1>Account Suspended</h1>
            <p>Your access to Vormox Infrastructure has been revoked due to a violation of our Terms of Service or an administrative action.</p>
            <p style="margin-bottom: 0;">If you believe this is a mistake, please contact our administrative team directly at:</p>
            <a href="mailto:support@vormox.com" style="display: block; margin-top: 16px; color: var(--danger-text); font-weight: 600; font-size: 18px; text-decoration: none;">support@vormox.com</a>
        </div>
    <?php endif; ?>

    <?php if ($user_status === 'unverified'): ?>
        <div class="modal">
            <div class="modal-icon icon-verify"><i class="fa-solid fa-envelope-open-text"></i></div>
            <h1>Verify Your Email</h1>
            <p>To secure your account, we need to verify your email address. <strong>(<?= htmlspecialchars($auth_user['email']) ?>)</strong></p>
            
            <div id="alertBox" class="alert"></div>

            <div id="step1">
                <button type="button" class="btn btn-primary" id="btnSendOtp" onclick="sendOTP()"><i class="fa-solid fa-paper-plane"></i> Send Verification Code</button>
            </div>

            <div id="step2" style="display: none;">
                <div class="input-group">
                    <input type="text" id="otpInput" maxlength="6" placeholder="000000" autocomplete="off">
                </div>
                <button type="button" class="btn btn-primary" id="btnVerify" onclick="verifyOTP()"><i class="fa-solid fa-shield-check"></i> Verify & Unlock</button>
                <button type="button" class="btn btn-secondary" onclick="sendOTP()"><i class="fa-solid fa-rotate-right"></i> Resend Code</button>
            </div>
            
            <a href="logout.php" style="display: inline-block; margin-top: 24px; color: var(--text-muted); font-size: 13px; text-decoration: none;">Log out</a>
        </div>

        <script>
            function showAlert(type, message) {
                const alertBox = document.getElementById('alertBox');
                alertBox.className = `alert ${type}`;
                alertBox.innerHTML = message;
            }

            function sendOTP() {
                const btn = document.getElementById('btnSendOtp');
                const ogText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
                btn.disabled = true;

                const fd = new FormData();
                fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                fd.append('auth_action', 'send_otp');

                fetch('', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('step1').style.display = 'none';
                        document.getElementById('step2').style.display = 'block';
                        showAlert('success', 'A 6-digit code has been sent to your email.');
                    } else {
                        showAlert('error', data.message);
                    }
                })
                .catch(() => showAlert('error', 'Network error. Please try again.'))
                .finally(() => {
                    btn.innerHTML = ogText;
                    btn.disabled = false;
                });
            }

            function verifyOTP() {
                const otp = document.getElementById('otpInput').value;
                if(otp.length !== 6) {
                    showAlert('error', 'Please enter a valid 6-digit code.');
                    return;
                }

                const btn = document.getElementById('btnVerify');
                const ogText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
                btn.disabled = true;

                const fd = new FormData();
                fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                fd.append('auth_action', 'verify_otp');
                fd.append('otp', otp);

                fetch('', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        showAlert('success', 'Verification successful! Unlocking...');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showAlert('error', data.message);
                        btn.innerHTML = ogText;
                        btn.disabled = false;
                    }
                })
                .catch(() => {
                    showAlert('error', 'Network error. Please try again.');
                    btn.innerHTML = ogText;
                    btn.disabled = false;
                });
            }
        </script>
    <?php endif; ?>

</body>
</html>
<?php 
// THIS EXIT IS CRITICAL. 
// It guarantees that the browser stops processing right here. 
// The protected user dashboard/billing pages below this require_once will NEVER load.
exit; 
} 
?>
