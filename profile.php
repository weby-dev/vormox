<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$error = '';
$success = '';

// --- HANDLE FORM SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Update Personal Information
    if (isset($_POST['update_profile'])) {
        $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_SPECIAL_CHARS));
        $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_SPECIAL_CHARS));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));

        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error = "First name, last name, and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check if email is already taken by ANOTHER user
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
                $checkStmt->execute(['email' => $email, 'id' => $_SESSION['user_id']]);
                
                if ($checkStmt->fetch()) {
                    $error = "That email address is already in use by another account.";
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET first_name = :fn, last_name = :ln, email = :email WHERE id = :id");
                    $updateStmt->execute([
                        'fn' => $first_name,
                        'ln' => $last_name,
                        'email' => $email,
                        'id' => $_SESSION['user_id']
                    ]);
                    $success = "Profile information updated successfully.";
                }
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error = "An error occurred while updating your profile.";
            }
        }
    }

    // 2. Update Password
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All password fields are required.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            try {
                // Verify current password first
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $_SESSION['user_id']]);
                $userAuth = $stmt->fetch();

                if ($userAuth && password_verify($current_password, $userAuth['password_hash'])) {
                    // Hash new password and update
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $updatePassStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                    $updatePassStmt->execute(['hash' => $new_hash, 'id' => $_SESSION['user_id']]);
                    
                    $success = "Password updated successfully.";
                } else {
                    $error = "The current password you entered is incorrect.";
                }
            } catch (PDOException $e) {
                error_log("Password update error: " . $e->getMessage());
                $error = "An error occurred while updating your password.";
            }
        }
    }
}

// --- FETCH CURRENT USER DATA ---
try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("User fetch error: " . $e->getMessage());
    die("A system error occurred.");
}

// Pass variables to header
$page_title = 'Profile Settings';
$header_title = 'Account Settings';

include 'includes/header.php';
?>

<style>
    .page-header { margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); letter-spacing: -.02em; margin-bottom: 8px; }
    .page-sub { font-size: 15px; color: var(--text-muted); }

    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; align-items: start; }
    
    .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px; }
    .form-group:last-child { margin-bottom: 0; }
    
    label { font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; }
    
    input[type="text"], input[type="email"], input[type="password"] { 
        width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); 
        border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; 
        transition: all 0.2s; outline: none; 
    }
    input:focus { border-color: var(--accent); background: var(--bg); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    
    .form-actions { display: flex; justify-content: flex-end; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }

    @media (max-width: 1024px) {
        .settings-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="content-area">
    
    <div class="page-header">
        <h1 class="page-title">Profile Settings</h1>
        <p class="page-sub">Manage your personal information and security credentials.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="settings-grid">
        
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-user" style="color: var(--accent2); margin-right: 8px;"></i> Personal Information</div>
            </div>
            
            <form method="POST" action="profile.php">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars($user['first_name']) ?>">
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars($user['last_name']) ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required value="<?= htmlspecialchars($user['email']) ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-shield-halved" style="color: var(--accent-green); margin-right: 8px;"></i> Security</div>
            </div>
            
            <form method="POST" action="profile.php">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required placeholder="Min. 8 characters">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat new password">
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_password" class="btn-primary" style="background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); box-shadow: none;">Update Password</button>
                </div>
            </form>
        </div>

    </div>

</div>

<?php include 'includes/footer.php'; ?>
