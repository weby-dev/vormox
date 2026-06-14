<?php
// backup_download.php
//
// User-side OTP-gated backup download (JSON endpoint).
//   POST action=request_otp&id=<backup_id>  → email a 6-digit code
//   POST action=verify&id=<backup_id>&otp=… → return a short-lived presigned GET URL
//
// Every step enforces that the backup row belongs to the signed-in user. The
// OTP is single-use, 10-minute TTL, stored in the session keyed by backup id.

session_start();
require_once __DIR__ . '/config.php';                 // $pdo, env, csrf
require_once __DIR__ . '/includes/notifications.php';  // notify_backup_download_otp
require_once __DIR__ . '/includes/s3_client.php';
require_once __DIR__ . '/includes/backup_store.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only.']); exit;
}
csrf_require();

$user_id   = (int) $_SESSION['user_id'];
$action    = (string) ($_POST['action'] ?? '');
$backup_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$backup_id) {
    echo json_encode(['ok' => false, 'message' => 'Missing backup id.']); exit;
}

// Ownership + availability check.
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.s3_key, b.status, u.email, u.first_name
          FROM backups b
          JOIN users   u ON u.id = b.user_id
         WHERE b.id = ? AND b.user_id = ?
         LIMIT 1
    ");
    $stmt->execute([$backup_id, $user_id]);
    $b = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'Database error.']); exit;
}
if (!$b) {
    echo json_encode(['ok' => false, 'message' => 'Backup not found.']); exit;
}
if ($b['status'] !== 'uploaded') {
    echo json_encode(['ok' => false, 'message' => 'This backup is not available to download.']); exit;
}

if ($action === 'request_otp') {
    $otp = (string) random_int(100000, 999999);
    $_SESSION['backup_otp'][$backup_id] = ['code' => $otp, 'time' => time(), 's3_key' => $b['s3_key']];
    $sent = notify_backup_download_otp($b['email'], $b['first_name'], $otp, 10);
    echo json_encode($sent
        ? ['ok' => true,  'message' => 'A 6-digit code was emailed to you.']
        : ['ok' => false, 'message' => 'Could not send the code. Please try again shortly.']);
    exit;
}

if ($action === 'verify') {
    $otp = trim((string) ($_POST['otp'] ?? ''));
    $rec = $_SESSION['backup_otp'][$backup_id] ?? null;
    if (!$rec) {
        echo json_encode(['ok' => false, 'message' => 'Request a code first.']); exit;
    }
    if (time() - (int) $rec['time'] > 600) {
        unset($_SESSION['backup_otp'][$backup_id]);
        echo json_encode(['ok' => false, 'message' => 'Code expired — request a new one.']); exit;
    }
    if (!hash_equals((string) $rec['code'], $otp)) {
        echo json_encode(['ok' => false, 'message' => 'Incorrect code.']); exit;
    }
    unset($_SESSION['backup_otp'][$backup_id]);   // single use

    if (!s3_configured()) {
        echo json_encode(['ok' => false, 'message' => 'Backup storage is not configured.']); exit;
    }
    $url = s3_presign_get($b['s3_key'], 120, backup_download_name($b['s3_key']));
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
