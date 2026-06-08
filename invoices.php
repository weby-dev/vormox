<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// --- Handle Billing Settings Update (Using UPSERT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_billing'])) {
    csrf_require();
    
    $billing_type = filter_input(INPUT_POST, 'billing_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'individual';
    $company_name = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $tax_id = filter_input(INPUT_POST, 'tax_id', FILTER_SANITIZE_SPECIAL_CHARS);
    $billing_email = filter_input(INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL);
    $address_line1 = filter_input(INPUT_POST, 'address_line1', FILTER_SANITIZE_SPECIAL_CHARS);
    $address_line2 = filter_input(INPUT_POST, 'address_line2', FILTER_SANITIZE_SPECIAL_CHARS);
    $city = filter_input(INPUT_POST, 'city', FILTER_SANITIZE_SPECIAL_CHARS);
    $state_province = filter_input(INPUT_POST, 'state_province', FILTER_SANITIZE_SPECIAL_CHARS);
    $postal_code = filter_input(INPUT_POST, 'postal_code', FILTER_SANITIZE_SPECIAL_CHARS);
    $country = filter_input(INPUT_POST, 'country', FILTER_SANITIZE_SPECIAL_CHARS);

    try {
        // Insert new profile, or Update existing profile if user_id already exists
        $updStmt = $pdo->prepare("
            INSERT INTO user_billing_profiles 
            (user_id, billing_type, company_name, tax_id, billing_email, address_line1, address_line2, city, state_province, postal_code, country) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            billing_type = VALUES(billing_type),
            company_name = VALUES(company_name),
            tax_id = VALUES(tax_id),
            billing_email = VALUES(billing_email),
            address_line1 = VALUES(address_line1),
            address_line2 = VALUES(address_line2),
            city = VALUES(city),
            state_province = VALUES(state_province),
            postal_code = VALUES(postal_code),
            country = VALUES(country)
        ");
        
        $updStmt->execute([
            $user_id, $billing_type, $company_name, $tax_id, $billing_email, 
            $address_line1, $address_line2, $city, $state_province, $postal_code, $country
        ]);
        
        $success = "Billing profile updated successfully.";
    } catch (PDOException $e) {
        $error = "Failed to update billing profile.";
    }
}

try {
    // Fetch User Profile, Wallet, and their joined Billing Profile
    $userStmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email, u.theme, u.wallet_balance,
               b.billing_type, b.company_name, b.tax_id, b.billing_email, 
               b.address_line1, b.address_line2, b.city, b.state_province, b.postal_code, b.country 
        FROM users u 
        LEFT JOIN user_billing_profiles b ON u.id = b.user_id 
        WHERE u.id = :id LIMIT 1
    ");
    $userStmt->execute(['id' => $user_id]);
    $user = $userStmt->fetch();

    // Fetch Invoices
    $invStmt = $pdo->prepare("
        SELECT i.*, p.domain 
        FROM invoices i 
        LEFT JOIN user_panels p ON i.panel_id = p.id 
        WHERE i.user_id = :id 
        ORDER BY i.created_at DESC
    ");
    $invStmt->execute(['id' => $user_id]);
    $invoices = $invStmt->fetchAll();

} catch (PDOException $e) {
    die("A system error occurred.");
}

$page_title = 'Billing & Invoices';
$header_title = 'Financial Ledger';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .alert { padding: 16px 24px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 12px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    /* Action Buttons */
    .btn-action { padding: 12px 24px; border-radius: 8px; font-family: var(--font-body); font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; border: none; }
    .btn-outline { background: transparent; border: 1px solid var(--border-strong); color: var(--text); }
    .btn-outline:hover { background: var(--surface2); }
    .btn-primary-action { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-primary-action:hover { filter: brightness(1.1); transform: translateY(-1px); }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .toolbar-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.1); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); background: rgba(100,116,139,0.03); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-Cancelled { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-Refunded { background: rgba(167,139,250,0.1); color: var(--accent2); border: 1px solid rgba(167,139,250,0.2); }

    .table-btn { padding: 8px 16px; background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; transition: 0.2s; display: inline-block; }
    .table-btn:hover { background: var(--accent2); color: #fff; border-color: var(--accent2); }
    
    .btn-pay { background: rgba(34,211,238,0.1); color: var(--accent-green); border-color: rgba(34,211,238,0.3); }
    .btn-pay:hover { background: var(--accent-green); color: #000; border-color: var(--accent-green); }

    /* Modals */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; overflow-y: auto; padding: 20px; }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-content { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 600px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); margin: auto; }
    
    .input-amount { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-head); font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 16px; outline: none; transition: 0.2s; }
    .input-amount:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    
    .form-group { margin-bottom: 16px; text-align: left; }
    .form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); font-family: var(--font-mono); text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select { width: 100%; padding: 12px 14px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; transition: 0.2s; }
    .form-group input:focus, .form-group select:focus { border-color: var(--accent); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
</style>

<div class="content-area">
    
    <div class="page-header">
        <div>
            <h1 class="page-title">Billing History</h1>
            <p class="page-sub">Manage your wallet, invoices, and billing profile.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div style="background: var(--surface2); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-family: var(--font-mono); font-weight: 600;">Available Wallet Balance</div>
            <div style="font-size: 28px; font-weight: 800; color: var(--text); font-family: var(--font-head);">$<?= number_format($user['wallet_balance'] ?? 0, 2) ?> USD</div>
        </div>
        <div style="display: flex; gap: 12px;">
            <button class="btn-outline btn-action" onclick="openBillingModal()"><i class="fa-solid fa-address-card"></i> Billing Settings</button>
            <button class="btn-primary-action btn-action" onclick="openAddFundsModal()"><i class="fa-solid fa-plus"></i> Add Funds</button>
        </div>
    </div>

    <div class="table-container">
        <div class="toolbar-header">
            <div style="font-weight: 600; color: var(--text);"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent); margin-right: 8px;"></i> All Invoices</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="15%">Invoice #</th>
                    <th width="20%">Service / Description</th>
                    <th width="20%">Billing Period</th>
                    <th width="10%">Amount</th>
                    <th width="10%">Status</th>
                    <th width="10%">Issued</th>
                    <th width="15%" style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">
                        <i class="fa-solid fa-receipt" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
                        You have no billing history.
                    </td>
                </tr>
                <?php else: foreach ($invoices as $inv): 
                    $is_wallet = (strpos($inv['invoice_number'], 'WAL-') === 0);
                ?>
                <tr>
                    <td style="font-family: var(--font-mono); font-weight: 600;">
                        <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" style="color: var(--accent2); text-decoration: none;">
                            <?= htmlspecialchars($inv['invoice_number']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if($is_wallet): ?>
                            <span style="color: var(--text-muted); font-size: 13px;"><i class="fa-solid fa-wallet" style="margin-right: 6px;"></i>Wallet Top-Up</span>
                        <?php elseif($inv['domain']): ?>
                            <div style="font-weight: 500; font-size: 13px;"><i class="fa-solid fa-server" style="color: var(--text-muted); font-size: 11px; margin-right: 6px;"></i><?= htmlspecialchars($inv['domain']) ?></div>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 13px;">General Account</span>
                        <?php endif; ?>
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
                    <td>
                        <span class="badge badge-<?= $inv['status'] ?>">
                            <?= htmlspecialchars($inv['status']) ?>
                        </span>
                    </td>
                    <td style="color: var(--text-muted); font-size: 13px;">
                        <?= date('M j, Y', strtotime($inv['created_at'])) ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if($inv['status'] === 'Unpaid'): ?>
                            <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" class="table-btn btn-pay"><i class="fa-solid fa-credit-card"></i> Pay Now</a>
                        <?php else: ?>
                            <a href="view-invoice.php?id=<?= urlencode($inv['invoice_number']) ?>" class="table-btn"><i class="fa-solid fa-eye"></i> View</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>

<div class="modal-overlay" id="addFundsModal">
    <div class="modal-content" style="text-align: center;">
        <h3 style="font-family: var(--font-head); margin-bottom: 8px;">Top Up Wallet</h3>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">Enter the amount of USD you want to add.</p>
        
        <form method="POST" action="add_funds.php"><?= csrf_field() ?>
            <input type="number" name="amount" class="input-amount" min="1" max="5000" step="1" placeholder="$10.00" required>
            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="button" class="btn-outline btn-action" onclick="closeAddFundsModal()" style="flex: 1; justify-content: center;">Cancel</button>
                <button type="submit" class="btn-primary-action btn-action" style="flex: 1; justify-content: center;">Create Invoice</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="billingModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h3 style="font-family: var(--font-head);">Billing Profile</h3>
            <button onclick="closeBillingModal()" style="background: transparent; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <form method="POST" action="invoices.php"><?= csrf_field() ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Account Type</label>
                    <select name="billing_type" onchange="toggleCompanyField(this.value)">
                        <option value="individual" <?= ($user['billing_type'] ?? '') == 'individual' ? 'selected' : '' ?>>Personal / Individual</option>
                        <option value="company" <?= ($user['billing_type'] ?? '') == 'company' ? 'selected' : '' ?>>Business / Company</option>
                    </select>
                </div>
                <div class="form-group" id="companyField" style="display: <?= ($user['billing_type'] ?? '') == 'company' ? 'block' : 'none' ?>;">
                    <label>Company Name</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" placeholder="Your Company LLC">
                </div>
            </div>

            <div class="form-group">
                <label>Billing Email</label>
                <input type="email" name="billing_email" value="<?= htmlspecialchars($user['billing_email'] ?? $user['email']) ?>" required>
            </div>

            <div class="form-group">
                <label>Street Address Line 1</label>
                <input type="text" name="address_line1" value="<?= htmlspecialchars($user['address_line1'] ?? '') ?>" placeholder="123 Main St" required>
            </div>
            
            <div class="form-group">
                <label>Street Address Line 2 (Optional)</label>
                <input type="text" name="address_line2" value="<?= htmlspecialchars($user['address_line2'] ?? '') ?>" placeholder="Apt, Suite, Bldg.">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>" placeholder="New York" required>
                </div>
                <div class="form-group">
                    <label>State / Province</label>
                    <input type="text" name="state_province" value="<?= htmlspecialchars($user['state_province'] ?? '') ?>" placeholder="NY" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ZIP / Postal Code</label>
                    <input type="text" name="postal_code" value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>" placeholder="10001" required>
                </div>
                <div class="form-group">
                    <label>Country</label>
                    <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>" placeholder="United States" required>
                </div>
            </div>

            <div class="form-group">
                <label>Tax ID / VAT Number (Optional)</label>
                <input type="text" name="tax_id" value="<?= htmlspecialchars($user['tax_id'] ?? '') ?>" placeholder="Company Tax ID">
            </div>
            
            <div style="margin-top: 24px; text-align: right;">
                <button type="button" class="btn-outline btn-action" onclick="closeBillingModal()" style="margin-right: 8px;">Cancel</button>
                <button type="submit" name="update_billing" class="btn-primary-action btn-action">Save Profile</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddFundsModal() { document.getElementById('addFundsModal').classList.add('show'); }
    function closeAddFundsModal() { document.getElementById('addFundsModal').classList.remove('show'); }

    function openBillingModal() { document.getElementById('billingModal').classList.add('show'); }
    function closeBillingModal() { document.getElementById('billingModal').classList.remove('show'); }

    function toggleCompanyField(val) {
        document.getElementById('companyField').style.display = (val === 'company') ? 'block' : 'none';
    }

    // Close modals on outside click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
