<?php
session_start();
require_once '../config.php';
require_once '../includes/s3_client.php';
require_once '../includes/backup_store.php';

// --- SECURITY BOILERPLATE (matches the rest of /admin) ---
$user_ip = $_SERVER['REMOTE_ADDR'];
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_ip_whitelist");
    if ($countStmt->fetchColumn() > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM admin_ip_whitelist WHERE ip_address = :ip LIMIT 1");
        $checkStmt->execute(['ip' => $user_ip]);
        if (!$checkStmt->fetch()) { header("Location: ../dashboard.php"); exit; }
    }
} catch (PDOException $e) { die("Security verification failed."); }

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_logged_in'] !== true) { header("Location: login.php"); exit; }

csrf_require();   // enforces token on POST; no-op on GET

// --- ACTION: download (admin, no OTP) → 302 to a short-lived presigned URL ---
if (($_GET['action'] ?? '') === 'download') {
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !s3_configured()) { http_response_code(400); exit('Bad request.'); }
    header('Location: ' . s3_presign_get($key, 120, backup_download_name($key)));
    exit;
}

// --- ACTION: delete (POST) → remove from S3 + drop the DB row ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $key = (string) ($_POST['key'] ?? '');
    if ($key !== '' && s3_configured()) {
        $res = s3_delete($key);
        try { $pdo->prepare("DELETE FROM backups WHERE s3_key = ?")->execute([$key]); } catch (PDOException $e) {}
        $_SESSION['flash'] = $res['ok']
            ? ['type' => 'success', 'msg' => 'Backup deleted from S3.']
            : ['type' => 'error',   'msg' => 'S3 delete failed: ' . $res['error']];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Missing key or storage not configured.'];
    }
    header('Location: backups.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- Nav badge + sidebar identity ---
$current_page = basename($_SERVER['PHP_SELF']);
try { $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending','payment_pending')")->fetchColumn(); }
catch (PDOException $e) { $pendingOrdersCount = 0; }
$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- DB-tracked backups ---
try {
    $rows = $pdo->query("
        SELECT b.id, b.subscription, b.db_name, b.s3_key, b.size_bytes, b.status, b.created_at,
               u.first_name, u.last_name, u.email, p.domain
          FROM backups b
          JOIN users u ON u.id = b.user_id
          LEFT JOIN user_panels p ON p.id = b.panel_id
         ORDER BY b.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) { $rows = []; }

$total_size = 0; $subs = [];
foreach ($rows as $r) { if ($r['status'] === 'uploaded') $total_size += (int) $r['size_bytes']; $subs[$r['subscription']] = true; }

// --- Live S3 listing (actual bucket contents — reveals any drift/orphans) ---
$s3_ok = s3_configured();
$s3_objects = []; $s3_error = ''; $s3_truncated = false;
if ($s3_ok) {
    $l = s3_list('', 1000);
    if ($l['ok']) { $s3_objects = $l['objects']; $s3_truncated = $l['next'] !== null; }
    else          { $s3_error = $l['error'] ?: 'List failed.'; }
}

$page_title = 'Backups';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head><?= csrf_meta() ?>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <!-- Vormox favicon (global) -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    document.documentElement.setAttribute('data-theme', savedTheme === 'dark' ? 'dark' : 'light');
  </script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root, [data-theme="dark"] { --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35; --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3); --accent: #a78bfa; --accent2: #8b5cf6; --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68; --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace; --font-body: 'Instrument Sans', sans-serif; --accent-glow: rgba(139,92,246,0.35); --accent-red: #f87171; --accent-green: #22d3ee; --accent-orange: #fb923c; }
    [data-theme="light"] { --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0; --border: #e2e8f0; --border-strong: #cbd5e1; --accent: #7c3aed; --accent2: #6d28d9; --text: #0f172a; --text-muted: #475569; --text-dim: #64748b; --accent-glow: rgba(124,58,237,0.15); --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626; }
    body { background: var(--bg); color: var(--text); font-family: var(--font-body); display: flex; min-height: 100vh; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    aside { width: 260px; background: rgba(5,8,16,.95); border-right: 1px solid var(--border); padding: 24px; display: flex; flex-direction: column; z-index: 10; flex-shrink: 0; transition: background 0.3s; }
    [data-theme="light"] aside { background: var(--bg); }
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 48px; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,var(--accent),var(--accent2)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    .logo span { color: var(--accent2); }
    .nav-label { margin: 24px 0 8px 16px; font-size: 11px; font-family: var(--font-mono); color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.2s; margin-bottom: 4px; }
    .nav-item i { width: 20px; text-align: center; }
    .nav-item:hover { background: rgba(139,92,246,.08); color: var(--text); }
    .nav-item.active { background: var(--accent2); color: #fff; box-shadow: 0 4px 12px rgba(139,92,246,.3); }
    .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 24px; }
    main { flex: 1; display: flex; flex-direction: column; position: relative; height: 100vh; overflow-y: auto; }
    .grid-bg { position: absolute; inset: 0; pointer-events: none; z-index: 0; background-image: linear-gradient(var(--border) 1px,transparent 1px), linear-gradient(90deg,var(--border) 1px,transparent 1px); background-size: 60px 60px; }
    header { padding: 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 99; background: rgba(5,8,16,.65); backdrop-filter: blur(20px); position: sticky; top: 0; transition: background 0.3s; }
    [data-theme="light"] header { background: rgba(255,255,255,0.8); }
    .header-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    [data-theme="dark"] .fa-moon { display: none; } [data-theme="light"] .fa-sun { display: none; }
    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 16px; margin-bottom: 28px; }
    .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px 22px; }
    .stat .label { font-size: 12px; color: var(--text-muted); font-family: var(--font-mono); text-transform: uppercase; letter-spacing: .05em; }
    .stat .value { font-family: var(--font-head); font-size: 28px; font-weight: 800; margin-top: 6px; }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 28px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.1); }
    .card-title .sub { margin-left: auto; font-size: 12px; color: var(--text-dim); font-family: var(--font-mono); font-weight: 400; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 14px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 14px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }
    .mono { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); word-break: break-all; }
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-uploaded { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-pending  { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-failed   { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .act { padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-strong); background: var(--surface2); color: var(--text); }
    .act:hover { border-color: var(--accent); color: var(--accent); }
    .act-del { border-color: rgba(248,113,113,.3); color: var(--accent-red); background: transparent; }
    .act-del:hover { background: var(--accent-red); color: #fff; border-color: var(--accent-red); }
    .inline-form { display: inline; margin: 0; }
    .alert { padding: 14px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .empty { padding: 48px 24px; text-align: center; color: var(--text-dim); }
  </style>
</head>
<body>

<aside>
  <a href="index.php" class="logo">
    <div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
    Vormox <span>Admin</span>
  </a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    <a href="orders.php" class="nav-item"><i class="fa-solid fa-inbox"></i> Pending Orders
        <?php if(!empty($pendingOrdersCount)): ?><span style="background: var(--accent-orange); color:#fff; font-size:10px; padding:2px 6px; border-radius:10px; margin-left:auto; font-weight:800;"><?= (int)$pendingOrdersCount ?></span><?php endif; ?></a>
    <a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    <a href="backups.php" class="nav-item active"><i class="fa-solid fa-clock-rotate-left"></i> Backups</a>
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Global Settings</a>
  </nav>
  <div class="sidebar-footer">
    <?php if($admin): ?>
    <div style="padding: 0 16px 16px; font-size: 13px; color: var(--text-muted);">
        Logged in as<br><strong style="color: var(--text);"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong>
    </div>
    <?php endif; ?>
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);"><i class="fa-solid fa-arrow-right-from-bracket"></i> End Session</a>
  </div>
</aside>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title">Database Backups</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme"><i class="fa-solid fa-sun"></i><i class="fa-solid fa-moon"></i></button>
    </div>
  </header>

  <div class="content-area">

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!$s3_ok): ?>
      <div class="alert alert-error">S3 is not configured (set <code>S3_*</code> in <code>.env</code>). Downloads and the live bucket view are disabled until then.</div>
    <?php elseif ($s3_error): ?>
      <div class="alert alert-error">Could not reach S3: <?= htmlspecialchars($s3_error) ?></div>
    <?php endif; ?>

    <div class="stat-grid">
      <div class="stat"><div class="label">Tracked backups</div><div class="value"><?= count($rows) ?></div></div>
      <div class="stat"><div class="label">Subscriptions</div><div class="value"><?= count($subs) ?></div></div>
      <div class="stat"><div class="label">Stored size</div><div class="value"><?= htmlspecialchars(backup_human_size($total_size)) ?></div></div>
      <div class="stat"><div class="label">Objects in bucket</div><div class="value"><?= $s3_ok ? count($s3_objects) . ($s3_truncated ? '+' : '') : '—' ?></div></div>
    </div>

    <!-- DB-tracked backups -->
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-database" style="color: var(--accent-green);"></i> Tracked backups <span class="sub"><?= count($rows) ?> total</span></div>
      <?php if (empty($rows)): ?>
        <div class="empty">No backups recorded yet. They appear here once the backup cron runs.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Subscription</th><th>Owner</th><th>Database</th><th>When</th><th>Size</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['domain'] ?: $r['subscription']) ?></td>
                <td><div style="font-weight:500;"><?= htmlspecialchars(trim($r['first_name'].' '.$r['last_name'])) ?></div><div class="mono"><?= htmlspecialchars($r['email']) ?></div></td>
                <td class="mono"><?= htmlspecialchars($r['db_name']) ?></td>
                <td><?= htmlspecialchars(date('M j, Y · H:i', strtotime($r['created_at']))) ?></td>
                <td><?= htmlspecialchars(backup_human_size($r['size_bytes'] !== null ? (int)$r['size_bytes'] : null)) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td style="text-align:right; white-space:nowrap;">
                  <?php if ($r['status'] === 'uploaded' && $s3_ok): ?>
                    <a class="act" href="backups.php?action=download&key=<?= urlencode($r['s3_key']) ?>"><i class="fa-solid fa-download"></i> Download</a>
                  <?php endif; ?>
                  <?php if ($s3_ok): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Permanently delete this backup from S3?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="key" value="<?= htmlspecialchars($r['s3_key']) ?>">
                      <button class="act act-del" type="submit"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Live S3 bucket -->
    <?php if ($s3_ok): ?>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-cloud" style="color: var(--accent2);"></i> Live S3 bucket <span class="sub"><?= htmlspecialchars((string)vormox_env('S3_BUCKET','')) ?> · <?= count($s3_objects) ?><?= $s3_truncated ? '+ (truncated at 1000)' : '' ?> object(s)</span></div>
      <?php if (empty($s3_objects)): ?>
        <div class="empty"><?= $s3_error ? 'Could not list bucket.' : 'Bucket is empty.' ?></div>
      <?php else: ?>
        <table>
          <thead><tr><th>Object key</th><th>Size</th><th>Last modified</th><th style="text-align:right;">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($s3_objects as $o): ?>
              <tr>
                <td class="mono"><?= htmlspecialchars($o['key']) ?></td>
                <td><?= htmlspecialchars(backup_human_size((int)$o['size'])) ?></td>
                <td class="mono"><?= htmlspecialchars($o['modified'] ? date('M j, Y · H:i', strtotime($o['modified'])) : '—') ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <a class="act" href="backups.php?action=download&key=<?= urlencode($o['key']) ?>"><i class="fa-solid fa-download"></i> Download</a>
                  <form method="POST" class="inline-form" onsubmit="return confirm('Permanently delete this object from S3?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="key" value="<?= htmlspecialchars($o['key']) ?>">
                    <button class="act act-del" type="submit"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</main>

<script>
  document.getElementById('adminThemeToggle').addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', cur);
    localStorage.setItem('admin_theme', cur);
  });
</script>
</body>
</html>
