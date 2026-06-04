<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

// Handle Add Funds AJAX Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_funds_success') {
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($amount > 0 && $order_id) {
        try {
            $pdo->beginTransaction();

            // 1. Update the user's wallet balance
            $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :uid");
            $stmt->execute(['amount' => $amount, 'uid' => $_SESSION['user_id']]);

            // 2. Generate a "Paid" invoice for this Add Funds transaction
            $invoice_number = 'INV-FND-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            
            $invStmt = $pdo->prepare("
                INSERT INTO invoices (user_id, invoice_number, amount, status, due_date, created_at, updated_at) 
                VALUES (:uid, :inv_num, :amount, 'Paid', NOW(), NOW(), NOW())
            ");
            $invStmt->execute([
                'uid' => $_SESSION['user_id'],
                'inv_num' => $invoice_number,
                'amount' => $amount
            ]);

            $pdo->commit();
            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Failed to add funds and create invoice: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database transaction failed']);
        }
        exit;
    }
}

$error = '';
$success = '';

// Handle Billing Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_billing'])) {
    $billing_type = $_POST['billing_type'] === 'company' ? 'company' : 'individual';
    $company_name = $billing_type === 'company' ? trim(filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS)) : null;
    $tax_id = $billing_type === 'company' ? trim(filter_input(INPUT_POST, 'tax_id', FILTER_SANITIZE_SPECIAL_CHARS)) : null;
    
    $billing_email = trim(filter_input(INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL));
    $address_line1 = trim(filter_input(INPUT_POST, 'address_line1', FILTER_SANITIZE_SPECIAL_CHARS));
    $address_line2 = trim(filter_input(INPUT_POST, 'address_line2', FILTER_SANITIZE_SPECIAL_CHARS));
    $city = trim(filter_input(INPUT_POST, 'city', FILTER_SANITIZE_SPECIAL_CHARS));
    $state_province = trim(filter_input(INPUT_POST, 'state_province', FILTER_SANITIZE_SPECIAL_CHARS));
    $postal_code = trim(filter_input(INPUT_POST, 'postal_code', FILTER_SANITIZE_SPECIAL_CHARS));
    $country = trim(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_SPECIAL_CHARS));

    if (empty($billing_email) || empty($address_line1) || empty($city) || empty($state_province) || empty($postal_code) || empty($country)) {
        $error = "Please fill in all required address fields.";
    } elseif ($billing_type === 'company' && empty($company_name)) {
        $error = "Company Name is required for company billing.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_billing_profiles 
                (user_id, billing_type, company_name, tax_id, billing_email, address_line1, address_line2, city, state_province, postal_code, country) 
                VALUES 
                (:uid, :btype, :cname, :tax, :email, :addr1, :addr2, :city, :state, :zip, :country)
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
            
            $stmt->execute([
                'uid' => $_SESSION['user_id'],
                'btype' => $billing_type,
                'cname' => $company_name,
                'tax' => $tax_id,
                'email' => $billing_email,
                'addr1' => $address_line1,
                'addr2' => $address_line2,
                'city' => $city,
                'state' => $state_province,
                'zip' => $postal_code,
                'country' => $country
            ]);
            
            $success = "Billing profile updated successfully.";
        } catch (PDOException $e) {
            $error = "A database error occurred while updating your billing profile.";
        }
    }
}

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme, wallet_balance FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    $billingStmt = $pdo->prepare("SELECT * FROM user_billing_profiles WHERE user_id = :id LIMIT 1");
    $billingStmt->execute(['id' => $_SESSION['user_id']]);
    $billing = $billingStmt->fetch();

    if (!$billing) {
        $billing = [
            'billing_type' => 'individual',
            'company_name' => '',
            'tax_id' => '',
            'billing_email' => $user['email'],
            'address_line1' => '',
            'address_line2' => '',
            'city' => '',
            'state_province' => '',
            'postal_code' => '',
            'country' => ''
        ];
    }

    $gwStmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE status = 'active'");
    $gwStmt->execute();
    $gateways = $gwStmt->fetchAll();

    $paytmConfig = ['merchantId' => '', 'upiId' => '', 'environment' => 'production'];
    foreach ($gateways as $gw) {
        if ($gw['type'] === 'paytm') {
            $paytmConfig['merchantId'] = $gw['paytm_merchant_id'];
            $paytmConfig['upiId'] = $gw['paytm_upi_id'];
            $paytmConfig['environment'] = $gw['environment'];
        }
    }

} catch (PDOException $e) {
    die("A system error occurred.");
}

$page_title = 'Billing Settings';
$header_title = 'Billing & Payments';

include 'includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
    .page-header { margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); letter-spacing: -.02em; margin-bottom: 8px; }
    .page-sub { font-size: 15px; color: var(--text-muted); }

    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .billing-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; align-items: start; }
    
    .segmented-control { display: flex; background: var(--bg2); padding: 4px; border-radius: 10px; border: 1px solid var(--border-strong); margin-bottom: 32px; }
    .segmented-control label { flex: 1; text-align: center; padding: 12px; font-size: 14px; font-family: var(--font-body); text-transform: none; letter-spacing: 0; cursor: pointer; border-radius: 8px; transition: all 0.2s; color: var(--text-muted); font-weight: 500; }
    .segmented-control input[type="radio"] { display: none; }
    .segmented-control input[type="radio"]:checked + label { background: var(--surface); color: var(--text); box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid var(--border); font-weight: 600; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
    label.field-label { font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; }
    
    input[type="text"], input[type="number"], input[type="email"], select { 
        width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); 
        border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; 
        transition: all 0.2s; outline: none; appearance: none;
    }
    input:focus, select:focus { border-color: var(--accent); background: var(--bg); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    
    .select-wrapper { position: relative; }
    .select-wrapper::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
    select option { background: var(--surface2); color: var(--text); padding: 12px; }

    .company-fields { display: none; overflow: hidden; }
    .company-fields.active { display: block; animation: slideDown 0.3s ease-out forwards; }

    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .form-actions { display: flex; justify-content: flex-end; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }

    .side-panel-card { background: var(--bg2); border-radius: 12px; padding: 20px; margin-bottom: 24px; border: 1px dashed var(--border-strong); }
    .side-title { font-size: 13px; font-family: var(--font-mono); text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); margin-bottom: 12px; }
    .side-value { font-size: 32px; font-weight: 700; color: var(--text); margin-bottom: 8px; font-family: var(--font-head); }
    
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
    .modal-overlay.active { display: flex; opacity: 1; }
    .modal-content { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 450px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); transform: translateY(20px); transition: transform 0.2s; }
    .modal-overlay.active .modal-content { transform: translateY(0); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .modal-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text); }
    .btn-close { background: transparent; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; transition: color 0.2s; }
    .btn-close:hover { color: var(--text); }

    .qr-container { text-align: center; margin: 24px 0; display: none; }
    .qr-container.active { display: block; }
    #qrCode { display: inline-block; padding: 16px; background: #fff; border-radius: 12px; margin-bottom: 16px; border: 4px solid var(--border-strong); }
    
    .payment-status { text-align: center; padding: 12px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-top: 16px; }
    .status-pending { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .status-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .status-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(251,146,60,0.3); border-radius: 50%; border-top-color: var(--accent-orange); animation: spin 1s ease-in-out infinite; margin-right: 8px; vertical-align: middle; }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 1024px) {
        .billing-grid { grid-template-columns: 1fr; }
        .form-row { grid-template-columns: 1fr; }
    }
</style>

<div class="content-area">
    
    <div class="page-header">
        <h1 class="page-title">Billing Details</h1>
        <p class="page-sub">Manage your billing address and tax information.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="billing-grid">
        
        <div class="card">
            <form method="POST" action="billing.php">
                
                <div class="segmented-control">
                    <input type="radio" name="billing_type" id="type_individual" value="individual" <?= $billing['billing_type'] === 'individual' ? 'checked' : '' ?>>
                    <label for="type_individual"><i class="fa-solid fa-user" style="margin-right: 8px;"></i> Individual / Personal</label>
                    
                    <input type="radio" name="billing_type" id="type_company" value="company" <?= $billing['billing_type'] === 'company' ? 'checked' : '' ?>>
                    <label for="type_company"><i class="fa-solid fa-building" style="margin-right: 8px;"></i> Company / Business</label>
                </div>

                <div class="company-fields <?= $billing['billing_type'] === 'company' ? 'active' : '' ?>" id="company_fields_container">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="field-label" for="company_name">Company Name *</label>
                            <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($billing['company_name']) ?>" placeholder="e.g., Vormox LLC">
                        </div>
                        <div class="form-group">
                            <label class="field-label" for="tax_id">Tax ID / VAT Number</label>
                            <input type="text" id="tax_id" name="tax_id" value="<?= htmlspecialchars($billing['tax_id']) ?>" placeholder="Optional">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label" for="billing_email">Billing Email Address *</label>
                    <input type="email" id="billing_email" name="billing_email" required value="<?= htmlspecialchars($billing['billing_email']) ?>">
                    <span style="font-size: 12px; color: var(--text-dim); margin-top: 4px;">Invoices and billing alerts will be sent here.</span>
                </div>

                <div class="form-group" style="margin-top: 32px;">
                    <label class="field-label" for="address_line1">Address Line 1 *</label>
                    <input type="text" id="address_line1" name="address_line1" required value="<?= htmlspecialchars($billing['address_line1']) ?>">
                </div>

                <div class="form-group">
                    <label class="field-label" for="address_line2">Address Line 2</label>
                    <input type="text" id="address_line2" name="address_line2" value="<?= htmlspecialchars($billing['address_line2']) ?>" placeholder="Apartment, suite, unit, etc. (optional)">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="field-label" for="city">City *</label>
                        <input type="text" id="city" name="city" required value="<?= htmlspecialchars($billing['city']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="field-label" for="state_province">State / Province *</label>
                        <input type="text" id="state_province" name="state_province" required value="<?= htmlspecialchars($billing['state_province']) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="field-label" for="postal_code">Postal / Zip Code *</label>
                        <input type="text" id="postal_code" name="postal_code" required value="<?= htmlspecialchars($billing['postal_code']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="field-label" for="country">Country *</label>
                        <div class="select-wrapper">
                            <select id="country" name="country" required>
                                <option value="" disabled <?= empty($billing['country']) ? 'selected' : '' ?>>Select Country...</option>
                                <option value="United States" <?= $billing['country'] === 'United States' ? 'selected' : '' ?>>United States</option>
                                <option value="United Kingdom" <?= $billing['country'] === 'United Kingdom' ? 'selected' : '' ?>>United Kingdom</option>
                                <option value="Canada" <?= $billing['country'] === 'Canada' ? 'selected' : '' ?>>Canada</option>
                                <option value="Australia" <?= $billing['country'] === 'Australia' ? 'selected' : '' ?>>Australia</option>
                                <option value="Germany" <?= $billing['country'] === 'Germany' ? 'selected' : '' ?>>Germany</option>
                                <option value="India" <?= $billing['country'] === 'India' ? 'selected' : '' ?>>India</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_billing" class="btn-primary"><i class="fa-solid fa-floppy-disk" style="margin-right: 8px;"></i> Save Billing Details</button>
                </div>
            </form>
        </div>

        <div>
            <div class="side-panel-card">
                <div class="side-title">Account Balance</div>
                <div class="side-value">$<?= number_format($user['wallet_balance'], 2) ?></div>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">Add funds to deploy and maintain your active services.</p>
                <button id="addFundsBtn" class="btn-primary" style="width: 100%;"><i class="fa-solid fa-wallet" style="margin-right: 8px;"></i> Add Funds</button>
            </div>
        </div>

    </div>

</div>

<div id="addFundsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">Add Funds</div>
            <button id="closeFundsModalBtn" class="btn-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div id="fundsFormContainer">
            <div class="form-group">
                <label class="field-label" for="fund_amount">Amount ($ USD)</label>
                <input type="number" id="fund_amount" min="1" step="0.01" required placeholder="10.00">
            </div>
            
            <div class="form-group">
                <label class="field-label" for="payment_gateway">Select Payment Gateway</label>
                <div class="select-wrapper">
                    <select id="payment_gateway">
                        <?php foreach ($gateways as $gw): ?>
                            <option value="<?= htmlspecialchars($gw['type']) ?>"><?= htmlspecialchars($gw['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top: 32px; display: flex; justify-content: flex-end;">
                <button id="proceedPayBtn" class="btn-primary" style="width: 100%;">Proceed to Pay</button>
            </div>
        </div>

        <div id="qrContainer" class="qr-container">
            <p style="color: var(--text-muted); margin-bottom: 16px; font-size: 14px;">Scan the QR code with any UPI app to pay</p>
            <div id="qrCode"></div>
            <div id="paymentStatus" class="payment-status status-pending">
                <span class="spinner"></span> Waiting for payment...
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const radioIndividual = document.getElementById('type_individual');
    const radioCompany = document.getElementById('type_company');
    const companyFieldsContainer = document.getElementById('company_fields_container');
    const companyNameInput = document.getElementById('company_name');

    function toggleCompanyFields() {
        if (radioCompany.checked) {
            companyFieldsContainer.classList.add('active');
            companyNameInput.setAttribute('required', 'required');
        } else {
            companyFieldsContainer.classList.remove('active');
            companyNameInput.removeAttribute('required');
        }
    }

    if(radioIndividual) radioIndividual.addEventListener('change', toggleCompanyFields);
    if(radioCompany) radioCompany.addEventListener('change', toggleCompanyFields);

    const addFundsBtn = document.getElementById('addFundsBtn');
    const fundsModal = document.getElementById('addFundsModal');
    const closeFundsModalBtn = document.getElementById('closeFundsModalBtn');
    const proceedPayBtn = document.getElementById('proceedPayBtn');
    const fundsFormContainer = document.getElementById('fundsFormContainer');
    const qrContainer = document.getElementById('qrContainer');
    const paymentStatus = document.getElementById('paymentStatus');
    
    let checkStatusInterval;
    const paytmConfig = {
        merchantId: "<?= $paytmConfig['merchantId'] ?? '' ?>",
        upiId: "<?= $paytmConfig['upiId'] ?? '' ?>",
        environment: "<?= $paytmConfig['environment'] ?? 'production' ?>",
        userId: "<?= $_SESSION['user_id'] ?>"
    };

    function resetModal() {
        if(!fundsFormContainer) return;
        fundsFormContainer.style.display = 'block';
        qrContainer.classList.remove('active');
        document.getElementById('qrCode').innerHTML = '';
        document.getElementById('fund_amount').value = '';
        
        const oldLabel = qrContainer.querySelector('.rate-label');
        if (oldLabel) oldLabel.remove();
        
        proceedPayBtn.disabled = false;
        proceedPayBtn.innerHTML = 'Proceed to Pay';
        
        if(checkStatusInterval) clearInterval(checkStatusInterval);
    }

    if(addFundsBtn) {
        addFundsBtn.addEventListener('click', () => {
            resetModal();
            fundsModal.classList.add('active');
        });
    }

    if(closeFundsModalBtn) {
        closeFundsModalBtn.addEventListener('click', () => {
            fundsModal.classList.remove('active');
            if(checkStatusInterval) clearInterval(checkStatusInterval);
        });
    }

    if(proceedPayBtn) {
        proceedPayBtn.addEventListener('click', async () => {
            const usdAmount = parseFloat(document.getElementById('fund_amount').value);
            const gateway = document.getElementById('payment_gateway').value;

            if (!usdAmount || usdAmount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            if (gateway === 'paytm') {
                proceedPayBtn.disabled = true;
                proceedPayBtn.innerHTML = '<span class="spinner"></span> Converting USD to INR...';

                try {
                    const response = await fetch('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json');
                    const data = await response.json();
                    
                    const conversionRate = data.usd.inr;
                    const inrAmount = (usdAmount * conversionRate).toFixed(2);

                    fundsFormContainer.style.display = 'none';
                    qrContainer.classList.add('active');

                    const rateLabel = document.createElement('div');
                    rateLabel.className = 'rate-label';
                    rateLabel.innerHTML = `<div style="background: rgba(34,211,238,0.1); border: 1px solid rgba(34,211,238,0.2); color: var(--accent-green); padding: 12px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-family: var(--font-mono); font-size: 13px;">$${usdAmount.toFixed(2)} USD = ₹${inrAmount} INR <span style="display:block; font-size: 10px; opacity: 0.8; margin-top: 4px;">Live Rate: 1 USD = ₹${conversionRate.toFixed(2)}</span></div>`;
                    qrContainer.insertBefore(rateLabel, qrContainer.firstChild);

                    const orderId = "PTM_WALLET_" + paytmConfig.userId + "_" + Math.floor(Date.now() / 1000);
                    
                    const upiUrl = `upi://pay?pa=${encodeURIComponent(paytmConfig.upiId)}&pn=Vormox&am=${inrAmount}&cu=INR&tn=Add Funds&tr=${orderId}`;
                    
                    new QRCode(document.getElementById("qrCode"), {
                        text: upiUrl,
                        width: 200,
                        height: 200,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });

                    // Pass USD amount to polling so the wallet + invoice get credited exactly in Dollars
                    startPaymentPolling(orderId, usdAmount);

                } catch (error) {
                    alert('Failed to fetch the live exchange rate. Please try again.');
                    proceedPayBtn.disabled = false;
                    proceedPayBtn.innerHTML = 'Proceed to Pay';
                }
            } else {
                alert('This gateway integration is pending.');
            }
        });
    }

    function startPaymentPolling(orderId, usdAmount) {
        paymentStatus.className = 'payment-status status-pending';
        paymentStatus.innerHTML = '<span class="spinner"></span> Waiting for payment...';

        checkStatusInterval = setInterval(async () => {
            try {
                const apiUrl = paytmConfig.environment === "production" 
                    ? "https://securegw.paytm.in/order/status" 
                    : "https://securegw-stage.paytm.in/order/status";
                
                const response = await fetch(apiUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        "MID": paytmConfig.merchantId,
                        "ORDERID": orderId
                    })
                });
                
                const data = await response.json();
                
                if (data.STATUS === "TXN_SUCCESS") {
                    clearInterval(checkStatusInterval);
                    paymentStatus.className = 'payment-status status-success';
                    paymentStatus.innerHTML = '<i class="fa-solid fa-check"></i> Payment Successful!';
                    
                    const formData = new URLSearchParams();
                    formData.append('action', 'add_funds_success');
                    formData.append('amount', usdAmount);
                    formData.append('order_id', orderId);

                    await fetch('billing.php', {
                        method: 'POST',
                        body: formData
                    });

                    setTimeout(() => {
                        // Redirect to invoices so the user can immediately see their new "Paid" invoice receipt
                        window.location.href = 'invoices.php';
                    }, 1500);
                } else if (data.STATUS === "TXN_FAILURE" && !data.RESPMSG.includes("Invalid Order Id")) {
                    clearInterval(checkStatusInterval);
                    paymentStatus.className = 'payment-status status-error';
                    paymentStatus.innerHTML = '<i class="fa-solid fa-xmark"></i> Payment Failed';
                }
            } catch (error) {
                // Keep polling silently
            }
        }, 3000);
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'add_funds') {
        setTimeout(() => {
            if(fundsModal && addFundsBtn) {
                resetModal();
                fundsModal.classList.add('active');
                window.history.replaceState({}, document.title, "billing.php");
            }
        }, 100);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
