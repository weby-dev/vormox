<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';

$user_id = $_SESSION['user_id'];
$invoice_number = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$invoice_number) { header("Location: invoices.php"); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               u.first_name, u.last_name, u.email, u.theme, u.wallet_balance,
               p.domain, p.nodes_count, p.billing_cycle
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        LEFT JOIN user_panels p ON i.panel_id = p.id 
        WHERE i.invoice_number = :inv AND i.user_id = :uid LIMIT 1
    ");
    $stmt->execute(['inv' => $invoice_number, 'uid' => $user_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) { die("Invoice not found or access denied."); }
    
    $user = ['first_name' => $invoice['first_name'], 'last_name' => $invoice['last_name'], 'email' => $invoice['email'], 'theme' => $invoice['theme']];
    
    $gwStmt = $pdo->prepare("SELECT paytm_upi_id, paytm_merchant_id, environment FROM payment_gateways WHERE type = 'paytm' AND status = 'active' LIMIT 1");
    $gwStmt->execute();
    $gateway = $gwStmt->fetch();

} catch (PDOException $e) {
    die("Database error while loading invoice.");
}

// -------------------------------------------------------------
// LIVE USD TO INR CONVERSION (OPEN SOURCE API)
// -------------------------------------------------------------
$usd_amount = (float) $invoice['amount'];
$exchange_rate = 83.50; // Fallback rate

try {
    $ch = curl_init('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 sec timeout so page doesn't hang if API is down
    $api_res = curl_exec($ch);
    curl_close($ch);
    
    if ($api_res) {
        $data = json_decode($api_res, true);
        if (isset($data['usd']['inr'])) {
            $exchange_rate = (float) $data['usd']['inr'];
        }
    }
} catch (Exception $e) { /* Ignore and use fallback */ }

$inr_amount = number_format($usd_amount * $exchange_rate, 2, '.', '');
$paytm_order_id = "PTM" . $invoice['id'] . "_" . substr(md5(time()), 0, 8);

$is_wallet_topup = (strpos($invoice['invoice_number'], 'WAL-') === 0);

$page_title = 'Invoice ' . $invoice['invoice_number'];
$header_title = 'Billing Details';

include 'includes/header.php';
?>

<style>
    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1000px; margin: 0 auto; width: 100%; }
    .top-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }
    
    .btn-action { padding: 12px 24px; border-radius: 8px; font-family: var(--font-body); font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; border: none; }
    .btn-print { background: transparent; border: 1px solid var(--border-strong); color: var(--text); }
    .btn-print:hover { background: var(--surface2); }
    
    .btn-wallet { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-wallet:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-wallet:disabled { background: var(--surface2); color: var(--text-muted); box-shadow: none; cursor: not-allowed; transform: none; }
    
    .btn-upi { background: #00b9f5; color: #fff; box-shadow: 0 4px 15px rgba(0, 185, 245, 0.3); }
    .btn-upi:hover { filter: brightness(1.1); transform: translateY(-1px); }

    .invoice-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .invoice-header { padding: 40px; display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border); }
    .invoice-header-left h2 { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
    .invoice-header-left p { color: var(--text-muted); font-family: var(--font-mono); font-size: 13px; }
    
    .badge { padding: 6px 14px; border-radius: 100px; font-size: 12px; font-weight: 700; text-transform: uppercase; font-family: var(--font-mono); display: inline-flex; align-items: center; gap: 6px; }
    .badge-Paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-Unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    
    .invoice-meta { display: grid; grid-template-columns: 1fr 1fr; padding: 40px; gap: 32px; background: rgba(0,0,0,0.1); border-bottom: 1px solid var(--border); }
    .meta-box h4 { font-size: 12px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 12px; font-family: var(--font-mono); }
    .meta-box p { font-size: 15px; color: var(--text); line-height: 1.6; font-weight: 500; }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 40px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 24px 40px; border-bottom: 1px solid var(--border); font-size: 15px; vertical-align: top; color: var(--text); }
    tr:last-child td { border-bottom: none; }

    .total-section { padding: 32px 40px; background: var(--bg2); display: flex; justify-content: flex-end; align-items: center; border-top: 1px solid var(--border-strong); }

    /* Modals */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-content { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 400px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); text-align: center; }
    .qr-box { background: #fff; padding: 16px; border-radius: 12px; display: inline-block; margin: 24px 0; border: 1px solid var(--border); }
    
    .input-amount { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-head); font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 16px; outline: none; transition: 0.2s; }
    .input-amount:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
</style>

<div class="content-area">
    
    <div style="background: var(--surface2); border: 1px solid var(--border); border-radius: 12px; padding: 16px 24px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-family: var(--font-mono); font-weight: 600;">Available Wallet Balance</div>
            <div style="font-size: 24px; font-weight: 800; color: var(--text); font-family: var(--font-head);">$<?= number_format($invoice['wallet_balance'], 2) ?> USD</div>
        </div>
        <button class="btn-print btn-action" onclick="openAddFundsModal()"><i class="fa-solid fa-plus"></i> Add Funds</button>
    </div>

    <div class="top-actions">
        <a href="invoices.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
        
        <div style="display: flex; gap: 16px; align-items: center;">
            <?php if($invoice['status'] === 'Unpaid'): ?>
                
                <?php if(!$is_wallet_topup): ?>
                <form method="POST" action="wallet_pay.php" style="margin: 0;">
                    <input type="hidden" name="invoice_number" value="<?= htmlspecialchars($invoice['invoice_number']) ?>">
                    <?php if ($invoice['wallet_balance'] >= $usd_amount): ?>
                        <button type="submit" class="btn-action btn-wallet"><i class="fa-solid fa-wallet"></i> Pay via Wallet</button>
                    <?php else: ?>
                        <button type="button" class="btn-action btn-wallet" disabled title="Insufficient Funds"><i class="fa-solid fa-wallet"></i> Insufficient Balance</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <?php if($gateway && $gateway['paytm_upi_id']): ?>
                    <button class="btn-action btn-upi" onclick="openUpiModal()"><i class="fa-solid fa-qrcode"></i> Pay via UPI (₹<?= $inr_amount ?>)</button>
                    <form method="POST" action="paytm_process.php" style="margin: 0;">
                        <input type="hidden" name="invoice_number" value="<?= htmlspecialchars($invoice['invoice_number']) ?>">
                        <button type="submit" class="btn-print btn-action" style="background: var(--bg2); border-color: transparent;"><i class="fa-solid fa-credit-card"></i> Card/NetBanking</button>
                    </form>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

    <div class="invoice-card">
        <div class="invoice-header">
            <div class="invoice-header-left">
                <h2><?= htmlspecialchars($invoice['invoice_number']) ?></h2>
                <p>Issued: <?= date('F j, Y', strtotime($invoice['created_at'])) ?></p>
            </div>
            <div>
                <span class="badge badge-<?= $invoice['status'] ?>">
                    <?php if($invoice['status'] == 'Paid') echo '<i class="fa-solid fa-check"></i>';
                          else echo '<i class="fa-solid fa-clock"></i>';
                    ?>
                    <?= htmlspecialchars($invoice['status']) ?>
                </span>
            </div>
        </div>

        <div class="invoice-meta">
            <div class="meta-box">
                <h4>Billed To</h4>
                <p>
                    <strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong><br>
                    <?= htmlspecialchars($invoice['email']) ?><br>
                </p>
            </div>
            <div class="meta-box">
                <h4>Invoice Details</h4>
                <p style="display: grid; grid-template-columns: 100px 1fr; gap: 8px;">
                    <span style="color: var(--text-muted);">Due Date:</span> <strong><?= date('M j, Y', strtotime($invoice['due_date'])) ?></strong>
                    <?php if(!$is_wallet_topup): ?>
                    <span style="color: var(--text-muted);">Period:</span> <span><?= $invoice['period_start'] ? date('M j, Y', strtotime($invoice['period_start'])) . ' - ' . date('M j, Y', strtotime($invoice['period_end'])) : "N/A" ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="70%">Description</th>
                    <th width="30%" style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php if($is_wallet_topup): ?>
                            <div style="font-weight: 600; font-size: 16px;">Account Wallet Top-Up</div>
                            <div style="color: var(--text-muted); font-size: 14px;">Funds will be instantly credited to your available balance.</div>
                        <?php else: ?>
                            <div style="font-weight: 600; font-size: 16px;">Vormox Infrastructure Allocation</div>
                            <?php if($invoice['domain']): ?>
                                <div style="color: var(--text-muted); font-size: 14px;">Domain: <?= htmlspecialchars($invoice['domain']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;">$<?= number_format($invoice['amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="total-section">
            <span style="font-size: 14px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-right: 24px;">Total Due</span>
            <span style="font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text);">$<?= number_format($invoice['amount'], 2) ?> <span style="font-size: 16px; color: var(--text-muted);">USD</span></span>
        </div>
    </div>
</div>

<div class="modal-overlay" id="addFundsModal">
    <div class="modal-content">
        <h3 style="font-family: var(--font-head); margin-bottom: 8px;">Top Up Wallet</h3>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">Enter the amount of USD you want to add.</p>
        
        <form method="POST" action="add_funds.php">
            <input type="number" name="amount" class="input-amount" min="1" max="1000" step="1" placeholder="$10.00" required>
            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="button" class="btn-print btn-action" onclick="closeAddFundsModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-action btn-wallet" style="flex: 1; border: none; cursor: pointer;">Generate Invoice</button>
            </div>
        </form>
    </div>
</div>

<?php if($invoice['status'] === 'Unpaid' && $gateway): ?>
<div class="modal-overlay" id="upiModal">
    <div class="modal-content">
        <h3 style="font-family: var(--font-head); margin-bottom: 8px;">Scan to Pay</h3>
        <p style="color: var(--text-muted); font-size: 14px;">Live Rate: 1 USD = ₹<?= number_format($exchange_rate, 2) ?></p>
        
        <div class="qr-box" id="qrcode"></div>
        
        <div style="font-family: var(--font-mono); font-size: 24px; font-weight: 800; color: #00b9f5; margin-bottom: 8px;">₹<?= $inr_amount ?></div>
        <div style="font-size: 12px; color: var(--text-dim); margin-bottom: 24px;">Order ID: <?= $paytm_order_id ?></div>
        
        <div id="paymentStatus" style="padding: 12px; border-radius: 8px; background: var(--bg2); color: var(--text-muted); font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Awaiting Payment...
        </div>

        <button class="btn-print" onclick="closeUpiModal()" style="width: 100%; margin-top: 16px; padding: 12px; border-radius: 8px; border: none; background: var(--surface2); color: var(--text); cursor: pointer;">Cancel</button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    let statusInterval;

    function openAddFundsModal() { document.getElementById('addFundsModal').classList.add('show'); }
    function closeAddFundsModal() { document.getElementById('addFundsModal').classList.remove('show'); }

    function openUpiModal() {
        document.getElementById('upiModal').classList.add('show');
        
        const upiUrl = `upi://pay?pa=<?= urlencode($gateway['paytm_upi_id']) ?>&pn=Vormox&am=<?= $inr_amount ?>&cu=INR&tn=Invoice_<?= $invoice['invoice_number'] ?>&tr=<?= $paytm_order_id ?>`;
        
        document.getElementById("qrcode").innerHTML = "";
        new QRCode(document.getElementById("qrcode"), {
            text: upiUrl, width: 200, height: 200,
            colorDark: "#000000", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H
        });

        statusInterval = setInterval(checkPaymentStatus, 3000);
    }

    function closeUpiModal() {
        document.getElementById('upiModal').classList.remove('show');
        clearInterval(statusInterval);
    }

    async function checkPaymentStatus() {
        try {
            const formData = new URLSearchParams();
            formData.append('order_id', '<?= $paytm_order_id ?>');
            formData.append('invoice_number', '<?= $invoice['invoice_number'] ?>');

            const res = await fetch('ajax_paytm_status.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.status === 'success') {
                clearInterval(statusInterval);
                const statusBox = document.getElementById('paymentStatus');
                statusBox.style.background = 'rgba(34,211,238,0.1)';
                statusBox.style.color = 'var(--accent-green)';
                statusBox.innerHTML = '<i class="fa-solid fa-circle-check"></i> Payment Received!';
                setTimeout(() => location.reload(), 2000);
            }
        } catch (err) { }
    }
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
