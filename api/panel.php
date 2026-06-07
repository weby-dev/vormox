<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

// Move this to config.php ideally, or to an environment variable
$TOKEN = 'mf43owpwxcgs60sdzp6rndl48g';

/**
 * Reliably retrieve the Authorization header across different
 * server configurations (Apache, Nginx+FPM, CGI, etc.)
 */
function getAuthorizationHeader() {
    $auth = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if ($headers) {
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (isset($headers['authorization'])) {
                $auth = $headers['authorization'];
            }
        }
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers) {
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (isset($headers['authorization'])) {
                $auth = $headers['authorization'];
            }
        }
    }

    return trim($auth);
}

$authHeader = getAuthorizationHeader();

if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authorization token missing'
    ]);
    exit;
}

$receivedToken = $matches[1];

// Constant-time comparison to prevent timing attacks
if (!hash_equals($TOKEN, $receivedToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

try {
    $domain = trim($_GET['domain'] ?? '');

    if ($domain === '') {
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
            created_at,
            auto_renew,
            registration_date,
            expiry_date
        FROM user_panels
        WHERE domain = ?
        LIMIT 1
    ");
    $stmt->execute([$domain]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$panel) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No data found for this domain'
        ]);
        exit;
    }

    // Normalize data types for cleaner JSON output
    $panel['id']            = (int)   $panel['id'];
    $panel['user_id']       = (int)   $panel['user_id'];
    $panel['nodes_count']   = (int)   $panel['nodes_count'];
    $panel['total_price']   = (float) $panel['total_price'];
    $panel['auto_renew']    = (bool)  (int) $panel['auto_renew'];

    // Compute helpful extras
    $extras = [
        'is_active'        => strtolower($panel['status']) === 'active',
        'days_remaining'   => null,
        'is_expired'       => null,
    ];

    if (!empty($panel['expiry_date'])) {
        try {
            $now    = new DateTime('now');
            $expiry = new DateTime($panel['expiry_date']);
            $diff   = (int) $now->diff($expiry)->format('%r%a'); // signed days
            $extras['days_remaining'] = $diff;
            $extras['is_expired']     = $diff < 0;
        } catch (Exception $e) {
            // Leave as null if expiry_date can't be parsed
        }
    }

    echo json_encode([
        'success' => true,
        'data'    => $panel,
        'meta'    => $extras
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    // Log the real error server-side, but don't leak it to clients
    error_log('panel.php error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
