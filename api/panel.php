<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$TOKEN = 'mf43owpwxcgs60sdzp6rndl48g';

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authorization token missing'
    ]);
    exit;
}

$receivedToken = $matches[1];

if ($receivedToken !== $TOKEN) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

try {
    $domain = trim($_GET['domain'] ?? '');

    if (empty($domain)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Domain parameter is required'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            domain,
            nodes_count,
            billing_cycle,
            total_price,
            status,
            created_at
        FROM user_panels
        WHERE domain = ?
        LIMIT 1
    ");

    $stmt->execute([$domain]);
    $panel = $stmt->fetch();

    if (!$panel) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No data found for this domain'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $panel
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}