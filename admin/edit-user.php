<?php
session_start();
require_once '../config.php';
require_once '../includes/notifications.php';
require_once '../includes/pricing.php';

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
$active_tab = 'settings'; // Default tab
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id) { header("Location: users.php"); exit; }

// --- HANDLE POST: SETTINGS & BILLING UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $active_tab = 'settings';
    
    // Core User Details
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $theme = filter_input(INPUT_POST, 'theme', FILTER_SANITIZE_SPECIAL_CHARS);
    $wallet_balance = filter_input(INPUT_POST, 'wallet_balance', FILTER_VALIDATE_FLOAT);
    $new_password = $_POST['new_password'] ?? '';

    // Billing Profile Details
    $billing_type = filter_input(INPUT_POST, 'billing_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'individual';
    $company_name = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $tax_id = filter_input(INPUT_POST, 'tax_id', FILTER_SANITIZE_SPECIAL_CHARS);
    $billing_email = filter_input(INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL) ?: $email;
    $address_line1 = filter_input(INPUT_POST, 'address_line1', FILTER_SANITIZE_SPECIAL_CHARS);
    $address_line2 = filter_input(INPUT_POST, 'address_line2', FILTER_SANITIZE_SPECIAL_CHARS);
    $city = filter_input(INPUT_POST, 'city', FILTER_SANITIZE_SPECIAL_CHARS);
    $state_province = filter_input(INPUT_POST, 'state_province', FILTER_SANITIZE_SPECIAL_CHARS);
    $postal_code = filter_input(INPUT_POST, 'postal_code', FILTER_SANITIZE_SPECIAL_CHARS);
    $country = filter_input(INPUT_POST, 'country', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$email) {
        $error = "A valid email address is required.";
    } elseif ($wallet_balance === false) {
        $error = "Invalid wallet balance amount.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Check Email uniqueness
            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $checkEmail->execute(['email' => $email, 'id' => $user_id]);
            if ($checkEmail->fetch()) {
                throw new Exception("This email is already in use by another client.");
            } 

            // 2. Update Core Users Table
            $sql = "UPDATE users SET first_name = :fn, last_name = :ln, email = :email, theme = :theme, wallet_balance = :balance";
            $params = ['fn' => $first_name, 'ln' => $last_name, 'email' => $email, 'theme' => in_array($theme, ['dark', 'light']) ? $theme : 'dark', 'balance' => $wallet_balance, 'id' => $user_id];
            
            if (!empty($new_password)) {
                $sql .= ", password_hash = :pass";
                $params['pass'] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);

            // 3. UPSERT User Billing Profile
            $pdo->prepare("
                INSERT INTO user_billing_profiles 
                (user_id, billing_type, company_name, tax_id, billing_email, address_line1, address_line2, city, state_province, postal_code, country) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                billing_type = VALUES(billing_type), company_name = VALUES(company_name), tax_id = VALUES(tax_id), billing_email = VALUES(billing_email),
                address_line1 = VALUES(address_line1), address_line2 = VALUES(address_line2), city = VALUES(city), state_province = VALUES(state_province),
                postal_code = VALUES(postal_code), country = VALUES(country)
            ")->execute([
                $user_id, $billing_type, $company_name, $tax_id, $billing_email, 
                $address_line1, $address_line2, $city, $state_province, $postal_code, $country
            ]);

            $pdo->commit();
            $success = "Client core and billing profiles updated successfully.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage() ?: "Database error while updating user.";
        }
    }
}

// --- HANDLE POST: BULK GENERATE INVOICES (PANELS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_generate_invoices'])) {
    $active_tab = 'panels';
    $selected_panels = $_POST['selected_panels'] ?? [];
    
    if (!empty($selected_panels)) {
        try {
            $pdo->beginTransaction();
            $generated = 0; $skipped = 0;
            
            foreach ($selected_panels as $pid) {
                // Prevent duplicate Unpaid invoices for the same panel
                $checkInv = $pdo->prepare("SELECT id FROM invoices WHERE panel_id = ? AND status = 'Unpaid' LIMIT 1");
                $checkInv->execute([$pid]);
                
                if ($checkInv->fetch()) {
                    $skipped++; continue;
                }

                $stmt = $pdo->prepare("SELECT user_id, total_price, billing_cycle, expiry_date FROM user_panels WHERE id = ?");
                $stmt->execute([$pid]);
                $panel = $stmt->fetch();
                
                if ($panel) {
                    $invoice_num = 'INV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    $amount = $panel['total_price'] ?? 15.00;
                    $due_date = date('Y-m-d', strtotime('+3 days'));
                    
                    // CALCULATE THE RECURRING PERIOD
                    $cycle = $panel['billing_cycle'] ?? 'monthly';
                    $current_expiry = $panel['expiry_date'];
                    
                    $base_time = (empty($current_expiry) || strtotime($current_expiry) < time()) ? time() : strtotime($current_expiry);
                    
                    $months_to_add = 1;
                    if ($cycle === 'quarterly') $months_to_add = 3;
                    if ($cycle === 'semi_annually') $months_to_add = 6;
                    if ($cycle === 'yearly' || $cycle === 'annually') $months_to_add = 12;
                    
                    $period_start = date('Y-m-d', $base_time);
                    $period_end = date('Y-m-d', strtotime("+$months_to_add months", $base_time));
                    
                    // UPDATED: Now inserting with type = 'renew'
                    $invStmt = $pdo->prepare("
                        INSERT INTO invoices (user_id, panel_id, invoice_number, amount, type, status, due_date, period_start, period_end, created_at)
                        VALUES (?, ?, ?, ?, 'renew', 'Unpaid', ?, ?, ?, NOW())
                    ");
                    $invStmt->execute([
                        $panel['user_id'], $pid, $invoice_num, $amount, $due_date, $period_start, $period_end
                    ]);
                    $generated++;

                    // Notify the customer that a renewal invoice was issued for them.
                    try {
                        $u = $pdo->prepare("
                            SELECT u.email, u.first_name, p.domain
                              FROM users u
                              JOIN user_panels p ON p.id = ?
                             WHERE u.id = ? LIMIT 1
                        ");
                        $u->execute([$pid, $panel['user_id']]);
                        if ($row = $u->fetch()) {
                            notify_invoice_created(
                                $row['email'],
                                $row['first_name'],
                                $invoice_num,
                                $amount,
                                $due_date,
                                "Renewal invoice for <strong>" . htmlspecialchars($row['domain'], ENT_QUOTES) . "</strong> covering " . date('M j, Y', strtotime($period_start)) . " — " . date('M j, Y', strtotime($period_end)) . "."
                            );
                        }
                    } catch (PDOException $e) { error_log("Renewal email lookup failed: " . $e->getMessage()); }
                }
            }
            $pdo->commit();
            
            if ($generated > 0 && $skipped > 0) {
                $success = "Generated $generated invoice(s). Skipped $skipped because they already have pending Unpaid invoices.";
            } elseif ($generated > 0) {
                $success = "Successfully generated $generated recurring unpaid invoice(s).";
            } elseif ($skipped > 0) {
                $error = "No invoices generated. All selected panels already have an existing Unpaid invoice.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error generating recurring invoices.";
        }
    } else {
        $error = "Please select at least one panel.";
    }
}

// --- HANDLE POST: BULK UPDATE INVOICE STATUS (Auto-Extend Logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_invoices'])) {
    $active_tab = 'invoices';
    $selected_invoices = $_POST['selected_invoices'] ?? [];
    $new_status = filter_input(INPUT_POST, 'invoice_status', FILTER_SANITIZE_SPECIAL_CHARS);
    $valid_inv_statuses = ['Paid', 'Unpaid', 'Cancelled'];
    
    if (!empty($selected_invoices) && in_array($new_status, $valid_inv_statuses)) {
        try {
            $processed = 0;
            foreach ($selected_invoices as $inv_id) {
                $pdo->beginTransaction();
                
                $was_expired_renewal  = false;
                $new_expiry_for_email = null;
                $emailTarget          = null;

                // IF MARKING AS PAID -> CREDIT WALLET (top-up), UPGRADE PLAN, or EXTEND PANEL (renewal)
                if ($new_status === 'Paid') {
                    $chkStmt = $pdo->prepare("
                        SELECT i.status, i.panel_id, i.type, i.amount, i.user_id, i.invoice_number,
                               u.email AS user_email, u.first_name AS user_first_name,
                               p.billing_cycle, p.expiry_date, p.pending_nodes_count, p.domain AS panel_domain
                        FROM invoices i
                        JOIN users u       ON u.id = i.user_id
                        LEFT JOIN user_panels p ON i.panel_id = p.id
                        WHERE i.id = ?
                    ");
                    $chkStmt->execute([$inv_id]);
                    $invData = $chkStmt->fetch();

                    if ($invData && $invData['status'] !== 'Paid') {

                        $is_upgrade = (strpos($invData['invoice_number'] ?? '', 'UPG-') === 0);

                        // ROUTE A: Wallet Top-Up
                        if ($invData['type'] === 'topup' || strpos($invData['invoice_number'] ?? '', 'WAL-') === 0) {
                            $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
                                ->execute([$invData['amount'], $invData['user_id']]);
                        }
                        // ROUTE B: Plan Upgrade — bump nodes_count, refresh total_price, DON'T extend expiry
                        elseif ($is_upgrade && !empty($invData['panel_id']) && !empty($invData['pending_nodes_count'])) {
                            vormox_apply_panel_upgrade(
                                $pdo,
                                $invData['panel_id'],
                                $invData['pending_nodes_count'],
                                $invData['billing_cycle']
                            );
                        }
                        // ROUTE C: Infrastructure Order/Renew
                        elseif (!empty($invData['panel_id'])) {
                            $was_expired_renewal = (
                                !empty($invData['expiry_date'])
                                && strtotime($invData['expiry_date']) < time()
                            );

                            $new_expiry_for_email = vormox_apply_panel_renewal(
                                $pdo,
                                $invData['panel_id'],
                                $invData['billing_cycle'] ?? 'monthly',
                                $invData['expiry_date']
                            );
                            $emailTarget = $invData;
                        }
                    }
                }

                $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$new_status, $inv_id]);
                $pdo->commit();
                $processed++;

                // Payment receipt for any invoice that just flipped Paid.
                if ($new_status === 'Paid' && !empty($invData) && $invData['status'] !== 'Paid') {
                    notify_invoice_paid(
                        $invData['user_email'] ?? null,
                        $invData['user_first_name'] ?? null,
                        $invData['invoice_number'],
                        $invData['amount'],
                        "Payment confirmed by Vormox billing team."
                    );
                }
                if ($was_expired_renewal && $new_expiry_for_email && $emailTarget) {
                    notify_panel_renewed_after_expiry(
                        $emailTarget['user_email'],
                        $emailTarget['user_first_name'],
                        $emailTarget['panel_domain'],
                        $new_expiry_for_email
                    );
                }
            }
            $success = "Successfully updated $processed invoice(s). Time extensions applied to active panels.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = "Database error updating invoice statuses.";
        }
    } else {
        $error = "Please select at least one invoice and a valid status.";
    }
}

// --- FETCH DATA FOR NOTIFICATIONS AND VIEWS ---
$current_page = 'users.php'; 
$pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- FETCH USER DETAILS & ALL ASSETS ---
try {
    // JOIN Billing profile fields
    $stmt = $pdo->prepare("
        SELECT u.*, 
               b.billing_type, b.company_name, b.tax_id, b.billing_email, 
               b.address_line1, b.address_line2, b.city, b.state_province, b.postal_code, b.country 
        FROM users u 
        LEFT JOIN user_billing_profiles b ON u.id = b.user_id
        WHERE u.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();
    if (!$user) { die("Client not found."); }

    // Fetch Panels
    $panelsStmt = $pdo->prepare("SELECT * FROM user_panels WHERE user_id = :id ORDER BY created_at DESC");
    $panelsStmt->execute(['id' => $user_id]);
    $userPanels = $panelsStmt->fetchAll();

    // Fetch Invoices (Now includes new `type` field implicitly)
    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE user_id = :id ORDER BY created_at DESC");
    $invStmt->execute(['id' => $user_id]);
    $userInvoices = $invStmt->fetchAll();

    // Fetch Tickets
    $ticStmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = :id ORDER BY updated_at DESC");
    $ticStmt->execute(['id' => $user_id]);
    $userTickets = $ticStmt->fetchAll();

} catch (PDOException $e) {
    die("Database error while loading client assets.");
}

$page_title = 'Client Hub: ' . $user['first_name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head><?= csrf_meta() ?>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <!-- Vormox favicon (global) -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    document.documentElement.setAttribute('data-theme', savedTheme === 'dark' ? 'dark' : 'light');
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
    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; margin-bottom: 24px; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }

    /* Client Header Profile */
    .profile-header { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 32px; display: flex; align-items: center; gap: 24px; margin-bottom: 32px; position: relative; overflow: hidden; }
    .avatar-large { width: 80px; height: 80px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 20px; display: flex; align-items: center; justify-content: center; font-family: var(--font-head); font-size: 32px; font-weight: 800; color: #fff; box-shadow: 0 10px 30px var(--accent-glow); }
    .profile-meta h2 { font-family: var(--font-head); font-size: 24px; color: var(--text); margin-bottom: 4px; }
    .profile-meta p { color: var(--text-muted); font-family: var(--font-mono); font-size: 13px; }
    .client-id { position: absolute; top: 32px; right: 32px; font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }

    /* Tab Navigation */
    .tab-nav { display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
    .tab-btn { padding: 10px 20px; border-radius: 8px; background: transparent; color: var(--text-muted); border: 1px solid transparent; cursor: pointer; font-weight: 600; font-family: var(--font-body); font-size: 14px; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
    .tab-btn:hover { color: var(--text); background: rgba(139,92,246,0.05); }
    .tab-btn.active { background: var(--surface2); color: var(--accent2); border-color: var(--border-strong); box-shadow: 0 4px 15px rgba(139,92,246,0.1); }
    .tab-badge { background: var(--bg2); border: 1px solid var(--border-strong); padding: 2px 8px; border-radius: 10px; font-size: 11px; font-family: var(--font-mono); color: var(--text); }

    .tab-pane { display: none; animation: fadeIn 0.3s ease; }
    .tab-pane.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Form Styles */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 10px; background: rgba(0,0,0,0.1); color: var(--text); }
    
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding: 32px; border-bottom: 1px solid var(--border); }
    @media(max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; transition: 0.2s; }
    .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    
    .wallet-input { position: relative; }
    .wallet-input i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--accent-green); }
    .wallet-input input { padding-left: 40px; font-family: var(--font-mono); font-weight: 600; color: var(--accent-green); border-color: rgba(34,211,238,0.3); background: rgba(34,211,238,0.02); }
    .wallet-input input:focus { border-color: var(--accent-green); box-shadow: 0 0 0 3px rgba(34,211,238,0.2); }

    .btn-submit { display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px; background: var(--accent2); color: #fff; border: none; border-radius: 8px; font-family: var(--font-body); font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-submit:hover { transform: translateY(-1px); filter: brightness(1.1); }

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

    .table-btn { padding: 6px 12px; background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; transition: 0.2s; display: inline-block; }
    .table-btn:hover { background: var(--accent2); color: #fff; border-color: var(--accent2); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-active, .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-suspended, .badge-error, .badge-Cancelled { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-pending, .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-creating, .badge-restarting { background: rgba(59,130,246,0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.2); }
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
    <a href="index.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if($pendingOrdersCount > 0): ?><span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span><?php endif; ?>
    </a>
    
    <a href="users.php" class="nav-item active"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    <a href="backups.php" class="nav-item <?= $current_page == 'backups.php' ? 'active' : '' ?>"><i class="fa-solid fa-clock-rotate-left"></i> Backups</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
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
    <div class="header-title">Client Command Center</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <a href="users.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Directory</a>

    <div class="profile-header">
        <div class="client-id">Client ID: #<?= htmlspecialchars($user['id']) ?></div>
        <div class="avatar-large"><?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) ?></div>
        <div class="profile-meta">
            <h2><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
            <p><i class="fa-regular fa-envelope" style="margin-right: 6px;"></i> <?= htmlspecialchars($user['email']) ?></p>
            <div style="margin-top: 12px; display: flex; gap: 16px; color: var(--text-dim); font-size: 12px;">
                <span><i class="fa-solid fa-clock-rotate-left"></i> Last Login: <?= $user['last_login'] ? date('M j, Y - g:i A', strtotime($user['last_login'])) : 'Never' ?></span>
                <span><i class="fa-solid fa-network-wired"></i> Last IP: <?= htmlspecialchars($user['last_ip'] ?? 'N/A') ?></span>
                <span><i class="fa-solid fa-calendar-plus"></i> Registered: <?= date('M j, Y', strtotime($user['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <div class="tab-nav">
        <button class="tab-btn <?= $active_tab == 'settings' ? 'active' : '' ?>" data-target="tab-settings"><i class="fa-solid fa-user-pen"></i> Account & Billing</button>
        <button class="tab-btn <?= $active_tab == 'panels' ? 'active' : '' ?>" data-target="tab-panels"><i class="fa-solid fa-server"></i> Provisioned Panels <span class="tab-badge"><?= count($userPanels) ?></span></button>
        <button class="tab-btn <?= $active_tab == 'invoices' ? 'active' : '' ?>" data-target="tab-invoices"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices <span class="tab-badge"><?= count($userInvoices) ?></span></button>
        <button class="tab-btn <?= $active_tab == 'tickets' ? 'active' : '' ?>" data-target="tab-tickets"><i class="fa-solid fa-headset"></i> Support Tickets <span class="tab-badge"><?= count($userTickets) ?></span></button>
    </div>

    <div id="tab-settings" class="tab-pane <?= $active_tab == 'settings' ? 'active' : '' ?>">
        <form method="POST" action="edit-user.php?id=<?= (int)$user_id ?>" class="card"><?= csrf_field() ?>
            
            <div class="card-title">
                <div style="color: var(--text);"><i class="fa-solid fa-user" style="color: var(--accent); margin-right: 8px;"></i> Core Account Settings</div>
            </div>
            <div class="form-grid">
                <div>
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                </div>

                <div>
                    <div class="form-group">
                        <label>Wallet Balance (Credits)</label>
                        <div class="wallet-input">
                            <i class="fa-solid fa-dollar-sign"></i>
                            <input type="number" step="0.01" name="wallet_balance" class="form-control" value="<?= htmlspecialchars($user['wallet_balance'] ?? 0.00) ?>" required>
                        </div>
                        <div style="font-size: 11px; color: var(--text-dim); margin-top: 6px;">Adjusting this immediately alters the user's purchasing power.</div>
                    </div>
                    <div class="form-group">
                        <label>Client Interface Theme</label>
                        <select name="theme" class="form-control">
                            <option value="dark" <?= $user['theme'] == 'dark' ? 'selected' : '' ?>>Dark Mode (Default)</option>
                            <option value="light" <?= $user['theme'] == 'light' ? 'selected' : '' ?>>Light Mode</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Force Password Reset</label>
                        <input type="text" name="new_password" class="form-control" placeholder="Leave blank to keep current password">
                    </div>
                </div>
            </div>

            <div class="card-title" style="border-top: 1px solid var(--border);">
                <div style="color: var(--text);"><i class="fa-solid fa-address-card" style="color: var(--accent-orange); margin-right: 8px;"></i> Billing Profile Details</div>
            </div>
            <div class="form-grid">
                <div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Account Type</label>
                            <select name="billing_type" class="form-control" onchange="document.getElementById('compField').style.display = (this.value === 'company') ? 'block' : 'none';">
                                <option value="individual" <?= ($user['billing_type'] ?? '') == 'individual' ? 'selected' : '' ?>>Individual</option>
                                <option value="company" <?= ($user['billing_type'] ?? '') == 'company' ? 'selected' : '' ?>>Company</option>
                            </select>
                        </div>
                        <div class="form-group" id="compField" style="display: <?= ($user['billing_type'] ?? '') == 'company' ? 'block' : 'none' ?>;">
                            <label>Company Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" placeholder="Company LLC">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" name="billing_email" class="form-control" value="<?= htmlspecialchars($user['billing_email'] ?? $user['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Tax ID / VAT Number</label>
                        <input type="text" name="tax_id" class="form-control" value="<?= htmlspecialchars($user['tax_id'] ?? '') ?>" placeholder="Optional">
                    </div>
                </div>

                <div>
                    <div class="form-group">
                        <label>Address Line 1</label>
                        <input type="text" name="address_line1" class="form-control" value="<?= htmlspecialchars($user['address_line1'] ?? '') ?>" placeholder="123 Main St">
                    </div>
                    <div class="form-group">
                        <label>Address Line 2</label>
                        <input type="text" name="address_line2" class="form-control" value="<?= htmlspecialchars($user['address_line2'] ?? '') ?>" placeholder="Apt, Suite, etc.">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>State / Province</label>
                            <input type="text" name="state_province" class="form-control" value="<?= htmlspecialchars($user['state_province'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>ZIP Code</label>
                            <input type="text" name="postal_code" class="form-control" value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" class="form-control" value="<?= htmlspecialchars($user['country'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div style="padding: 32px; display: flex; justify-content: flex-end; border-top: 1px solid var(--border);">
                <button type="submit" name="update_user" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Save Complete Profile</button>
            </div>
        </form>
    </div>

    <div id="tab-panels" class="tab-pane <?= $active_tab == 'panels' ? 'active' : '' ?>">
        <form method="POST" action="edit-user.php?id=<?= (int)$user_id ?>" class="card"><?= csrf_field() ?>
            <div class="card-title">
                <div style="color: var(--text);"><i class="fa-solid fa-server" style="color: var(--accent); margin-right: 8px;"></i> Client Infrastructure</div>
                <div>
                    <button type="submit" name="bulk_generate_invoices" class="btn-bulk" onclick="return confirm('Generate next recurring Unpaid invoices for the selected panels?');">
                        <i class="fa-solid fa-file-invoice-dollar"></i> Generate Next Invoice
                    </button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="5%"><input type="checkbox" id="selectAllPanels" title="Select All Panels"></th>
                        <th width="5%">ID</th>
                        <th width="20%">Domain</th>
                        <th width="15%">Nodes</th>
                        <th width="15%">Status</th>
                        <th width="15%">Expiry Date</th>
                        <th width="15%">Created</th>
                        <th width="10%" style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($userPanels)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 48px; color: var(--text-dim);">No panels found for this client.</td></tr>
                    <?php else: foreach($userPanels as $p): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_panels[]" value="<?= $p['id'] ?>" class="panel-checkbox"></td>
                            <td style="font-family: var(--font-mono); color: var(--text-muted);">#<?= $p['id'] ?></td>
                            <td style="font-weight: 600; color: var(--text);"><?= htmlspecialchars($p['domain']) ?></td>
                            <td style="font-weight: 500;"><?= $p['nodes_count'] ?> Node(s)</td>
                            <td><span class="badge badge-<?= $p['status'] ?>"><?= htmlspecialchars(str_replace('_', ' ', $p['status'])) ?></span></td>
                            <td style="font-family: var(--font-mono); font-size: 13px;">
                                <?php 
                                    if (!empty($p['expiry_date'])) {
                                        $exp = strtotime($p['expiry_date']);
                                        $color = ($exp < time()) ? 'var(--accent-red)' : 'var(--text)';
                                        echo "<span style='color: $color;'>" . date('M j, Y', $exp) . "</span>";
                                    } else {
                                        echo "<span style='color: var(--text-muted);'>N/A</span>";
                                    }
                                ?>
                            </td>
                            <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                            <td style="text-align: right;">
                                <?php if(in_array($p['status'], ['pending', 'payment_pending'])): ?>
                                    <a href="orders.php?action=fulfill&id=<?= $p['id'] ?>" class="table-btn"><i class="fa-solid fa-box-open"></i> Order</a>
                                <?php else: ?>
                                    <a href="manage_panel.php?id=<?= $p['id'] ?>" class="table-btn"><i class="fa-solid fa-server"></i> Manage</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>

    <div id="tab-invoices" class="tab-pane <?= $active_tab == 'invoices' ? 'active' : '' ?>">
        <form method="POST" action="edit-user.php?id=<?= (int)$user_id ?>" class="card"><?= csrf_field() ?>
            <div class="card-title">
                <div style="color: var(--text);"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent-green); margin-right: 8px;"></i> Billing History</div>
                <div style="display: flex; align-items: center;">
                    <select name="invoice_status" class="status-select" required>
                        <option value="">-- Set Status --</option>
                        <option value="Paid">Mark as Paid</option>
                        <option value="Unpaid">Mark as Unpaid</option>
                        <option value="Cancelled">Cancel Invoices</option>
                    </select>
                    <button type="submit" name="bulk_update_invoices" class="btn-bulk" onclick="return confirm('Update status for selected invoices? Note: Marking as Paid will automatically extend panel expiry dates.');"><i class="fa-solid fa-check"></i> Apply</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="5%"><input type="checkbox" id="selectAllInvoices" title="Select All Invoices"></th>
                        <th width="15%">Invoice Number</th>
                        <th width="10%">Type</th>
                        <th width="20%">Billing Period</th>
                        <th width="10%">Amount</th>
                        <th width="10%">Status</th>
                        <th width="15%">Date Issued</th>
                        <th width="15%" style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($userInvoices)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 48px; color: var(--text-dim);">No billing history for this client.</td></tr>
                    <?php else: foreach($userInvoices as $inv): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_invoices[]" value="<?= $inv['id'] ?>" class="invoice-checkbox"></td>
                            <td style="font-family: var(--font-mono); font-weight: 600; color: var(--text);"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($inv['type'] ?? 'order') ?>">
                                    <?= htmlspecialchars($inv['type'] ?? 'order') ?>
                                </span>
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
                            <td style="font-family: var(--font-mono); font-weight: 600;">$<?= number_format($inv['amount'], 2) ?></td>
                            <td><span class="badge badge-<?= $inv['status'] ?>"><?= htmlspecialchars($inv['status']) ?></span></td>
                            <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);"><?= date('M j, Y', strtotime($inv['created_at'])) ?></td>
                            <td style="text-align: right;">
                                <a href="../user/view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" target="_blank" class="table-btn"><i class="fa-solid fa-file-invoice"></i> View</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>

    <div id="tab-tickets" class="tab-pane <?= $active_tab == 'tickets' ? 'active' : '' ?>">
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th width="10%">ID</th>
                        <th width="35%">Subject</th>
                        <th width="20%">Status</th>
                        <th width="20%">Last Updated</th>
                        <th width="15%" style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($userTickets)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 48px; color: var(--text-dim);">No support tickets opened by this client.</td></tr>
                    <?php else: foreach($userTickets as $t): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); color: var(--text-muted);">#<?= $t['id'] ?></td>
                            <td style="font-weight: 600; color: var(--text);"><?= htmlspecialchars($t['subject']) ?></td>
                            <td><span class="badge badge-<?= str_replace('-', '', $t['status']) ?>"><?= htmlspecialchars(str_replace('-', ' ', $t['status'])) ?></span></td>
                            <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);"><?= date('M j, Y', strtotime($t['updated_at'])) ?></td>
                            <td style="text-align: right;">
                                <a href="view-ticket.php?id=<?= $t['id'] ?>" class="table-btn"><i class="fa-solid fa-reply"></i> Open Ticket</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
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

    // Tab Logic
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            // Remove active classes
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));
            
            // Add active class to clicked button and target pane
            btn.classList.add('active');
            const target = document.getElementById(btn.getAttribute('data-target'));
            if(target) target.classList.add('active');
        });
    });

    // Checkboxes Check-All Logic
    const selectAllPanels = document.getElementById('selectAllPanels');
    if (selectAllPanels) {
        selectAllPanels.addEventListener('change', function() {
            document.querySelectorAll('.panel-checkbox').forEach(cb => cb.checked = this.checked);
        });
    }

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
