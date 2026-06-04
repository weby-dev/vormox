<?php
session_start();
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
    // Check if the domain exists anywhere in the user_panels table
    $stmt = $pdo->prepare("SELECT id FROM user_panels WHERE domain = :domain LIMIT 1");
    $stmt->execute(['domain' => $domain]);
    
    if ($stmt->fetch()) {
        echo json_encode(['exists' => true]);
    } else {
        echo json_encode(['exists' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
