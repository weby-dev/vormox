<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['invoice_number'])) {
    die("Invalid Request");
}

$user_id = $_SESSION['user_id'];
$invoice_number = filter_input(INPUT_POST, 'invoice_number', FILTER_SANITIZE_SPECIAL_CHARS);

try {
    $pdo->beginTransaction();

    // 1. Fetch Invoice and User Wallet Balance simultaneously
    $stmt = $pdo->prepare("
        SELECT i.id, i.amount, i.status, i.panel_id, u.wallet_balance, p.billing_cycle, p.expiry_date
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

    // 3. Auto-Extend Panel Lifespan
    if (!empty($data['panel_id'])) {
        $cycle = $data['billing_cycle'] ?? 'monthly';
        $current_expiry = $data['expiry_date'];
        
        $base_time = (empty($current_expiry) || strtotime($current_expiry) < time()) ? time() : strtotime($current_expiry);
        
        $months_to_add = 1;
        if ($cycle === 'quarterly') $months_to_add = 3;
        if ($cycle === 'semi_annually') $months_to_add = 6;
        if ($cycle === 'annually') $months_to_add = 12;
        
        $new_expiry = date('Y-m-d H:i:s', strtotime("+$months_to_add months", $base_time));
        
        $pdo->prepare("UPDATE user_panels SET expiry_date = ?, status = 'active' WHERE id = ?")
            ->execute([$new_expiry, $data['panel_id']]);
    }

    // 4. Mark Invoice as Paid
    $log_msg = "Paid via Wallet. Deducted $" . $data['amount'];
    $pdo->prepare("UPDATE invoices SET status = 'Paid', gateway_logs = ? WHERE invoice_number = ?")->execute([$log_msg, $invoice_number]);

    $pdo->commit();
    header("Location: view-invoice.php?id=" . urlencode($invoice_number));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Payment failed: " . $e->getMessage());
}
