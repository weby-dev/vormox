<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';                 // gates banned/unverified, sets session
require_once 'includes/backup_store.php';      // backup_human_size, naming

$user_id = $_SESSION['user_id'];

// $user powers the header/sidebar chrome (same as every other dashboard page).
$userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

// This user's backups, newest first, grouped by subscription (panel domain).
try {
    $bkStmt = $pdo->prepare("
        SELECT b.id, b.subscription, b.db_name, b.s3_key, b.size_bytes, b.status, b.created_at,
               p.domain
          FROM backups b
          LEFT JOIN user_panels p ON p.id = b.panel_id
         WHERE b.user_id = ?
         ORDER BY b.subscription ASC, b.created_at DESC
    ");
    $bkStmt->execute([$user_id]);
    $rows = $bkStmt->fetchAll();
} catch (PDOException $e) {
    $rows = [];
}

$grouped = [];
foreach ($rows as $r) {
    $key = $r['subscription'];
    if (!isset($grouped[$key])) $grouped[$key] = ['domain' => $r['domain'] ?: $r['subscription'], 'items' => []];
    $grouped[$key]['items'][] = $r;
}

$page_title   = 'Backups';
$header_title = 'Database Backups';
include 'includes/header.php';
?>

<style>
    .page-header { margin-bottom: 32px; }
    .page-title  { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub    { font-size: 15px; color: var(--text-muted); max-width: 720px; }

    .sub-card    { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 24px; }
    .sub-head    { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.1); }
    .sub-head i  { color: var(--accent-green); }
    .sub-domain  { font-family: var(--font-head); font-weight: 700; font-size: 16px; color: var(--text); }
    .sub-count   { margin-left: auto; font-size: 12px; color: var(--text-dim); font-family: var(--font-mono); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 14px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text); }
    tr:last-child td { border-bottom: none; }
    .mono { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; font-family: var(--font-mono); display: inline-block; }
    .badge-uploaded { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-pending  { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-failed   { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .dl-btn { padding: 8px 16px; background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 6px; }
    .dl-btn:hover:not(:disabled) { background: var(--accent2); color: #fff; border-color: var(--accent2); }
    .dl-btn:disabled { opacity: .4; cursor: not-allowed; }

    .empty { background: var(--surface); border: 1px dashed var(--border-strong); border-radius: var(--radius); padding: 60px 24px; text-align: center; color: var(--text-muted); }

    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity .25s; padding: 20px; }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-card { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 420px; padding: 32px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
    .modal-card h3 { font-family: var(--font-head); font-size: 20px; margin-bottom: 8px; color: var(--text); }
    .modal-card p  { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; }
    .otp-input { width: 100%; padding: 14px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-mono); font-size: 22px; text-align: center; letter-spacing: 8px; outline: none; margin-bottom: 16px; }
    .otp-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
    .modal-actions { display: flex; gap: 10px; }
    .modal-actions button { flex: 1; padding: 12px; border-radius: 8px; border: none; font-weight: 600; font-size: 14px; cursor: pointer; }
    .btn-cancel { background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong) !important; }
    .btn-confirm { background: var(--accent2); color: #fff; }
    .btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
</style>

<div class="content-area">
    <div class="page-header">
        <div class="page-title">Database Backups</div>
        <div class="page-sub">Automated dumps of your panel databases, stored securely off-site. Downloads are protected — we'll email you a one-time code to confirm each one.</div>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="empty">
            <i class="fa-solid fa-clock-rotate-left" style="font-size: 32px; color: var(--text-dim); margin-bottom: 12px;"></i><br>
            No backups yet. Your databases are backed up automatically on a schedule — check back soon.
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $g): ?>
            <div class="sub-card">
                <div class="sub-head">
                    <i class="fa-solid fa-database"></i>
                    <span class="sub-domain"><?= htmlspecialchars($g['domain']) ?></span>
                    <span class="sub-count"><?= count($g['items']) ?> backup<?= count($g['items']) === 1 ? '' : 's' ?></span>
                </div>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Database</th><th>Size</th><th>Status</th><th style="text-align:right;">Download</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y · H:i', strtotime($b['created_at']))) ?></td>
                                <td class="mono"><?= htmlspecialchars($b['db_name']) ?></td>
                                <td><?= htmlspecialchars(backup_human_size($b['size_bytes'] !== null ? (int)$b['size_bytes'] : null)) ?></td>
                                <td><span class="badge badge-<?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span></td>
                                <td style="text-align:right;">
                                    <button class="dl-btn" <?= $b['status'] === 'uploaded' ? '' : 'disabled' ?>
                                        onclick="requestBackup(<?= (int)$b['id'] ?>, this)">
                                        <i class="fa-solid fa-download"></i> Download
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- OTP modal -->
<div class="modal-overlay" id="otpModal">
    <div class="modal-card">
        <h3>Confirm download</h3>
        <p>We emailed a 6-digit code to <strong><?= htmlspecialchars($user['email']) ?></strong>. Enter it to download this backup.</p>
        <input type="text" inputmode="numeric" maxlength="6" class="otp-input" id="otpInput" placeholder="••••••" autocomplete="one-time-code">
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeOtp()">Cancel</button>
            <button class="btn-confirm" id="otpConfirm" onclick="verifyBackup()">Download</button>
        </div>
    </div>
</div>

<script>
const CSRF = window.CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
let activeBackupId = null;

async function postBackup(params) {
    params.csrf_token = CSRF;
    const res = await fetch('backup_download.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params)
    });
    return res.json();
}

async function requestBackup(id, btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending code…';
    try {
        const data = await postBackup({ action: 'request_otp', id });
        if (data.ok) {
            activeBackupId = id;
            document.getElementById('otpInput').value = '';
            document.getElementById('otpModal').classList.add('show');
            setTimeout(() => document.getElementById('otpInput').focus(), 50);
        } else {
            alert(data.message || 'Could not start the download.');
        }
    } catch (e) {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

function closeOtp() {
    document.getElementById('otpModal').classList.remove('show');
    activeBackupId = null;
}

async function verifyBackup() {
    const otp = document.getElementById('otpInput').value.trim();
    if (otp.length !== 6) { document.getElementById('otpInput').focus(); return; }
    const confirm = document.getElementById('otpConfirm');
    const original = confirm.innerHTML;
    confirm.disabled = true;
    confirm.innerHTML = 'Verifying…';
    try {
        const data = await postBackup({ action: 'verify', id: activeBackupId, otp });
        if (data.ok && data.url) {
            closeOtp();
            window.location = data.url;   // presigned URL forces an attachment download
        } else {
            alert(data.message || 'Verification failed.');
        }
    } catch (e) {
        alert('Network error. Please try again.');
    } finally {
        confirm.disabled = false;
        confirm.innerHTML = original;
    }
}

document.getElementById('otpInput').addEventListener('keydown', e => { if (e.key === 'Enter') verifyBackup(); });
</script>

<?php include 'includes/footer.php'; ?>
