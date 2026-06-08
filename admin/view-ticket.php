<?php
session_start();
require_once '../config.php'; 

// --- SECURITY BOILERPLATE ---
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


csrf_require();
$success = ''; $error = '';
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ticket_id) { header("Location: tickets.php"); exit; }

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Action 1: Add a Reply
    // Replies live in ticket_messages (the only message table in the schema).
    // is_staff_reply = 1 marks this as an admin/support reply, sender_id is
    // the admin's id (it's not FK-constrained so collisions with users.id are
    // resolved at render time via is_staff_reply).
    if (isset($_POST['submit_reply'])) {
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            $error = "Reply message cannot be empty.";
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("
                    INSERT INTO ticket_messages (ticket_id, sender_id, message, is_staff_reply, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ")->execute([$ticket_id, $_SESSION['admin_id'], $message]);

                // After staff replies, the ball is in the customer's court.
                $pdo->prepare("
                    UPDATE tickets
                       SET status = 'Awaiting Reply', updated_at = NOW()
                     WHERE id = ?
                ")->execute([$ticket_id]);

                $pdo->commit();
                $success = "Reply posted successfully.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Admin reply failed: " . $e->getMessage());
                $error = "Failed to post reply.";
            }
        }
    }

    // Action 2: Update Ticket Status (canonical ENUM values only)
    if (isset($_POST['update_status'])) {
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
        $valid_statuses = ['Open', 'In Progress', 'Awaiting Reply', 'Resolved', 'Closed'];

        if (in_array($new_status, $valid_statuses, true)) {
            try {
                $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_status, $ticket_id]);
                $success = "Ticket status updated to {$new_status}.";
            } catch (PDOException $e) {
                $error = "Failed to update status.";
            }
        } else {
            $error = "Invalid status value.";
        }
    }
}

// --- FETCH DATA FOR NOTIFICATIONS AND VIEWS ---
$current_page = basename($_SERVER['PHP_SELF']);
try {
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
} catch (PDOException $e) {
    $pendingOrdersCount = 0;
}

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- FETCH SPECIFIC TICKET & MESSAGES ---
$messages = [];
try {
    // Main Ticket
    $stmt = $pdo->prepare("
        SELECT t.*, u.first_name, u.last_name, u.email,
               d.name AS department
          FROM tickets t
          JOIN users u ON t.user_id = u.id
          LEFT JOIN support_departments d ON t.department_id = d.id
         WHERE t.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $ticket_id]);
    $ticket = $stmt->fetch();

    if (!$ticket) { die("Ticket not found."); }

    // All messages — original first, replies in time order. We join users
    // ONLY when is_staff_reply=0 and admins ONLY when is_staff_reply=1 so the
    // sender_id can't accidentally match a row in the wrong table.
    $msgStmt = $pdo->prepare("
        SELECT m.id, m.message, m.is_staff_reply, m.sender_id, m.created_at, m.attachment_path,
               u.first_name AS user_fn,  u.last_name AS user_ln,
               a.first_name AS admin_fn, a.last_name AS admin_ln
          FROM ticket_messages m
          LEFT JOIN users  u ON m.is_staff_reply = 0 AND m.sender_id = u.id
          LEFT JOIN admins a ON m.is_staff_reply = 1 AND m.sender_id = a.id
         WHERE m.ticket_id = ?
         ORDER BY m.created_at ASC, m.id ASC
    ");
    $msgStmt->execute([$ticket_id]);
    $messages = $msgStmt->fetchAll();
} catch (PDOException $e) {
    error_log("admin/view-ticket fetch failed: " . $e->getMessage());
    if (!isset($ticket)) { die("Database error loading ticket."); }
}

$page_title = 'Ticket #' . $ticket['id'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head><?= csrf_meta() ?>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?> — Vormox Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  
  <script>
    const savedTheme = localStorage.getItem('admin_theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    document.documentElement.setAttribute('data-theme', savedTheme === 'light' || (!savedTheme && prefersLight) ? 'light' : 'dark');
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; }

    .btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; margin-bottom: 24px; font-weight: 500; transition: 0.2s; }
    .btn-back:hover { color: var(--text); }
    
    .layout-grid { display: grid; grid-template-columns: 1fr 350px; gap: 32px; align-items: start; }
    @media (max-width: 1100px) { .layout-grid { grid-template-columns: 1fr; } }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 24px; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 16px; font-weight: 700; background: rgba(0,0,0,0.1); color: var(--text); }
    
    /* Meta Sidebar Styles */
    .meta-row { display: flex; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; }
    .meta-row:last-child { border-bottom: none; }
    .meta-label { color: var(--text-muted); font-family: var(--font-mono); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
    .meta-value { font-weight: 600; color: var(--text); }

    .status-select { width: 100%; padding: 12px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-mono); font-size: 13px; outline: none; margin-bottom: 16px; }
    .status-select:focus { border-color: var(--accent); }
    .btn-block { display: block; width: 100%; text-align: center; padding: 12px; font-family: var(--font-body); font-weight: 600; font-size: 14px; border-radius: 8px; cursor: pointer; border: none; transition: 0.2s; background: var(--surface2); color: var(--text); }
    .btn-block:hover { background: var(--border); }
    
    .btn-primary { background: var(--accent2); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-primary:hover { filter: brightness(1.1); background: var(--accent2); color: #fff; }

    /* Thread Styles */
    .msg-bubble { padding: 24px; border-bottom: 1px solid var(--border); }
    .msg-bubble:last-child { border-bottom: none; }
    .msg-admin { background: rgba(139,92,246,0.03); border-left: 4px solid var(--accent); }
    
    .msg-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .msg-author { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; color: var(--text); }
    .author-avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #fff; }
    .avatar-user { background: var(--surface2); border: 1px solid var(--border-strong); color: var(--text); }
    .avatar-admin { background: linear-gradient(135deg,var(--accent),var(--accent2)); box-shadow: 0 4px 10px var(--accent-glow); }
    .msg-date { font-size: 12px; color: var(--text-muted); font-family: var(--font-mono); }
    
    .msg-body { font-size: 15px; line-height: 1.6; color: var(--text); white-space: pre-wrap; }

    .reply-box { padding: 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; margin-top: 24px; }
    .reply-box textarea { width: 100%; height: 150px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; padding: 16px; color: var(--text); font-family: var(--font-body); font-size: 14px; outline: none; resize: vertical; margin-bottom: 16px; transition: 0.2s; }
    .reply-box textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-Open            { background: rgba(96,165,250,0.12);  color: #60a5fa;            border: 1px solid rgba(96,165,250,0.25); }
    .badge-In-Progress     { background: rgba(167,139,250,0.12); color: var(--accent);      border: 1px solid rgba(167,139,250,0.25); }
    .badge-Awaiting-Reply  { background: rgba(251,146,60,0.12);  color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.25); }
    .badge-Resolved        { background: rgba(34,211,238,0.12);  color: var(--accent-green); border: 1px solid rgba(34,211,238,0.25); }
    .badge-Closed          { background: var(--surface2);        color: var(--text-muted);   border: 1px solid var(--border); }

    /* Toasts */
    #toast-container { position: fixed; bottom: 32px; right: 32px; z-index: 9999; display: flex; flex-direction: column; gap: 12px; }
    .toast { padding: 16px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 12px; color: var(--text); box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: slideIn 0.3s ease forwards; min-width: 300px; font-family: var(--font-body); background: var(--surface); }
    .toast.success { border: 1px solid rgba(34,211,238,0.3); border-left: 4px solid var(--accent-green); }
    .toast.error { border: 1px solid rgba(248,113,113,0.3); border-left: 4px solid var(--accent-red); }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
  </style>
</head>
<body>

<div id="toast-container"></div>

<aside>
  <a href="index.php" class="logo">
    <div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
    Vormox <span>Admin</span>
  </a>
  <nav>
    <div class="nav-label">Core</div>
    <a href="index.php" class="nav-item <?= $current_page == 'index.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
    
    <a href="orders.php" class="nav-item <?= $current_page == 'orders.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-inbox"></i> Pending Orders 
        <?php if(isset($pendingOrdersCount) && $pendingOrdersCount > 0): ?>
            <span style="background: var(--accent-orange); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: auto; font-weight: 800;"><?= $pendingOrdersCount ?></span>
        <?php endif; ?>
    </a>
    
    <a href="users.php" class="nav-item <?= $current_page == 'users.php' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Users & Clients</a>
    <a href="panels.php" class="nav-item <?= in_array($current_page, ['panels.php', 'manage_panel.php']) ? 'active' : '' ?>"><i class="fa-solid fa-server"></i> Provisioned Panels</a>
    
    <div class="nav-label">Financial</div>
    <a href="invoices.php" class="nav-item <?= in_array($current_page, ['invoices.php', 'view-invoice.php']) ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item <?= $current_page == 'gateways.php' ? 'active' : '' ?>"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <!-- Highlights active if on tickets.php OR view-ticket.php -->
    <a href="tickets.php" class="nav-item <?= in_array($current_page, ['tickets.php', 'view-ticket.php']) ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>
    <a href="security.php" class="nav-item <?= $current_page == 'security.php' ? 'active' : '' ?>"><i class="fa-solid fa-lock"></i> IP Whitelist</a>
    <a href="settings.php" class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Global Settings</a>
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
    <div class="header-title">Ticket #<?= htmlspecialchars($ticket['id']) ?></div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    <a href="tickets.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Queue</a>

    <div style="font-family: var(--font-head); font-size: 28px; font-weight: 700; color: var(--text); margin-bottom: 32px;">
        <?= htmlspecialchars($ticket['subject']) ?>
    </div>

    <div class="layout-grid">
        
        <!-- LEFT COLUMN: THREAD & REPLY -->
        <div>
            <div class="card" style="margin-bottom: 0;">
                
                <!-- Unified thread: original message + every reply, in order. -->
                <?php if (empty($messages)): ?>
                    <div class="msg-bubble">
                        <div class="msg-body" style="color: var(--text-muted); font-style: italic;">
                            This ticket has no messages yet.
                        </div>
                    </div>
                <?php else: foreach ($messages as $msg):
                    $isAdmin = !empty($msg['is_staff_reply']);
                    if ($isAdmin) {
                        $authorName = trim(($msg['admin_fn'] ?? '') . ' ' . ($msg['admin_ln'] ?? ''));
                        if ($authorName === '') $authorName = 'Support';
                    } else {
                        $authorName = trim(($msg['user_fn'] ?? '') . ' ' . ($msg['user_ln'] ?? ''));
                        if ($authorName === '') $authorName = trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'Customer';
                    }
                    $initial = strtoupper(substr($authorName, 0, 1));
                ?>
                <div class="msg-bubble <?= $isAdmin ? 'msg-admin' : '' ?>">
                    <div class="msg-header">
                        <div class="msg-author">
                            <?php if ($isAdmin): ?>
                                <div class="author-avatar avatar-admin"><i class="fa-solid fa-shield"></i></div>
                            <?php else: ?>
                                <div class="author-avatar avatar-user"><?= htmlspecialchars($initial) ?></div>
                            <?php endif; ?>
                            <?= htmlspecialchars($authorName) ?>
                            <?php if ($isAdmin): ?>
                                <span style="font-size: 11px; background: rgba(139,92,246,0.1); color: var(--accent2); padding: 2px 6px; border-radius: 4px; margin-left: 4px;">Staff</span>
                            <?php endif; ?>
                        </div>
                        <div class="msg-date"><?= date('M j, Y - g:i A', strtotime($msg['created_at'])) ?></div>
                    </div>
                    <div class="msg-body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Reply Box -->
            <form method="POST" action="view-ticket.php?id=<?= (int)$ticket_id ?>" class="reply-box">
                <?= csrf_field() ?>
                <div style="font-family: var(--font-head); font-weight: 700; margin-bottom: 16px;"><i class="fa-solid fa-reply"></i> Add a Reply</div>
                <textarea name="message" placeholder="Type your response here... Markdown/HTML is stripped for security." required></textarea>
                <div style="text-align: right;">
                    <button type="submit" name="submit_reply" class="btn-block btn-primary" style="display: inline-block; width: auto; padding: 12px 32px;"><i class="fa-solid fa-paper-plane"></i> Send Reply</button>
                </div>
            </form>
        </div>

        <!-- RIGHT COLUMN: META & STATUS -->
        <div>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-circle-info" style="color: var(--accent);"></i> Ticket Details</div>
                
                <div class="meta-row"><span class="meta-label">Department</span><span class="meta-value"><?= htmlspecialchars($ticket['department'] ?? 'Support') ?></span></div>
                <div class="meta-row"><span class="meta-label">Priority</span><span class="meta-value"><?= htmlspecialchars($ticket['priority'] ?? 'Low') ?></span></div>
                <div class="meta-row"><span class="meta-label">Status</span><span class="badge badge-<?= htmlspecialchars(str_replace(' ', '-', $ticket['status'])) ?>"><?= htmlspecialchars($ticket['status']) ?></span></div>
                <div class="meta-row" style="flex-direction: column; gap: 8px;">
                    <span class="meta-label">Client</span>
                    <a href="users.php" style="color: var(--accent2); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-user-circle"></i> <?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?></a>
                </div>
                
                <div style="padding: 24px; background: rgba(0,0,0,0.1); border-top: 1px solid var(--border);">
                    <form method="POST" action="view-ticket.php?id=<?= (int)$ticket_id ?>">
                        <?= csrf_field() ?>
                        <label class="meta-label" style="display: block; margin-bottom: 8px;">Change Status</label>
                        <select name="status" class="status-select">
                            <?php foreach (['Open','In Progress','Awaiting Reply','Resolved','Closed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_status" class="btn-block">Update Status</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
  </div>
</main>

<script>
// Handle PHP Post-back Toasts
<?php if ($success): ?>
    showToast('success', <?= json_encode($success) ?>);
<?php endif; ?>
<?php if ($error): ?>
    showToast('error', <?= json_encode($error) ?>);
<?php endif; ?>

function showToast(type, message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = type === 'success' 
        ? `<i class="fa-solid fa-check-circle" style="color: var(--accent-green); font-size: 18px;"></i> ${message}` 
        : `<i class="fa-solid fa-circle-exclamation" style="color: var(--accent-red); font-size: 18px;"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
}

document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('adminThemeToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            const body = document.documentElement;
            const currentTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            body.setAttribute('data-theme', currentTheme);
            localStorage.setItem('admin_theme', currentTheme);
        });
    }
});
</script>
</body>
</html>
