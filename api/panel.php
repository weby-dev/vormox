<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Response / security headers
// ---------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
// Server-to-server only — explicitly forbid browser cross-origin use.
header('Access-Control-Allow-Origin: null');
header('Vary: Origin');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function vormox_json_exit(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function vormox_get_auth_header(): string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k]) && is_string($_SERVER[$k])) {
            return trim($_SERVER[$k]);
        }
    }
    foreach (['apache_request_headers', 'getallheaders'] as $fn) {
        if (function_exists($fn)) {
            $h = $fn();
            if (is_array($h) && $h) {
                $h = array_change_key_case($h, CASE_LOWER);
                if (!empty($h['authorization']) && is_string($h['authorization'])) {
                    return trim($h['authorization']);
                }
            }
        }
    }
    return '';
}

function vormox_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Only honour X-Forwarded-For when the immediate peer is an explicitly
    // trusted reverse proxy. Otherwise it is attacker-controlled.
    $trusted = array_filter(array_map('trim',
        explode(',', (string) vormox_env('TRUSTED_PROXIES', ''))));
    if ($trusted && in_array($ip, $trusted, true)) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($fwd) && $fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
    }
    return $ip;
}

function vormox_audit(string $event, array $ctx = []): void {
    $entry = array_merge([
        'ts'    => gmdate('c'),
        'event' => $event,
        'ip'    => vormox_client_ip(),
        'ua'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'path'  => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    ], $ctx);
    error_log('[vormox-api] ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
}

// File-backed sliding-window rate limit on failed auth attempts per IP.
function vormox_rate_dir(): string {
    $dir = sys_get_temp_dir() . '/vormox-api-rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function vormox_rate_load(string $ip): array {
    $file = vormox_rate_dir() . '/auth_' . hash('sha256', $ip);
    if (!is_file($file)) return [];
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function vormox_rate_allow(string $ip, int $window = 60, int $max = 10): bool {
    $now = time();
    $entries = array_values(array_filter(
        vormox_rate_load($ip),
        static fn($t) => is_int($t) && $t > $now - $window
    ));
    return count($entries) < $max;
}

function vormox_rate_record(string $ip, int $window = 60): void {
    $file = vormox_rate_dir() . '/auth_' . hash('sha256', $ip);
    $now = time();
    $entries = array_values(array_filter(
        vormox_rate_load($ip),
        static fn($t) => is_int($t) && $t > $now - $window
    ));
    $entries[] = $now;
    @file_put_contents($file, json_encode($entries), LOCK_EX);
}

// ---------------------------------------------------------------------------
// Transport / method enforcement
// ---------------------------------------------------------------------------
$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
);
if (vormox_env('API_FORCE_HTTPS', '1') === '1' && !$isHttps) {
    vormox_audit('plaintext_rejected');
    vormox_json_exit(403, ['success' => false, 'message' => 'HTTPS required']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    vormox_json_exit(405, ['success' => false, 'message' => 'Method not allowed']);
}

// ---------------------------------------------------------------------------
// Optional IP allow-list (server-to-server lockdown)
// ---------------------------------------------------------------------------
$clientIp = vormox_client_ip();
$allowList = array_filter(array_map('trim',
    explode(',', (string) vormox_env('API_PANEL_IP_ALLOWLIST', ''))));
if ($allowList && !in_array($clientIp, $allowList, true)) {
    vormox_audit('ip_not_allowed');
    vormox_json_exit(403, ['success' => false, 'message' => 'Forbidden']);
}

// ---------------------------------------------------------------------------
// Server-side token sanity (fail closed, no detail leak)
// ---------------------------------------------------------------------------
$TOKEN = (string) vormox_env('API_PANEL_TOKEN', '');
if ($TOKEN === '' || strlen($TOKEN) < 32) {
    error_log('panel.php: API_PANEL_TOKEN is missing or shorter than 32 chars');
    vormox_json_exit(503, ['success' => false, 'message' => 'Service unavailable']);
}

// ---------------------------------------------------------------------------
// Rate limit BEFORE the auth check so an attacker can't brute-force freely
// ---------------------------------------------------------------------------
if (!vormox_rate_allow($clientIp)) {
    vormox_audit('auth_rate_limited');
    header('Retry-After: 60');
    vormox_json_exit(429, ['success' => false, 'message' => 'Too many requests']);
}

// ---------------------------------------------------------------------------
// Authenticate (unified 401 for missing OR invalid token)
// ---------------------------------------------------------------------------
$authHeader = vormox_get_auth_header();
$received   = '';
if ($authHeader !== '' &&
    preg_match('/^Bearer\s+([A-Za-z0-9._\-]{32,256})$/', $authHeader, $m)) {
    $received = $m[1];
}

if ($received === '' || !hash_equals($TOKEN, $received)) {
    vormox_rate_record($clientIp);
    vormox_audit('auth_failed');
    header('WWW-Authenticate: Bearer realm="vormox-api"');
    vormox_json_exit(401, ['success' => false, 'message' => 'Unauthorized']);
}

// ---------------------------------------------------------------------------
// Input validation: domain
// ---------------------------------------------------------------------------
$domainRaw = $_GET['domain'] ?? '';
if (!is_string($domainRaw)) {
    vormox_json_exit(400, ['success' => false, 'message' => 'Invalid domain parameter']);
}
$domain = strtolower(trim($domainRaw));
// Strip scheme + any path/query a caller might mistakenly include.
$domain = preg_replace('#^https?://#', '', $domain);
$domain = explode('/', $domain, 2)[0];
$domain = explode('?', $domain, 2)[0];

if ($domain === ''
    || strlen($domain) > 253
    || !preg_match(
        '/^(?=.{1,253}$)(?:(?!-)[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/',
        $domain
    )) {
    vormox_json_exit(400, [
        'success' => false,
        'message' => 'A valid domain parameter is required',
    ]);
}

// ---------------------------------------------------------------------------
// Lookup + response
// ---------------------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        SELECT
            domain,
            nodes_count,
            billing_cycle,
            status,
            auto_renew,
            registration_date,
            expiry_date,
            created_at
        FROM user_panels
        WHERE domain = ?
        LIMIT 1
    ");
    $stmt->execute([$domain]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$panel) {
        vormox_audit('domain_not_found', ['domain' => $domain]);
        vormox_json_exit(404, [
            'success' => false,
            'code'    => 'DOMAIN_NOT_FOUND',
            'message' => 'No subscription found for this domain',
        ]);
    }

    $nodesCount = (int) $panel['nodes_count'];
    $autoRenew  = (bool) (int) $panel['auto_renew'];
    $status     = (string) $panel['status'];
    $statusLc   = strtolower($status);

    $daysRemaining = null;
    $isExpired     = false;
    if (!empty($panel['expiry_date'])) {
        try {
            $tz     = new DateTimeZone('UTC');
            $now    = new DateTimeImmutable('now', $tz);
            $expiry = new DateTimeImmutable((string) $panel['expiry_date'], $tz);
            $daysRemaining = (int) $now->diff($expiry)->format('%r%a');
            $isExpired     = $daysRemaining < 0;
        } catch (Throwable $e) {
            // Unparseable expiry → rely on status alone.
        }
    }

    $subscriptionActive = ($statusLc === 'active') && !$isExpired;

    $response = [
        'success' => true,
        'data'    => [
            'domain'              => (string) $panel['domain'],
            'nodes_count'         => $nodesCount,
            'billing_cycle'       => $panel['billing_cycle'],
            'status'              => $status,
            'subscription_active' => $subscriptionActive,
            'auto_renew'          => $autoRenew,
            'registration_date'   => $panel['registration_date'],
            'expiry_date'         => $panel['expiry_date'],
            'days_remaining'      => $daysRemaining,
            'is_expired'          => $isExpired,
            'created_at'          => $panel['created_at'],
        ],
    ];

    if (!$subscriptionActive) {
        $response['code']    = 'SUBSCRIPTION_EXPIRED';
        $response['message'] = 'Subscription has expired';
    }

    vormox_audit('lookup_ok', [
        'domain'              => $domain,
        'subscription_active' => $subscriptionActive,
    ]);

    echo json_encode($response, JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    // Log the real error server-side, but never leak it to clients.
    error_log('panel.php exception: ' . $e->getMessage());
    vormox_json_exit(500, ['success' => false, 'message' => 'Internal server error']);
}
