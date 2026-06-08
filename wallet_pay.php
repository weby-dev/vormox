<?php
session_start();
require_once 'config.php';
require_once 'includes/notifications.php';
require_once 'includes/pricing.php';

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['invoice_number'])) {
    die("Invalid Request");
}

csrf_require();

$user_id = $_SESSION['user_id'];
$invoice_number = filter_input(INPUT_POST, 'invoice_number', FILTER_SANITIZE_SPECIAL_CHARS);

try {
    $pdo->beginTransaction();

    // 1. Fetch Invoice and User Wallet Balance simultaneously
    $stmt = $pdo->prepare("
        SELECT i.id, i.amount, i.status, i.panel_id, u.wallet_balance,
               u.email AS user_email, u.first_name AS user_first_name,
               p.billing_cycle, p.expiry_date, p.pending_nodes_count, p.domain AS panel_domain
        FROM invoices i
        JOIN users u ON i.user_id = u.id
        LEFT JOIN user_panels p ON i.panel_id = p.id
        WHERE i.invoice_number = ? AND i.user_id = ? AND i.status = 'Unpaid' FOR UPDATE
    ");
    $stmt->execute([$invoice_number, $user_id]);
    $data = $stmt->fetch();

    if (!$data) {
        throw new Exception("Invoice not found or already paid.");
    }

    if ($data['wallet_balance'] < $data['amount']) {
        throw new Exception("Insufficient wallet balance.");
    }

    // 2. Deduct from Wallet
    $new_balance = $data['wallet_balance'] - $data['amount'];
    $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);

    $is_upgrade = (strpos($invoice_number, 'UPG-') === 0);

    // Remember whether this panel was already past its expiry — drives
    // the "service restored" notification after commit.
    $was_expired_renewal = (
        !$is_upgrade
        && !empty($data['panel_id'])
        && !empty($data['expiry_date'])
        && strtotime($data['expiry_date']) < time()
    );
    $new_expiry_for_email = null;

    // 3. Apply payment side-effects
    if ($is_upgrade && !empty($data['panel_id']) && !empty($data['pending_nodes_count'])) {
        // UPGRADE: bump nodes_count, refresh total_price for the cycle. Don't extend expiry.
        vormox_apply_panel_upgrade(
            $pdo,
            $data['panel_id'],
            $data['pending_nodes_count'],
            $data['billing_cycle']
        );
    } elseif (!empty($data['panel_id'])) {
        // RENEW: extend expiry one cycle
        $new_expiry_for_email = vormox_apply_panel_renewal(
            $pdo,
            $data['panel_id'],
            $data['billing_cycle'] ?? 'monthly',
            $data['expiry_date']
        );
    }

    // 4. Mark Invoice as Paid
    $log_msg = "Paid via Wallet. Deducted $" . $data['amount'];
    $pdo->prepare("UPDATE invoices SET status = 'Paid', gateway_logs = ? WHERE invoice_number = ?")->execute([$log_msg, $invoice_number]);

    $pdo->commit();

    // Payment receipt for every successful wallet payment.
    notify_invoice_paid(
        $data['user_email'],
        $data['user_first_name'],
        $invoice_number,
        $data['amount'],
        "Paid from wallet balance."
    );

    // Fire "service restored" mail only after the DB is durably committed.
    if ($was_expired_renewal && $new_expiry_for_email) {
        notify_panel_renewed_after_expiry(
            $data['user_email'],
            $data['user_first_name'],
            $data['panel_domain'],
            $new_expiry_for_email
        );
    }

    header("Location: view-invoice.php?id=" . urlencode($invoice_number));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Payment failed: " . $e->getMessage());
}
