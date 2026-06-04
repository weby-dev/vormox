<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$invoice_number = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$invoice_number) {
    header("Location: invoices.php");
    exit;
}

try {
    // Get User basic info
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    // Get Invoice details
    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = :inv_num AND user_id = :uid LIMIT 1");
    $invStmt->execute(['inv_num' => $invoice_number, 'uid' => $_SESSION['user_id']]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        die("Invoice not found or access denied.");
    }

    // Get User Billing details
    $billingStmt = $pdo->prepare("SELECT * FROM user_billing_profiles WHERE user_id = :id LIMIT 1");
    $billingStmt->execute(['id' => $_SESSION['user_id']]);
    $billing = $billingStmt->fetch();

    // Determine what the item is based on the invoice prefix
    $is_funding = strpos($invoice['invoice_number'], 'INV-FND') !== false;
    $item_description = $is_funding ? 'Wallet Funds Deposit' : 'Proxmox Cloud Infrastructure Provisioning';

} catch (PDOException $e) {
    die("A system error occurred.");
}

$page_title = 'Invoice ' . $invoice['invoice_number'];
$header_title = 'Billing & Payments';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); letter-spacing: -.02em; margin-bottom: 8px; }
    .page-sub { font-size: 15px; color: var(--text-muted); }
    
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 24px; transition: color 0.2s; }
    .back-link:hover { color: var(--text); }

    .invoice-wrapper { max-width: 900px; margin: 0 auto; }
    
    .invoice-actions { display: flex; justify-content: flex-end; gap: 16px; margin-bottom: 24px; }
    
    .invoice-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 48px; box-shadow: 0 12px 40px rgba(0,0,0,0.15); transition: background 0.3s, border-color 0.3s; }
    [data-theme="light"] .invoice-card { box-shadow: 0 12px 40px rgba(0,0,0,0.05); }

    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 48px; padding-bottom: 32px; border-bottom: 1px solid var(--border-strong); }
    
    .inv-brand { display: flex; flex-direction: column; gap: 12px; }
    .inv-logo { display: flex; align-items: center; gap: 10px; font-family: var(--font-head); font-size: 24px; font-weight: 800; color: var(--text); letter-spacing: -.5px; }
    .inv-logo-icon { width: 36px; height: 36px; background: linear-gradient(135deg,var(--accent),var(--accent-green)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    .inv-logo span { color: var(--accent2); }
    .inv-company-details { font-size: 13px; color: var(--text-muted); line-height: 1.6; }

    .inv-meta { text-align: right; }
    .inv-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: 0.05em; margin-bottom: 16px; text-transform: uppercase; opacity: 0.9; }
    
    .badge { padding: 6px 14px; border-radius: 100px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; }
    .badge-paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-cancelled { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .inv-addresses { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-bottom: 48px; }
    .addr-block h3 { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 12px; }
    .addr-name { font-weight: 700; font-size: 16px; color: var(--text); margin-bottom: 4px; }
    .addr-text { font-size: 14px; color: var(--text-muted); line-height: 1.6; }

    .inv-details-bar { display: flex; gap: 48px; padding: 20px 24px; background: var(--bg2); border-radius: 12px; border: 1px solid var(--border); margin-bottom: 48px; }
    .detail-item { display: flex; flex-direction: column; gap: 4px; }
    .detail-label { font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; }
    .detail-val { font-size: 14px; font-weight: 600; color: var(--text); }

    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 48px; }
    .inv-table th { padding: 12px 16px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 2px solid var(--border-strong); text-align: left; }
    .inv-table th.right { text-align: right; }
    .inv-table td { padding: 20px 16px; border-bottom: 1px solid var(--border); font-size: 15px; color: var(--text); vertical-align: middle; }
    .inv-table td.right { text-align: right; font-family: var(--font-mono); font-weight: 500; }
    
    .inv-summary { display: flex; justify-content: flex-end; }
    .summary-box { width: 100%; max-width: 320px; }
    .summary-row { display: flex; justify-content: space-between; padding: 12px 16px; font-size: 14px; color: var(--text-muted); }
    .summary-row.total { border-top: 2px solid var(--border-strong); margin-top: 8px; padding-top: 16px; font-size: 20px; font-weight: 700; color: var(--text); font-family: var(--font-head); }

    .inv-footer { margin-top: 64px; text-align: center; font-size: 13px; color: var(--text-dim); border-top: 1px solid var(--border); padding-top: 32px; }

    /* Print Styles to ensure perfect PDF generation */
    @media print {
        body { background: #fff !important; color: #000 !important; }
        nav, aside, header, .invoice-actions, .back-link { display: none !important; }
        .content-area { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
        .invoice-card { box-shadow: none !important; border: none !important; padding: 0 !important; }
        .inv-details-bar { background: #f8fafc !important; border-color: #e2e8f0 !important; }
        .inv-table th { border-bottom-color: #cbd5e1 !important; color: #64748b !important; }
        .inv-table td { border-bottom-color: #e2e8f0 !important; color: #0f172a !important; }
        .badge-paid { background: #dcfce7 !important; color: #0f766e !important; border-color: #99f6e4 !important; }
        .badge-unpaid { background: #ffedd5 !important; color: #c2410c !important; border-color: #fed7aa !important; }
        .detail-label, .addr-block h3, .inv-company-details, .addr-text, .summary-row { color: #475569 !important; }
        .inv-title, .addr-name, .detail-val, .summary-row.total { color: #0f172a !important; }
        .inv-footer { color: #94a3b8 !important; border-top-color: #e2e8f0 !important; }
    }
</style>

<div class="content-area">
    <a href="invoices.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>

    <div class="invoice-wrapper">
        <div class="invoice-actions">
            <button onclick="window.print()" class="btn-ghost"><i class="fa-solid fa-print" style="margin-right: 8px;"></i> Print / PDF</button>
            
            <?php if ($invoice['status'] === 'Unpaid'): ?>
                <form method="POST" action="invoices.php" style="margin: 0;">
                    <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id']) ?>">
                    <button type="submit" name="pay_invoice" class="btn-primary"><i class="fa-solid fa-wallet" style="margin-right: 8px;"></i> Pay Now</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="invoice-card" id="invoiceArea">
            <div class="inv-header">
                <div class="inv-brand">
                    <div class="inv-logo">
                        <div class="inv-logo-icon"><i class="fa-solid fa-bolt"></i></div>
                        Vorm<span>ox</span>
                    </div>
                    <div class="inv-company-details">
                        Vormox Automation Cloud<br>
                        123 Control Plane Ave, Suite 400<br>
                        San Francisco, CA 94105<br>
                        billing@vormox.com
                    </div>
                </div>
                <div class="inv-meta">
                    <div class="inv-title">Invoice</div>
                    <span class="badge badge-<?= strtolower($invoice['status']) ?>">
                        <?= htmlspecialchars($invoice['status']) ?>
                    </span>
                </div>
            </div>

            <div class="inv-addresses">
                <div class="addr-block">
                    <h3>Billed To</h3>
                    <?php if ($billing && $billing['billing_type'] === 'company' && !empty($billing['company_name'])): ?>
                        <div class="addr-name"><?= htmlspecialchars($billing['company_name']) ?></div>
                        <div class="addr-text">ATTN: <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                    <?php else: ?>
                        <div class="addr-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                    <?php endif; ?>
                    
                    <div class="addr-text">
                        <?php if ($billing && !empty($billing['address_line1'])): ?>
                            <?= htmlspecialchars($billing['address_line1']) ?><br>
                            <?php if(!empty($billing['address_line2'])) echo htmlspecialchars($billing['address_line2']) . '<br>'; ?>
                            <?= htmlspecialchars($billing['city'] . ', ' . $billing['state_province'] . ' ' . $billing['postal_code']) ?><br>
                            <?= htmlspecialchars($billing['country']) ?><br>
                        <?php endif; ?>
                        <?= htmlspecialchars($billing['billing_email'] ?? $user['email']) ?>
                    </div>
                </div>
            </div>

            <div class="inv-details-bar">
                <div class="detail-item">
                    <span class="detail-label">Invoice Number</span>
                    <span class="detail-val"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Issue Date</span>
                    <span class="detail-val"><?= date('M j, Y', strtotime($invoice['created_at'])) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Due Date</span>
                    <span class="detail-val"><?= date('M j, Y', strtotime($invoice['due_date'])) ?></span>
                </div>
            </div>

            <table class="inv-table">
                <thead>
                    <tr>
                        <th width="70%">Description</th>
                        <th width="30%" class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <div style="font-weight: 600; margin-bottom: 4px;"><?= $item_description ?></div>
                            <div style="font-size: 13px; color: var(--text-muted);">
                                <?= $is_funding ? 'Account balance top-up' : 'Automated infrastructure deployment' ?>
                            </div>
                        </td>
                        <td class="right">$<?= number_format($invoice['amount'], 2) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="inv-summary">
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span>$<?= number_format($invoice['amount'], 2) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Tax (0%)</span>
                        <span>$0.00</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span>$<?= number_format($invoice['amount'], 2) ?></span>
                    </div>
                </div>
            </div>

            <div class="inv-footer">
                <p>If you have any questions regarding this invoice, please contact support@vormox.com</p>
                <p style="margin-top: 8px;">Thank you for trusting Vormox.</p>
            </div>

        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
