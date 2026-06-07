<?php
session_start();
require_once 'config.php';
require_once 'includes/PaytmChecksum.php';

try {
    $pgStmt = $pdo->prepare("SELECT paytm_upi_id FROM payment_gateways WHERE type = 'paytm' LIMIT 1");
    $pgStmt->execute();
    $gateway = $pgStmt->fetch();
    
    if (!$gateway || empty($gateway['paytm_upi_id'])) {
        die("System Configuration Error: Missing Gateway Keys.");
    }
    $merchant_key = $gateway['paytm_upi_id']; 
} catch (PDOException $e) {
    die("Database Connection Failed.");
}

$paytmChecksum = "";
$paramList = array();

foreach($_POST as $key => $value) {
    if($key == "CHECKSUMHASH") {
        $paytmChecksum = $value;
    } else {
        $paramList[$key] = $value;
    }
}

$isValidChecksum = PaytmChecksum::verifySignature($paramList, $merchant_key, $paytmChecksum);

if($isValidChecksum == "TRUE") {
    
    // Extract internal Invoice ID from Order ID (e.g., PTM59_170000 -> 59)
    $order_id_parts = explode('_', $_POST['ORDERID']);
    $internal_invoice_id = str_replace('PTM', '', $order_id_parts[0]);

    $gateway_logs = json_encode($_POST, JSON_PRETTY_PRINT);

    if ($_POST["STATUS"] == "TXN_SUCCESS") {
        
        try {
            $pdo->beginTransaction();

            // Fetch the invoice
            $stmt = $pdo->prepare("
                SELECT i.id, i.invoice_number, i.amount, i.user_id, i.status, i.panel_id, 
                       p.billing_cycle, p.expiry_date 
                FROM invoices i 
                LEFT JOIN user_panels p ON i.panel_id = p.id 
                WHERE i.id = ? FOR UPDATE
            ");
            $stmt->execute([$internal_invoice_id]);
            $invData = $stmt->fetch();

            if ($invData && $invData['status'] !== 'Paid') {
                
                // ROUTE A: WALLET TOP-UP INVOICE
                if (strpos($invData['invoice_number'], 'WAL-') === 0) {
                    $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
                        ->execute([$invData['amount'], $invData['user_id']]);
                }
                
                // ROUTE B: NORMAL INFRASTRUCTURE INVOICE
                elseif (!empty($invData['panel_id'])) {
                    $cycle = $invData['billing_cycle'] ?? 'monthly';
                    $current_expiry = $invData['expiry_date'];
                    
                    $base_time = (empty($current_expiry) || strtotime($current_expiry) < time()) ? time() : strtotime($current_expiry);
                    
                    $months_to_add = 1;
                    if ($cycle === 'quarterly') $months_to_add = 3;
                    if ($cycle === 'semi_annually') $months_to_add = 6;
                    if ($cycle === 'yearly' || $cycle === 'annually') $months_to_add = 12;
                    
                    $new_expiry = date('Y-m-d H:i:s', strtotime("+$months_to_add months", $base_time));
                    
                    $pdo->prepare("UPDATE user_panels SET expiry_date = ?, status = 'active' WHERE id = ?")
                        ->execute([$new_expiry, $invData['panel_id']]);
                }

                // MARK INVOICE AS PAID
                $pdo->prepare("UPDATE invoices SET status = 'Paid', gateway_logs = ? WHERE id = ?")
                    ->execute([$gateway_logs, $internal_invoice_id]);
                
                $pdo->commit();
                
                header("Location: view-invoice.php?id=" . urlencode($invData['invoice_number']) . "&payment=success");
                exit;
            } else {
                $pdo->rollBack();
                header("Location: invoices.php");
                exit;
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Database Error during payment processing.");
        }

    } else {
        // Failed Payment
        $pdo->prepare("UPDATE invoices SET gateway_logs = ? WHERE id = ?")->execute([$gateway_logs, $internal_invoice_id]);
        
        $invNumStmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
        $invNumStmt->execute([$internal_invoice_id]);
        $inv_num = $invNumStmt->fetchColumn();

        header("Location: view-invoice.php?id=" . urlencode($inv_num) . "&payment=failed");
        exit;
    }
} else {
    die("Security Checksum Mismatch! Suspicious transaction detected.");
}
?>
