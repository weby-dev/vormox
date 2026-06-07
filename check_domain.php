<?php
session_start();

// FIX: Changed from '../config.php' to 'config.php' since they are in the same directory
require_once 'config.php';

header('Content-Type: application/json');

// Only allow logged-in users to check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$domain = filter_input(INPUT_GET, 'domain', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$domain) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    // PRODUCTION READY CHECK: 
    // Only block the domain if it is attached to a panel that is currently alive, pending, or suspended.
    // Because you terminated 'cloud.siteworx.com', this will return false, making the domain available again!
    $stmt = $pdo->prepare("
        SELECT id FROM user_panels 
        WHERE domain = :domain 
        AND status IN ('payment_pending', 'pending', 'creating', 'active', 'restarting', 'suspended', 'error') 
        LIMIT 1
    ");
    $stmt->execute(['domain' => $domain]);
    
    if ($stmt->fetch()) {
        echo json_encode(['exists' => true]);
    } else {
        echo json_encode(['exists' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
