<?php
// backup_download.php
//
// User-side backup download. Two JSON steps + one streaming step:
//   POST action=request_otp&id=<backup_id>  → email a 6-digit code
//   POST action=verify&id=<backup_id>&otp=… → returns a vormox-domain fetch URL
//   GET  action=fetch&t=<token>             → streams the file THROUGH this server
//
// The S3 endpoint is never exposed to the browser — the presigned URL is used
// server-side only inside s3_stream_to_browser(). Every step is ownership-checked;
// the OTP and the fetch token are both single-use with short TTLs.

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/s3_client.php';
require_once __DIR__ . '/includes/backup_store.php';

if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']); exit;
}
$user_id = (int) $_SESSION['user_id'];
$action  = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// ---------------------------------------------------------------------------
// STREAM (GET) — pull the file from S3 server-side and pipe it to the browser.
// Authorised by a one-time token minted after OTP verification.
// ---------------------------------------------------------------------------
if ($action === 'fetch') {
    $token = (string) ($_GET['t'] ?? '');
    $rec   = $_SESSION['backup_dl'][$token] ?? null;
    if (!$rec || (time() - (int) $rec['time']) > 120) {
        if ($token !== '') unset($_SESSION['backup_dl'][$token]);
        http_response_code(403); exit('Download link expired — please request it again.');
    }
    unset($_SESSION['backup_dl'][$token]);   // single use

    // Re-validate ownership at fetch time and use the authoritative key.
    $st = $pdo->prepare("SELECT s3_key FROM backups WHERE id = ? AND user_id = ? AND status = 'uploaded' LIMIT 1");
    $st->execute([(int) $rec['backup_id'], $user_id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit('Backup not found.'); }

    s3_stream_to_browser($row['s3_key'], backup_download_name($row['s3_key']));
    exit;
}

// ---------------------------------------------------------------------------
// JSON steps (POST only, CSRF-guarded).
// ---------------------------------------------------------------------------
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only.']); exit;
}
csrf_require();

$backup_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$backup_id) { echo json_encode(['ok' => false, 'message' => 'Missing backup id.']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.s3_key, b.status, u.email, u.first_name
          FROM backups b JOIN users u ON u.id = b.user_id
         WHERE b.id = ? AND b.user_id = ? LIMIT 1
    ");
    $stmt->execute([$backup_id, $user_id]);
    $b = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'Database error.']); exit;
}
if (!$b)                          { echo json_encode(['ok' => false, 'message' => 'Backup not found.']); exit; }
if ($b['status'] !== 'uploaded')  { echo json_encode(['ok' => false, 'message' => 'This backup is not available to download.']); exit; }

if ($action === 'request_otp') {
    $otp = (string) random_int(100000, 999999);
    $_SESSION['backup_otp'][$backup_id] = ['code' => $otp, 'time' => time()];
    $sent = notify_backup_download_otp($b['email'], $b['first_name'], $otp, 10);
    echo json_encode($sent
        ? ['ok' => true,  'message' => 'A 6-digit code was emailed to you.']
        : ['ok' => false, 'message' => 'Could not send the code. Please try again shortly.']);
    exit;
}

if ($action === 'verify') {
    $otp = trim((string) ($_POST['otp'] ?? ''));
    $rec = $_SESSION['backup_otp'][$backup_id] ?? null;
    if (!$rec)                                  { echo json_encode(['ok' => false, 'message' => 'Request a code first.']); exit; }
    if (time() - (int) $rec['time'] > 600) {
        unset($_SESSION['backup_otp'][$backup_id]);
        echo json_encode(['ok' => false, 'message' => 'Code expired — request a new one.']); exit;
    }
    if (!hash_equals((string) $rec['code'], $otp)) { echo json_encode(['ok' => false, 'message' => 'Incorrect code.']); exit; }
    unset($_SESSION['backup_otp'][$backup_id]);   // single use

    // Mint a one-time, short-lived token and hand back a VORMOX-domain URL.
    // The browser never sees the S3 endpoint.
    $token = bin2hex(random_bytes(16));
    $_SESSION['backup_dl'][$token] = ['backup_id' => $backup_id, 'time' => time()];
    echo json_encode(['ok' => true, 'url' => 'backup_download.php?action=fetch&t=' . $token]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
