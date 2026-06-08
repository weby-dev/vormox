<?php
session_start();
require_once 'config.php';
require_once 'includes/notifications.php';
require_once 'includes/pricing.php';

header('Content-Type: application/json');

// Only logged-in users may poll. invoice_number is the ONLY thing we accept
// from the client — order_id is server-side state, bound to the invoice when
// the QR was first issued in view-invoice.php.
if (!isset($_SESSION['user_id']) || empty($_POST['invoice_number'])) {
    echo json_encode(['status' => 'pending']); exit;
}

csrf_require();

$invoice_number = filter_input(INPUT_POST, 'invoice_number', FILTER_SANITIZE_SPECIAL_CHARS);
$user_id        = (int) $_SESSION['user_id'];

try {
    // ---------------------------------------------------------------------
    // Fetch the invoice + the SERVER-SIDE Paytm binding. We also constrain by
    // user_id so a user can't poll someone else's invoice.
    // ---------------------------------------------------------------------
    $lookup = $pdo->prepare("
        SELECT id, status, amount, user_id, panel_id,
               paytm_order_id, paytm_expected_amount, invoice_number
          FROM invoices
         WHERE invoice_number = ? AND user_id = ?
         LIMIT 1
    ");
    $lookup->execute([$invoice_number, $user_id]);
    $inv = $lookup->fetch();

    if (!$inv) { echo json_encode(['status' => 'pending']); exit; }

    // Short-circuit if it's already Paid (saves API calls when the page
    // polls after a previous successful run).
    if ($inv['status'] === 'Paid') {
        echo json_encode(['status' => 'success']); exit;
    }

    // No QR was ever issued for this invoice — nothing to verify against.
    if (empty($inv['paytm_order_id'])) {
        echo json_encode(['status' => 'pending']); exit;
    }

    $order_id        = $inv['paytm_order_id'];
    $expected_amount = (float) $inv['paytm_expected_amount']; // INR, stored at QR time

    // ---------------------------------------------------------------------
    // Fetch live API credentials
    // ---------------------------------------------------------------------
    $gwStmt = $pdo->prepare("
        SELECT paytm_merchant_id, environment
          FROM payment_gateways
         WHERE type = 'paytm' AND status = 'active'
         LIMIT 1
    ");
    $gwStmt->execute();
    $gateway = $gwStmt->fetch();
    if (!$gateway) { echo json_encode(['status' => 'pending']); exit; }

    $merchant_id = $gateway['paytm_merchant_id'];

    $apiUrl = (strtolower($gateway['environment']) === 'production')
        ? 'https://securegw.paytm.in/order/status'
        : 'https://securegw-stage.paytm.in/order/status';

    $payload = json_encode(['MID' => $merchant_id, 'ORDERID' => $order_id]);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response, true);

    // Not successful yet — keep polling.
    if (!isset($resData['STATUS']) || $resData['STATUS'] !== 'TXN_SUCCESS') {
        echo json_encode(['status' => 'pending']); exit;
    }

    // ---------------------------------------------------------------------
    // AMOUNT VERIFICATION — must equal what we asked the user to pay.
    // Without this, a paid ₹1 transaction would satisfy a ₹50,000 invoice.
    // ---------------------------------------------------------------------
    $txn_amount = isset($resData['TXNAMOUNT']) ? (float) $resData['TXNAMOUNT'] : -1.0;
    if ($expected_amount <= 0 || abs($txn_amount - $expected_amount) > 0.01) {
        error_log(sprintf(
            "Paytm amount mismatch for invoice %s: expected %.2f INR, got %.2f INR (order %s)",
            $invoice_number, $expected_amount, $txn_amount, $order_id
        ));
        echo json_encode(['status' => 'pending']); exit;
    }

    // Defensive: the order id Paytm echoed back must equal what we asked for.
    if (isset($resData['ORDERID']) && $resData['ORDERID'] !== $order_id) {
        error_log("Paytm ORDERID echo mismatch: sent {$order_id}, got {$resData['ORDERID']}");
        echo json_encode(['status' => 'pending']); exit;
    }

    // ---------------------------------------------------------------------
    // Mark invoice paid + apply side effects (top-up / upgrade / renewal).
    // ---------------------------------------------------------------------
    $pdo->beginTransaction();

    // Re-lock the row and re-read the panel context for routing.
    $stmt = $pdo->prepare("
        SELECT i.id, i.amount, i.user_id, i.panel_id, i.invoice_number, i.status,
               u.email AS user_email, u.first_name AS user_first_name,
               p.billing_cycle, p.expiry_date, p.pending_nodes_count, p.domain AS panel_domain
          FROM invoices i
          JOIN users u            ON u.id = i.user_id
          LEFT JOIN user_panels p ON p.id = i.panel_id
         WHERE i.invoice_number = ?
         FOR UPDATE
    ");
    $stmt->execute([$invoice_number]);
    $invData = $stmt->fetch();

    // Re-check: another tab could have flipped it between the lookup and lock.
    if (!$invData || $invData['status'] === 'Paid') {
        $pdo->commit();
        echo json_encode(['status' => 'success']); exit;
    }

    $was_expired_renewal  = false;
    $new_expiry_for_email = null;

    $is_wallet_topup = (strpos($invData['invoice_number'], 'WAL-') === 0);
    $is_upgrade      = (strpos($invData['invoice_number'], 'UPG-') === 0);

    $was_expired_renewal = (
        !$is_wallet_topup
        && !$is_upgrade
        && !empty($invData['panel_id'])
        && !empty($invData['expiry_date'])
        && strtotime($invData['expiry_date']) < time()
    );

    if ($is_wallet_topup) {
        // Wallet top-up — credit the user's balance
        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
            ->execute([$invData['amount'], $invData['user_id']]);
    } elseif ($is_upgrade && !empty($invData['panel_id']) && !empty($invData['pending_nodes_count'])) {
        // Plan upgrade — apply new node count
        vormox_apply_panel_upgrade(
            $pdo,
            $invData['panel_id'],
            $invData['pending_nodes_count'],
            $invData['billing_cycle']
        );
    } elseif (!empty($invData['panel_id'])) {
        // Panel renewal — extend expiry
        $new_expiry_for_email = vormox_apply_panel_renewal(
            $pdo,
            $invData['panel_id'],
            $invData['billing_cycle'] ?? 'monthly',
            $invData['expiry_date']
        );
    }

    $pdo->prepare("
        UPDATE invoices
           SET status = 'Paid', gateway_logs = ?
         WHERE invoice_number = ?
    ")->execute([$response, $invoice_number]);

    $pdo->commit();

    // ---------------------------------------------------------------------
    // After-commit notifications.
    // ---------------------------------------------------------------------
    notify_invoice_paid(
        $invData['user_email'],
        $invData['user_first_name'],
        $invData['invoice_number'],
        $invData['amount'],
        'Paid via UPI.'
    );

    if ($was_expired_renewal && $new_expiry_for_email) {
        notify_panel_renewed_after_expiry(
            $invData['user_email'],
            $invData['user_first_name'],
            $invData['panel_domain'],
            $new_expiry_for_email
        );
    }

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("ajax_paytm_status error: " . $e->getMessage());
    echo json_encode(['status' => 'pending']);
}
