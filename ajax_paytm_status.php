<?php
session_start();
require_once 'config.php';
require_once 'includes/PaytmChecksum.php'; 

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_POST['order_id']) || empty($_POST['invoice_number'])) {
    echo json_encode(['status' => 'pending']); exit;
}

$order_id = filter_input(INPUT_POST, 'order_id', FILTER_SANITIZE_SPECIAL_CHARS);
$invoice_number = filter_input(INPUT_POST, 'invoice_number', FILTER_SANITIZE_SPECIAL_CHARS);

try {
    // Check if it's already marked as paid locally to save API calls
    $checkStmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_number = ? LIMIT 1");
    $checkStmt->execute([$invoice_number]);
    if ($checkStmt->fetchColumn() === 'Paid') {
        echo json_encode(['status' => 'success']); exit;
    }

    // Fetch live API credentials
    $gwStmt = $pdo->prepare("SELECT paytm_merchant_id, paytm_upi_id, environment FROM payment_gateways WHERE type = 'paytm' AND status = 'active' LIMIT 1");
    $gwStmt->execute();
    $gateway = $gwStmt->fetch();

    if (!$gateway) { echo json_encode(['status' => 'pending']); exit; }

    $merchant_id = $gateway['paytm_merchant_id'];
    $merchant_key = $gateway['paytm_upi_id']; // Key stored in UPI column
    
    $apiUrl = (strtolower($gateway['environment']) === 'production') 
        ? "https://securegw.paytm.in/v3/order/status" 
        : "https://securegw-stage.paytm.in/v3/order/status";

    // Build the Checksum for Paytm API
    $paytmParams = array();
    $paytmParams["body"] = array("mid" => $merchant_id, "orderId" => $order_id);
    $checksum = PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $merchant_key);
    $paytmParams["head"] = array("signature" => $checksum);

    // Call Paytm API
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paytmParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));  
    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response, true);

    if (isset($resData['body']['resultInfo']['resultStatus']) && $resData['body']['resultInfo']['resultStatus'] === 'TXN_SUCCESS') {
        
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, amount, panel_id, billing_cycle, expiry_date FROM invoices JOIN user_panels ON invoices.panel_id = user_panels.id WHERE invoice_number = ? FOR UPDATE");
        $stmt->execute([$invoice_number]);
        $invData = $stmt->fetch();

        // Auto-Extend Panel Lifespan
        if ($invData) {
            $cycle = $invData['billing_cycle'] ?? 'monthly';
            $current_expiry = $invData['expiry_date'];
            
            $base_time = (empty($current_expiry) || strtotime($current_expiry) < time()) ? time() : strtotime($current_expiry);
            
            $months_to_add = 1;
            if ($cycle === 'quarterly') $months_to_add = 3;
            if ($cycle === 'semi_annually') $months_to_add = 6;
            if ($cycle === 'annually') $months_to_add = 12;
            
            $new_expiry = date('Y-m-d H:i:s', strtotime("+$months_to_add months", $base_time));
            
            $pdo->prepare("UPDATE user_panels SET expiry_date = ?, status = 'active' WHERE id = ?")->execute([$new_expiry, $invData['panel_id']]);
        }

        $pdo->prepare("UPDATE invoices SET status = 'Paid', gateway_logs = ? WHERE invoice_number = ?")->execute([$response, $invoice_number]);
        
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'pending']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['status' => 'pending']);
}
