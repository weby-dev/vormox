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

// ---------------------------------------------------------------------------
// Canonical ENUMs (must match the DB definition exactly)
// ---------------------------------------------------------------------------
$valid_statuses   = ['Open', 'In Progress', 'Awaiting Reply', 'Resolved', 'Closed'];
$valid_priorities = ['Low', 'Medium', 'High', 'Critical'];

// ---------------------------------------------------------------------------
// SINGLE-ROW STATUS UPDATE (inline dropdown on each row)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ticket_id  = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status',    FILTER_SANITIZE_SPECIAL_CHARS);

    if ($ticket_id && in_array($new_status, $valid_statuses, true)) {
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $ticket_id]);
            $success = "Ticket #{$ticket_id} status updated to {$new_status}.";
        } catch (PDOException $e) {
            $error = "Failed to update ticket status.";
        }
    } else {
        $error = "Invalid status value.";
    }
}

// ---------------------------------------------------------------------------
// BULK ACTIONS — apply one operation to N selected tickets
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_apply'])) {
    $action = (string) ($_POST['bulk_action'] ?? '');   // 'status' | 'priority'
    $value  = (string) ($_POST['bulk_value']  ?? '');
    $raw    = $_POST['selected_tickets'] ?? [];
    $ids    = array_values(array_unique(array_filter(array_map('intval', (array) $raw))));

    if (empty($ids)) {
        $error = "No tickets selected.";
    } elseif ($action === 'status' && in_array($value, $valid_statuses, true)) {
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id IN ($place)");
            $stmt->execute(array_merge([$value], $ids));
            $success = $stmt->rowCount() . " ticket(s) status set to {$value}.";
        } catch (PDOException $e) {
            $error = "Bulk status update failed.";
        }
    } elseif ($action === 'priority' && in_array($value, $valid_priorities, true)) {
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id IN ($place)");
            $stmt->execute(array_merge([$value], $ids));
            $success = $stmt->rowCount() . " ticket(s) priority set to {$value}.";
        } catch (PDOException $e) {
            $error = "Bulk priority update failed.";
        }
    } else {
        $error = "Invalid bulk action or value.";
    }
}

// --- Sidebar / stat data ---
$current_page = basename($_SERVER['PHP_SELF']);
try {
    $pendingOrdersCount = $pdo->query("SELECT COUNT(*) FROM user_panels WHERE status IN ('pending', 'payment_pending')")->fetchColumn();
} catch (PDOException $e) { $pendingOrdersCount = 0; }

$adminStmt = $pdo->prepare("SELECT first_name, last_name FROM admins WHERE id = :id LIMIT 1");
$adminStmt->execute(['id' => $_SESSION['admin_id']]);
$admin = $adminStmt->fetch();

// --- Filters ---
$filter_status   = $_GET['status']   ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$filter_search   = trim((string) ($_GET['q'] ?? ''));

// --- Stats + tickets ---
$totalTickets = 0; $openTickets = 0; $closedTickets = 0; $tickets = [];
try {
    $totalTickets  = (int) $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $openTickets   = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'In Progress', 'Awaiting Reply')")->fetchColumn();
    $closedTickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Resolved', 'Closed')")->fetchColumn();

    // Build the WHERE clause from the active filters.
    $where  = [];
    $params = [];
    if (in_array($filter_status, $valid_statuses, true)) {
        $where[] = "t.status = ?"; $params[] = $filter_status;
    }
    if (in_array($filter_priority, $valid_priorities, true)) {
        $where[] = "t.priority = ?"; $params[] = $filter_priority;
    }
    if ($filter_search !== '') {
        $where[] = "(t.subject LIKE ? OR t.reference_id LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
        $like = '%' . $filter_search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = "
        SELECT t.*, u.first_name, u.last_name, u.email
          FROM tickets t
          JOIN users   u ON t.user_id = u.id
    ";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= "
        ORDER BY
            CASE t.status
                WHEN 'Open' THEN 1
                WHEN 'Awaiting Reply' THEN 2
                WHEN 'In Progress' THEN 3
                WHEN 'Resolved' THEN 4
                ELSE 5
            END,
            t.updated_at DESC
    ";
    $ticketsStmt = $pdo->prepare($sql);
    $ticketsStmt->execute($params);
    $tickets = $ticketsStmt->fetchAll();
} catch (PDOException $e) {
    // graceful empty state on missing schema / DB hiccup
}

// Per-status counts for filter chips (over the full table, not the filtered set)
$statusCounts = ['all' => $totalTickets];
foreach ($valid_statuses as $s) { $statusCounts[$s] = 0; }
try {
    $cStmt = $pdo->query("SELECT status, COUNT(*) c FROM tickets GROUP BY status");
    foreach ($cStmt->fetchAll() as $r) {
        if (isset($statusCounts[$r['status']])) $statusCounts[$r['status']] = (int)$r['c'];
    }
} catch (PDOException $e) { /* leave zeros */ }

$page_title = 'Support Tickets';
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
    :root, [data-theme="dark"] { --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35; --border: rgba(139,92,246,0.15); --border-strong: rgba(139,92,246,0.3); --accent: #a78bfa; --accent2: #8b5cf6; --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68; --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace; --font-body: 'Instrument Sans', sans-serif; --accent-glow: rgba(139,92,246,0.35); --accent-red: #f87171; --accent-green: #22d3ee; --accent-orange: #fb923c; --accent-blue: #60a5fa; }
    [data-theme="light"] { --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0; --border: #e2e8f0; --border-strong: #cbd5e1; --accent: #7c3aed; --accent2: #6d28d9; --text: #0f172a; --text-muted: #475569; --text-dim: #64748b; --accent-glow: rgba(124,58,237,0.15); --accent-green: #0891b2; --accent-orange: #ea580c; --accent-red: #dc2626; --accent-blue: #2563eb; }

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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 22px; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    [data-theme="light"] .stat-card { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .stat-icon { position: absolute; top: 22px; right: 22px; width: 38px; height: 38px; border-radius: 10px; background: rgba(139,92,246,0.1); color: var(--accent2); display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .stat-label { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px; }
    .stat-value { font-family: var(--font-head); font-size: 32px; font-weight: 700; color: var(--text); }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .card-title { padding: 22px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 17px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 10px; background: rgba(0,0,0,0.1); }

    /* Filter row */
    .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 14px 22px; border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.05); }
    .filter-tab { font-size: 13px; font-weight: 500; color: var(--text-muted); padding: 6px 12px; border-radius: 6px; cursor: pointer; user-select: none; text-decoration: none; transition: 0.2s; }
    .filter-tab:hover { color: var(--text); background: rgba(139,92,246,0.08); }
    .filter-tab.active { background: rgba(167,139,250,0.18); color: var(--accent); }
    .filter-tab .count { display: inline-block; background: rgba(100,116,139,0.18); color: var(--text-dim); padding: 1px 8px; border-radius: 100px; margin-left: 6px; font-size: 11px; font-family: var(--font-mono); }
    .filter-tab.active .count { background: var(--accent2); color: #fff; }

    .search-wrap { margin-left: auto; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .search-wrap input, .search-wrap select { padding: 7px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-body); font-size: 13px; outline: none; transition: 0.2s; }
    .search-wrap input:focus, .search-wrap select:focus { border-color: var(--accent); }
    .search-wrap .btn-go { padding: 7px 14px; background: var(--accent2); color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; }
    .search-wrap .btn-go:hover { filter: brightness(1.1); }
    .search-wrap .btn-clr { padding: 7px 12px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-size: 13px; cursor: pointer; text-decoration: none; }

    /* Bulk action bar */
    .bulk-bar { display: none; align-items: center; gap: 10px; padding: 12px 22px; background: rgba(167,139,250,0.08); border-bottom: 1px solid rgba(167,139,250,0.2); font-size: 14px; color: var(--text); flex-wrap: wrap; animation: bulkSlide 0.18s ease-out; }
    @keyframes bulkSlide { from { transform: translateY(-6px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .bulk-bar .count-pill { background: var(--accent2); color: #fff; font-weight: 700; font-family: var(--font-mono); font-size: 12px; padding: 4px 10px; border-radius: 100px; }
    .bulk-bar select { padding: 7px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-body); font-size: 13px; outline: none; }
    .bulk-bar .btn-apply { padding: 7px 14px; background: var(--accent2); color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; }
    .bulk-bar .btn-apply:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .bulk-bar .btn-apply:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    .bulk-bar .btn-clear { padding: 7px 12px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-size: 13px; cursor: pointer; }
    .bulk-bar .btn-clear:hover { color: var(--text); border-color: var(--border-strong); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 14px 22px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 18px 22px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }
    tr.is-selected td { background: rgba(167,139,250,0.06) !important; }

    .col-check { width: 44px; padding-right: 0 !important; }
    input[type="checkbox"].t-check {
        appearance: none; width: 18px; height: 18px;
        border: 1.5px solid var(--border-strong); border-radius: 4px;
        background: var(--bg2); cursor: pointer; position: relative; transition: 0.15s; vertical-align: middle;
    }
    input[type="checkbox"].t-check:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"].t-check:checked::after {
        content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
        color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    }

    .table-link { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
    .table-link:hover .link-title { color: var(--accent2); }

    .btn-action { padding: 8px 14px; background: var(--accent2); color: #fff; font-size: 12px; font-weight: 600; font-family: var(--font-body); text-decoration: none; border-radius: 6px; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; box-shadow: 0 4px 15px var(--accent-glow); }
    .btn-action:hover { transform: translateY(-1px); filter: brightness(1.1); }

    .status-select { padding: 7px 10px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }
    .inline-form { display: flex; gap: 8px; align-items: center; margin: 0; }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-Open            { background: rgba(96,165,250,0.12); color: var(--accent-blue);   border: 1px solid rgba(96,165,250,0.25); }
    .badge-In-Progress     { background: rgba(167,139,250,0.12); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.25); }
    .badge-Awaiting-Reply  { background: rgba(251,146,60,0.12);  color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.25); }
    .badge-Resolved        { background: rgba(34,211,238,0.12);  color: var(--accent-green);  border: 1px solid rgba(34,211,238,0.25); }
    .badge-Closed          { background: var(--surface2);        color: var(--text-muted);    border: 1px solid var(--border); }

    .prio { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; }
    .prio .dot { width: 8px; height: 8px; border-radius: 50%; }
    .prio-Critical { color: var(--accent-red); font-weight: 700; }
    .prio-Critical .dot { background: var(--accent-red); box-shadow: 0 0 8px rgba(248,113,113,0.4); }
    .prio-High     { color: var(--accent-orange); font-weight: 600; }
    .prio-High .dot { background: var(--accent-orange); }
    .prio-Medium   { color: var(--accent-blue); }
    .prio-Medium .dot { background: var(--accent-blue); }
    .prio-Low      { color: var(--text-muted); }
    .prio-Low .dot { background: var(--text-dim); }

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
    <div class="header-title">Support Desk</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">

    <div class="stats-grid">
        <div class="stat-card" style="<?= $openTickets > 0 ? 'border-color: rgba(251,146,60,0.4); box-shadow: 0 0 20px rgba(251,146,60,0.1);' : '' ?>">
            <div class="stat-icon" style="background: rgba(251,146,60,0.1); color: var(--accent-orange);"><i class="fa-solid fa-envelope-open-text"></i></div>
            <div class="stat-label">Awaiting Response</div>
            <div class="stat-value" style="<?= $openTickets > 0 ? 'color: var(--accent-orange);' : '' ?>"><?= number_format($openTickets) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(34,211,238,0.1); color: var(--accent-green);"><i class="fa-solid fa-check-double"></i></div>
            <div class="stat-label">Resolved / Closed</div>
            <div class="stat-value"><?= number_format($closedTickets) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-ticket"></i></div>
            <div class="stat-label">Total Lifetime Tickets</div>
            <div class="stat-value"><?= number_format($totalTickets) ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-headset" style="color: var(--accent);"></i> Helpdesk Queue
            </div>
            <div style="font-size: 12px; font-family: var(--font-mono); color: var(--text-dim); font-weight: 500;">
                Showing <?= count($tickets) ?> of <?= $totalTickets ?> tickets
            </div>
        </div>

        <!-- Filter chips + search -->
        <form method="GET" action="tickets.php" class="filter-bar">
            <a href="tickets.php" class="filter-tab <?= ($filter_status === 'all') ? 'active' : '' ?>">All <span class="count"><?= $statusCounts['all'] ?></span></a>
            <?php foreach ($valid_statuses as $s):
                $href = '?status=' . urlencode($s);
                if ($filter_priority !== 'all') $href .= '&priority=' . urlencode($filter_priority);
                if ($filter_search !== '')      $href .= '&q='        . urlencode($filter_search);
            ?>
                <a href="<?= htmlspecialchars($href) ?>" class="filter-tab <?= ($filter_status === $s) ? 'active' : '' ?>"><?= htmlspecialchars($s) ?> <span class="count"><?= $statusCounts[$s] ?></span></a>
            <?php endforeach; ?>

            <div class="search-wrap">
                <select name="priority" onchange="this.form.submit()">
                    <option value="all" <?= $filter_priority === 'all' ? 'selected' : '' ?>>Any priority</option>
                    <?php foreach ($valid_priorities as $p): ?>
                        <option value="<?= $p ?>" <?= $filter_priority === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <input type="text" name="q" placeholder="Search subject, ref, client, email…" value="<?= htmlspecialchars($filter_search) ?>" style="min-width: 240px;">
                <button type="submit" class="btn-go"><i class="fa-solid fa-magnifying-glass"></i></button>
                <?php if ($filter_status !== 'all' || $filter_priority !== 'all' || $filter_search !== ''): ?>
                    <a href="tickets.php" class="btn-clr">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Bulk action bar (inside the same form below so its fields submit together) -->
        <form method="POST" action="tickets.php" id="bulkForm">
            <?= csrf_field() ?>
            <input type="hidden" name="bulk_apply" value="1">

            <div class="bulk-bar" id="bulkBar">
                <span class="count-pill"><span id="selectedCount">0</span> selected</span>

                <select name="bulk_action" id="bulkActionSelect" required>
                    <option value="">— Choose action —</option>
                    <option value="status">Set status</option>
                    <option value="priority">Set priority</option>
                </select>

                <select name="bulk_value" id="bulkValueSelect" required disabled>
                    <option value="">—</option>
                </select>

                <button type="submit" class="btn-apply" id="bulkApplyBtn" disabled
                        onclick="return confirm('Apply this change to the selected tickets?');">
                    <i class="fa-solid fa-bolt"></i> Apply
                </button>
                <button type="button" class="btn-clear" id="bulkClearBtn">Clear</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" class="t-check" id="selectAll" title="Select all visible"></th>
                        <th width="6%">ID</th>
                        <th width="32%">Subject &amp; Client</th>
                        <th width="10%">Priority</th>
                        <th width="14%">Current Status</th>
                        <th width="14%">Updated</th>
                        <th width="20%" style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">
                            <i class="fa-solid fa-headset" style="font-size: 28px; opacity: 0.4; display: block; margin-bottom: 12px;"></i>
                            No tickets match the current filters.
                        </td></tr>
                    <?php else: foreach ($tickets as $t):
                        $statusClass = 'badge-' . str_replace(' ', '-', $t['status']);
                        $prioClass   = 'prio-' . htmlspecialchars($t['priority'] ?: 'Low');
                    ?>
                        <tr>
                            <td class="col-check">
                                <input type="checkbox" class="t-check ticket-check" name="selected_tickets[]" value="<?= (int)$t['id'] ?>">
                            </td>
                            <td style="font-family: var(--font-mono); color: var(--text-muted); font-size: 13px;">#<?= htmlspecialchars($t['id']) ?></td>
                            <td>
                                <a href="view-ticket.php?id=<?= (int)$t['id'] ?>" class="table-link">
                                    <div class="link-title" style="font-weight: 600; font-size: 14px; margin-bottom: 4px; color: var(--text);"><?= htmlspecialchars($t['subject']) ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">
                                        <i class="fa-solid fa-user" style="font-size: 10px; margin-right: 4px;"></i>
                                        <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?>
                                        <span style="opacity: 0.6;">·</span>
                                        <?= htmlspecialchars($t['email']) ?>
                                    </div>
                                    <?php if (!empty($t['reference_id'])): ?>
                                        <div style="font-size: 11px; color: var(--text-dim); font-family: var(--font-mono); margin-top: 2px;"><?= htmlspecialchars($t['reference_id']) ?></div>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td><span class="prio <?= $prioClass ?>"><span class="dot"></span><?= htmlspecialchars($t['priority'] ?: 'Low') ?></span></td>
                            <td>
                                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($t['status']) ?></span>
                            </td>
                            <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);">
                                <?= date('M j, g:i A', strtotime($t['updated_at'])) ?>
                            </td>
                            <td>
                                <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                                    <!--
                                      Inline per-row status change.
                                      Separate form so it doesn't submit selected_tickets[] from the bulk form.
                                    -->
                                </div>
                                <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                                    <a href="view-ticket.php?id=<?= (int)$t['id'] ?>" class="btn-action"><i class="fa-solid fa-reply"></i> Reply</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>

        <!--
          Per-row status dropdowns live OUTSIDE the bulk form so submitting them
          doesn't drag along selected_tickets[]. Rendered as a separate <form>
          per row, positioned via JS into the right table cell.
        -->
        <?php foreach ($tickets as $t): ?>
            <form method="POST" action="tickets.php" class="inline-form quick-status" data-target="#row-action-<?= (int)$t['id'] ?>" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="update_status" value="1">
                <select name="status" class="status-select" onchange="this.form.submit()">
                    <?php foreach ($valid_statuses as $s): ?>
                        <option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endforeach; ?>
    </div>
  </div>
</main>

<script>
const VALID_STATUSES   = <?= json_encode($valid_statuses)   ?>;
const VALID_PRIORITIES = <?= json_encode($valid_priorities) ?>;

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
    const themeToggle = document.getElementById('adminThemeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const body = document.documentElement;
            const next = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            body.setAttribute('data-theme', next);
            localStorage.setItem('admin_theme', next);
        });
    }

    // ---- Per-row inline status forms — mount them in the rightmost cell ----
    document.querySelectorAll('table tbody tr').forEach(tr => {
        const last = tr.querySelector('td:last-child > div:last-child');
        if (!last) return;
        const cb  = tr.querySelector('.ticket-check');
        if (!cb) return;
        const id  = cb.value;
        const fr  = document.querySelector(`.quick-status[data-target="#row-action-${id}"]`);
        if (!fr) return;
        fr.style.display = 'flex';
        last.insertBefore(fr, last.firstChild);
    });

    // ---- Bulk action UI ----
    const selectAll       = document.getElementById('selectAll');
    const bulkBar         = document.getElementById('bulkBar');
    const selectedCountEl = document.getElementById('selectedCount');
    const bulkActionSel   = document.getElementById('bulkActionSelect');
    const bulkValueSel    = document.getElementById('bulkValueSelect');
    const bulkApplyBtn    = document.getElementById('bulkApplyBtn');
    const bulkClearBtn    = document.getElementById('bulkClearBtn');

    const getBoxes = () => document.querySelectorAll('.ticket-check');

    function refreshBulkBar() {
        const all = Array.from(getBoxes());
        const checked = all.filter(b => b.checked);
        selectedCountEl.textContent = checked.length;
        bulkBar.style.display = checked.length > 0 ? 'flex' : 'none';

        document.querySelectorAll('tbody tr').forEach(row => {
            const cb = row.querySelector('.ticket-check');
            row.classList.toggle('is-selected', cb && cb.checked);
        });

        if (all.length === 0) {
            selectAll.checked = false; selectAll.indeterminate = false;
        } else {
            selectAll.checked       = checked.length === all.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        }
        refreshApplyState();
    }

    function refreshApplyState() {
        const hasAction = !!bulkActionSel.value;
        const hasValue  = !!bulkValueSel.value;
        const hasRows   = Array.from(getBoxes()).some(b => b.checked);
        bulkApplyBtn.disabled = !(hasAction && hasValue && hasRows);
    }

    bulkActionSel.addEventListener('change', () => {
        const action = bulkActionSel.value;
        bulkValueSel.innerHTML = '<option value="">—</option>';
        if (action === 'status') {
            VALID_STATUSES.forEach(v => {
                const o = document.createElement('option'); o.value = v; o.textContent = v;
                bulkValueSel.appendChild(o);
            });
            bulkValueSel.disabled = false;
        } else if (action === 'priority') {
            VALID_PRIORITIES.forEach(v => {
                const o = document.createElement('option'); o.value = v; o.textContent = v;
                bulkValueSel.appendChild(o);
            });
            bulkValueSel.disabled = false;
        } else {
            bulkValueSel.disabled = true;
        }
        refreshApplyState();
    });
    bulkValueSel.addEventListener('change', refreshApplyState);

    selectAll.addEventListener('change', () => {
        const target = selectAll.checked;
        getBoxes().forEach(cb => cb.checked = target);
        refreshBulkBar();
    });

    document.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('ticket-check')) refreshBulkBar();
    });

    bulkClearBtn.addEventListener('click', () => {
        getBoxes().forEach(cb => cb.checked = false);
        refreshBulkBar();
    });

    refreshBulkBar();
});
</script>
</body>
</html>
