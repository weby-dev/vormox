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

// --- UPDATE PANEL STATUS (Manual Row Change) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $valid_statuses = ['payment_pending', 'pending', 'creating', 'active', 'restarting', 'suspended', 'error'];
    
    if ($panel_id && in_array($new_status, $valid_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE user_panels SET status = :status WHERE id = :pid");
            $stmt->execute(['status' => $new_status, 'pid' => $panel_id]);
            $success = "Panel status updated successfully to " . strtoupper($new_status) . ".";
        } catch (PDOException $e) {
            $error = "Failed to update panel status.";
        }
    } else {
        $error = "Invalid status selected.";
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

// --- FETCH FILTERED PANELS (Exclude Pending) ---
try {
    // Only fetch statuses that are past the initial order phase
    $panelsStmt = $pdo->query("
        SELECT p.*, u.first_name, u.last_name, u.email 
        FROM user_panels p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status IN ('creating', 'active', 'restarting', 'suspended', 'error')
        ORDER BY p.created_at DESC
    ");
    $panels = $panelsStmt->fetchAll();
} catch (PDOException $e) {
    die("Database error while loading panels.");
}

$page_title = 'Provisioned Infrastructure';
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

    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1600px; margin: 0 auto; width: 100%; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .card-title { padding: 20px 24px; border-bottom: 1px solid var(--border); font-family: var(--font-head); font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 10px; background: rgba(0,0,0,0.1); }
    
    .toolbar { display: flex; gap: 12px; align-items: center; }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; transition: background 0.2s; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    .table-link { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
    .table-link:hover .link-title { color: var(--accent2); }

    input[type="checkbox"] { appearance: none; width: 18px; height: 18px; border: 2px solid var(--border-strong); border-radius: 4px; background: var(--bg); cursor: pointer; position: relative; transition: 0.2s; }
    input[type="checkbox"]:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"]:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    .btn { padding: 8px 16px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; transition: 0.2s; font-size: 12px; font-family: var(--font-body); display: inline-flex; align-items: center; gap: 6px; }
    .btn-outline { background: transparent; border: 1px solid var(--border-strong); color: var(--text); }
    .btn-outline:hover { background: var(--surface2); border-color: var(--accent); color: var(--accent); }
    
    .status-select { padding: 8px 12px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; transition: 0.3s; cursor: pointer; }
    .status-select:focus { border-color: var(--accent); }
    .inline-form { display: flex; gap: 8px; align-items: center; margin: 0; }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-creating { background: rgba(59,130,246,0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.2); }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-restarting { background: rgba(251,191,36,0.1); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }
    .badge-suspended { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .badge-error { background: rgba(220,38,38,0.1); color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }

    /* Toast Notifications */
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
    <a href="invoices.php" class="nav-item <?= $current_page == 'invoices.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
    <a href="gateways.php" class="nav-item <?= $current_page == 'gateways.php' ? 'active' : '' ?>"><i class="fa-solid fa-building-columns"></i> Gateways</a>
    
    <div class="nav-label">System</div>
    <a href="tickets.php" class="nav-item <?= $current_page == 'tickets.php' ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>
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
    <div class="header-title">Infrastructure Provisioning</div>
    <div style="display: flex; gap: 16px; align-items: center;">
        <span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-dim);">IP: <?= htmlspecialchars($user_ip) ?></span>
        <button class="theme-toggle" id="adminThemeToggle" aria-label="Toggle Theme">
          <i class="fa-solid fa-sun"></i>
          <i class="fa-solid fa-moon"></i>
        </button>
    </div>
  </header>

  <div class="content-area">
    
    <div class="card">
        <div class="card-title">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-server" style="color: var(--accent-green);"></i> Active Deployments
            </div>
            
            <div class="toolbar" style="background: var(--bg2); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border);">
                
                <div style="display: flex; gap: 6px; align-items: center; border-right: 1px solid var(--border-strong); padding-right: 12px;">
                    <select id="bulkBeAction" class="status-select" style="margin:0;">
                        <option value="">-- Backend --</option>
                        <option value="create">Create (Rebuild)</option>
                        <option value="update">Update Code</option>
                        <option value="start">Start Service</option>
                        <option value="stop">Stop Service</option>
                        <option value="restart">Restart</option>
                    </select>
                    <button class="btn btn-outline" type="button" onclick="runBulk('be')"><i class="fa-solid fa-bolt"></i> Run</button>
                </div>

                <div style="display: flex; gap: 6px; align-items: center; padding-left: 6px;">
                    <select id="bulkFeAction" class="status-select" style="margin:0;">
                        <option value="">-- Frontend --</option>
                        <optgroup label="Daemon">
                            <option value="start">Start Service</option>
                            <option value="stop">Stop Service</option>
                            <option value="restart">Restart</option>
                        </optgroup>
                        <optgroup label="Build / Deploy">
                            <option value="create">Create (first-time install)</option>
                            <option value="update">Update Code (rebuild)</option>
                            <option value="delete">Delete (wipe + remove unit)</option>
                        </optgroup>
                    </select>
                    <button class="btn btn-outline" type="button" onclick="runBulk('fe')"><i class="fa-solid fa-bolt"></i> Run</button>
                </div>

            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="5%"><input type="checkbox" id="selectAll" title="Select All"></th>
                    <th width="20%">Domain</th>
                    <th width="20%">Client</th>
                    <th width="10%">Spec</th>
                    <th width="15%">Panel State</th>
                    <th width="15%">Override State</th>
                    <th width="10%">Expiry Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($panels)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">No active panels found.</td></tr>
                <?php else: foreach($panels as $p): ?>
                    <tr id="row-<?= $p['id'] ?>">
                        <td><input type="checkbox" value="<?= $p['id'] ?>" class="panel-checkbox"></td>
                        <td>
                            <a href="manage_panel.php?id=<?= htmlspecialchars($p['id']) ?>" class="table-link">
                                <div class="link-title" style="font-weight: 600; font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-link" style="font-size: 12px; margin-right: 6px; color: var(--text-muted);"></i><?= htmlspecialchars($p['domain']) ?></div>
                            </a>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 500; color: var(--text);"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($p['email']) ?></div>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 500;"><?= htmlspecialchars($p['nodes_count']) ?> Node(s)</div>
                            <div style="font-size: 11px; color: var(--text-muted); text-transform: capitalize; font-family: var(--font-mono);"><?= htmlspecialchars(str_replace('_', '-', $p['billing_cycle'])) ?></div>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($p['status']) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $p['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="panels.php" class="inline-form"><?= csrf_field() ?>
                                <input type="hidden" name="panel_id" value="<?= htmlspecialchars($p['id']) ?>">
                                <select name="status" class="status-select" onchange="this.form.submit()" style="padding: 6px 10px;">
                                    <option value="creating" <?= $p['status']=='creating'?'selected':'' ?>>Creating...</option>
                                    <option value="active" <?= $p['status']=='active'?'selected':'' ?>>Active</option>
                                    <option value="restarting" <?= $p['status']=='restarting'?'selected':'' ?>>Restarting</option>
                                    <option value="suspended" <?= $p['status']=='suspended'?'selected':'' ?>>Suspended</option>
                                    <option value="error" <?= $p['status']=='error'?'selected':'' ?>>Error</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        </td>
                        <td style="color: var(--text-dim); font-size: 13px; font-family: var(--font-mono);">
                            <?php 
                                if (!empty($p['expiry_date'])) {
                                    $expiry = strtotime($p['expiry_date']);
                                    // Highlight if it's expired or expiring soon (within 3 days)
                                    if ($expiry < time()) {
                                        echo '<span style="color: var(--accent-red); font-weight: 600;">' . date('M j, Y', $expiry) . '</span>';
                                    } elseif ($expiry < strtotime('+3 days')) {
                                        echo '<span style="color: var(--accent-orange); font-weight: 600;">' . date('M j, Y', $expiry) . '</span>';
                                    } else {
                                        echo date('M j, Y', $expiry);
                                    }
                                } else {
                                    echo '<span style="opacity: 0.5;">N/A</span>';
                                }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
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

// Bulk Actions Logic
//
// The selected action determines BOTH which endpoint we POST to and the
// payload shape:
//
//   daemon ops (start/stop/restart) → ajax_service_handler.php?id=<id>
//                                       body: ajax_action=<action>&service_type=<be|fe>
//
//   FE create / update / delete     → setup_frontend.php
//                                       body: panel_id=<id>&action=<create|update|delete>
//
//   BE create                       → setup_backend.php
//                                       body: panel_id=<id>
//                                     (BE update/delete aren't implemented as bulk endpoints yet)
//
function runBulk(type) {
    const select = document.getElementById(type === 'be' ? 'bulkBeAction' : 'bulkFeAction');
    const action = select.value;

    if (!action) {
        showToast('error', 'Please select an action from the dropdown first.');
        return;
    }

    const checkboxes = document.querySelectorAll('.panel-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('error', 'Please check at least one panel to apply the bulk action.');
        return;
    }

    // --- Decide endpoint + payload shape -----------------------------------
    const DAEMON_OPS = ['start', 'stop', 'restart'];
    const BUILD_OPS  = ['create', 'update', 'delete'];

    let endpoint = null;
    let buildBody = null;       // function(id) → FormData

    if (DAEMON_OPS.includes(action)) {
        endpoint = (id) => `ajax_service_handler.php?id=${id}`;
        buildBody = (id) => {
            const fd = new FormData();
            fd.append('csrf_token', window.__CSRF__);
            fd.append('ajax_action', action);
            fd.append('service_type', type);
            return fd;
        };
    } else if (type === 'fe' && BUILD_OPS.includes(action)) {
        endpoint = () => `setup_frontend.php`;
        buildBody = (id) => {
            const fd = new FormData();
            fd.append('csrf_token', window.__CSRF__);
            fd.append('panel_id', id);
            fd.append('action', action);
            return fd;
        };
    } else if (type === 'be' && action === 'create') {
        endpoint = () => `setup_backend.php`;
        buildBody = (id) => {
            const fd = new FormData();
            fd.append('csrf_token', window.__CSRF__);
            fd.append('panel_id', id);
            return fd;
        };
    } else {
        // BE update / delete are intentionally per-panel only — running them
        // in bulk wipes /root/somaniOne-main on N hosts simultaneously, which
        // is rarely what an admin actually wants.
        showToast('error', `Bulk ${action.toUpperCase()} isn't available for ${type.toUpperCase()}. Open the panel individually.`);
        return;
    }

    // --- Confirm ------------------------------------------------------------
    let confirmMsg = `Are you sure you want to bulk ${action.toUpperCase()} the ${type.toUpperCase()} service for ${checkboxes.length} panel(s)?`;
    if (BUILD_OPS.includes(action)) {
        const warn = action === 'delete'
            ? `WIPE the working dir + remove the systemd unit on ${checkboxes.length} host(s).`
            : `Build/${action} will run in the background on ${checkboxes.length} remote host(s) concurrently (5–10 min each). Watch each panel's terminal for progress.`;
        confirmMsg = `WARNING — ${warn}\n\nContinue?`;
    }
    if (!confirm(confirmMsg)) return;

    showToast('success', `Dispatching bulk ${action.toUpperCase()} to ${checkboxes.length} panel(s)...`);

    // --- Fan out -----------------------------------------------------------
    window.__CSRF__ = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    const ids = Array.from(checkboxes).map(cb => cb.value);
    let okCount = 0, errCount = 0;
    const promises = [];

    for (const id of ids) {
        const row = document.getElementById('row-' + id);
        if (row) row.style.opacity = '0.4';

        const p = fetch(endpoint(id), {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.__CSRF__ },
            body: buildBody(id)
        })
            .then(async res => {
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (e) { throw new Error("Server returned invalid JSON: " + text.slice(0, 80)); }
                if (!res.ok) throw new Error(data.message || data.error || ("HTTP " + res.status));
                return data;
            })
            .then(data => {
                // Daemon endpoint returns {status: 'success'|'error'};
                // setup_* endpoints return {success: true|false, message}.
                const ok = (data.status === 'success') || (data.success === true);
                if (ok) {
                    if (row) { row.style.opacity = '1'; row.style.borderLeft = '4px solid var(--accent-green)'; }
                    okCount++;
                } else {
                    if (row) { row.style.opacity = '1'; row.style.borderLeft = '4px solid var(--accent-orange)'; }
                    errCount++;
                    console.warn(`Panel ${id}:`, data.message || data.error || 'unknown error');
                }
            })
            .catch(err => {
                console.error(`Panel ${id} Error:`, err);
                if (row) { row.style.opacity = '1'; row.style.borderLeft = '4px solid var(--accent-red)'; }
                errCount++;
            });

        promises.push(p);
    }

    Promise.all(promises).then(() => {
        const verb = BUILD_OPS.includes(action) ? 'kicked off' : 'dispatched';
        if (errCount === 0) {
            showToast('success', `${okCount} panel(s) ${verb}. Reloading in 3s...`);
        } else {
            showToast('error',   `${okCount} ${verb}, ${errCount} failed. Check rows highlighted in red/orange. Reloading in 5s...`);
        }
        setTimeout(() => location.reload(), errCount === 0 ? 3000 : 5000);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Theme Toggle
    const toggle = document.getElementById('adminThemeToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            const body = document.documentElement;
            const currentTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            body.setAttribute('data-theme', currentTheme);
            localStorage.setItem('admin_theme', currentTheme);
        });
    }

    // Master Checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.panel-checkbox').forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>
</body>
</html>
