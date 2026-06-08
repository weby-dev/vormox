<?php
session_start();
require_once 'config.php';
require_once 'includes/notifications.php';

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['amount'])) {
    die("Invalid request");
}

csrf_require();

$user_id = $_SESSION['user_id'];
$amount = (float) filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

if ($amount < 1) {
    die("Amount must be at least $1.00");
}

try {
    $invoice_number = 'WAL-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $due_date = date('Y-m-d', strtotime('+1 day')); // Wallet invoices expire quickly

    // We pass NULL for panel_id, period_start, period_end since it's just a wallet top-up
    $invStmt = $pdo->prepare("
        INSERT INTO invoices (user_id, panel_id, invoice_number, amount, type, status, due_date, created_at)
        VALUES (:uid, NULL, :inv_num, :amount, 'topup', 'Unpaid', :due, NOW())
    ");
    
    $invStmt->execute([
        'uid' => $user_id,
        'inv_num' => $invoice_number,
        'amount' => $amount,
        'due' => $due_date
    ]);

    // Notify user that their wallet top-up invoice is ready to pay.
    try {
        $u = $pdo->prepare("SELECT email, first_name FROM users WHERE id = ? LIMIT 1");
        $u->execute([$user_id]);
        if ($row = $u->fetch()) {
            notify_invoice_created(
                $row['email'],
                $row['first_name'],
                $invoice_number,
                $amount,
                $due_date,
                "Wallet top-up invoice — pay any UPI app to credit your Vormox wallet."
            );
        }
    } catch (PDOException $e) { error_log("Wallet email lookup failed: " . $e->getMessage()); }

    // Send the user directly to the new invoice they just created
    header("Location: view-invoice.php?id=" . urlencode($invoice_number));
    exit;

} catch (PDOException $e) {
    die("Database error while generating wallet top-up invoice.");
}
