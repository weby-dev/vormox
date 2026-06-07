<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php';

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_autorenew') {
    $panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
    $auto_renew = filter_input(INPUT_POST, 'auto_renew', FILTER_VALIDATE_INT);

    if ($panel_id && ($auto_renew === 0 || $auto_renew === 1)) {
        try {
            $stmt = $pdo->prepare("UPDATE user_panels SET auto_renew = :renew WHERE id = :pid AND user_id = :uid");
            $stmt->execute(['renew' => $auto_renew, 'pid' => $panel_id, 'uid' => $user_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_panel'])) {
    $domain = trim(filter_input(INPUT_POST, 'domain', FILTER_SANITIZE_URL));
    $nodes = filter_input(INPUT_POST, 'nodes_count', FILTER_VALIDATE_INT);
    $billing_cycle = filter_input(INPUT_POST, 'billing_cycle', FILTER_SANITIZE_SPECIAL_CHARS);

    $cycle_months = [ 'monthly' => 1, 'quarterly' => 3, 'semi_annually' => 6, 'annually' => 12 ];
    $months = $cycle_months[$billing_cycle] ?? 0;

    if (empty($domain) || !$nodes || !$months) {
        $error = "Please provide valid inputs for all fields.";
    } elseif ($nodes > 25) {
        $error = "For more than 25 nodes, please contact our enterprise support team.";
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM user_panels WHERE domain = :domain AND status IN ('payment_pending', 'pending', 'creating', 'active', 'restarting', 'suspended', 'error') LIMIT 1");
        $checkStmt->execute(['domain' => $domain]);
        
        if ($checkStmt->fetch()) {
            $error = "The domain '$domain' is already in use by an active panel.";
        } else {
            $price_per_node = ($nodes >= 11) ? 8 : (($nodes >= 5) ? 9 : 10);
            $total_price = ($nodes * $price_per_node) * $months;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO user_panels (user_id, domain, nodes_count, billing_cycle, total_price, status, auto_renew) VALUES (:uid, :dom, :nodes, :cycle, :total, 'payment_pending', 1)");
                $stmt->execute(['uid' => $user_id, 'dom' => $domain, 'nodes' => $nodes, 'cycle' => $billing_cycle, 'total' => $total_price]);
                
                $new_panel_id = $pdo->lastInsertId();

                $invoice_number = 'INV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $due_date = date('Y-m-d', strtotime('+3 days'));
                
                $period_start = date('Y-m-d');
                $period_end = date('Y-m-d', strtotime("+$months months"));

                $invStmt = $pdo->prepare("
                    INSERT INTO invoices (user_id, panel_id, invoice_number, amount, status, due_date, period_start, period_end, created_at) 
                    VALUES (:uid, :pid, :inv_num, :amount, 'Unpaid', :due, :p_start, :p_end, NOW())
                ");
                $invStmt->execute([
                    'uid' => $user_id, 
                    'pid' => $new_panel_id, 
                    'inv_num' => $invoice_number, 
                    'amount' => $total_price, 
                    'due' => $due_date,
                    'p_start' => $period_start,
                    'p_end' => $period_end
                ]);

                $pdo->commit();
                $success = "Panel requested and Invoice #$invoice_number generated successfully.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "An error occurred while provisioning your panel.";
            }
        }
    }
}

$filter_status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : 'hide_terminated';
$search_query = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM user_panels WHERE user_id = :id";
$params = ['id' => $user_id];

if ($filter_status === 'hide_terminated') {
    $sql .= " AND status != 'terminated'";
} elseif ($filter_status === 'active') {
    $sql .= " AND status IN ('active', 'restarting')";
} elseif ($filter_status === 'pending') {
    $sql .= " AND status IN ('pending', 'payment_pending', 'creating')";
} elseif ($filter_status === 'suspended') {
    $sql .= " AND status = 'suspended'";
}

if (!empty($search_query)) {
    $sql .= " AND domain LIKE :search";
    $params['search'] = '%' . $search_query . '%';
}

$sql .= " ORDER BY created_at DESC";

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $user_id]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    $panelsStmt = $pdo->prepare($sql);
    $panelsStmt->execute($params);
    $panels = $panelsStmt->fetchAll();

} catch (PDOException $e) {
    die("A system error occurred.");
}

$page_title = 'Control Panels';
$header_title = 'Infrastructure';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .filter-bar { 
        display: flex; 
        gap: 16px; 
        margin-bottom: 24px; 
        align-items: center; 
        justify-content: space-between; 
        background: var(--surface); 
        padding: 16px 20px; 
        border: 1px solid var(--border); 
        border-radius: 12px; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.05); 
    }
    .search-wrapper { 
        display: flex; 
        align-items: center; 
        flex: 1; 
        min-width: 280px; 
        background: var(--bg2); 
        border: 1px solid var(--border-strong); 
        border-radius: 8px; 
        padding: 0 16px; 
        height: 46px;
        box-sizing: border-box;
        transition: all 0.2s; 
    }
    .search-wrapper:focus-within { 
        border-color: var(--accent); 
        box-shadow: 0 0 0 3px var(--accent-glow); 
        background: var(--bg); 
    }
    .search-wrapper i { 
        color: var(--text-dim); 
        font-size: 14px;
        flex-shrink: 0;
    }
    .search-wrapper .search-input { 
        flex: 1; 
        width: auto;
        height: 100%; 
        margin: 0;
        padding: 0 12px; 
        background: transparent !important; 
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        color: var(--text); 
        outline: none; 
        font-family: var(--font-body); 
        font-size: 14px;
        line-height: 1;
    }
    .search-wrapper .search-input:focus {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
    }
    .search-wrapper .search-input::placeholder { color: var(--text-muted); }
    
    .filter-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .filter-select { 
        height: 46px;
        box-sizing: border-box;
        padding: 0 40px 0 16px; 
        background: var(--bg2); 
        border: 1px solid var(--border-strong); 
        border-radius: 8px; 
        color: var(--text); 
        font-family: var(--font-body); 
        font-size: 14px; 
        font-weight: 500; 
        outline: none; 
        cursor: pointer; 
        appearance: none; 
        background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%237a8aa8%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
        background-repeat: no-repeat;
        background-position: right 16px top 50%;
        background-size: 10px auto;
    }
    .filter-select:focus { border-color: var(--accent); background-color: var(--bg); }
    
    .btn-filter { 
        height: 46px;
        box-sizing: border-box;
        background: var(--accent2); 
        color: #fff; 
        padding: 0 24px; 
        border-radius: 8px; 
        font-size: 14px; 
        font-family: var(--font-body); 
        font-weight: 600; 
        border: none; 
        cursor: pointer; 
        display: inline-flex; 
        align-items: center; 
        gap: 8px; 
        transition: 0.2s;
        box-shadow: 0 4px 15px var(--accent-glow);
    }
    .btn-filter:hover { filter: brightness(1.1); transform: translateY(-1px); }

    @media (max-width: 850px) {
        .filter-bar { flex-direction: column; align-items: stretch; }
        .filter-actions { flex-direction: column; align-items: stretch; }
        .search-wrapper { min-width: 100%; }
        .filter-select { width: 100%; }
        .btn-filter { justify-content: center; }
    }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    
    .toolbar-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.1); }
    .toolbar { display: flex; gap: 12px; align-items: center; background: var(--bg2); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); }
    .status-select { padding: 6px 12px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 6px; color: var(--text); font-family: var(--font-mono); font-size: 12px; outline: none; }
    .btn-outline { background: transparent; border: 1px solid var(--border-strong); color: var(--text); padding: 6px 12px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-size: 12px; font-weight: 600; }
    .btn-outline:hover { background: var(--surface2); border-color: var(--accent); color: var(--accent); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-strong); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(139,92,246,0.02); }

    input[type="checkbox"] { appearance: none; width: 18px; height: 18px; border: 2px solid var(--border-strong); border-radius: 4px; background: var(--bg); cursor: pointer; position: relative; transition: 0.2s; }
    input[type="checkbox"]:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"]:checked::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-payment_pending { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-pending { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .badge-creating { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-restarting { background: rgba(251,191,36,0.1); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }
    .badge-suspended, .badge-terminated { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--surface2); border: 1px solid var(--border-strong); transition: .3s; border-radius: 24px; }
    .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: var(--text-muted); transition: .3s; border-radius: 50%; }
    input:checked + .slider { background-color: rgba(34,211,238,0.15); border-color: rgba(34,211,238,0.3); }
    input:checked + .slider:before { background-color: var(--accent-green); transform: translateX(20px); }
    input:disabled + .slider { opacity: 0.5; cursor: not-allowed; }

    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
    .modal-overlay.active { display: flex; opacity: 1; }
    .modal-content { background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); transform: translateY(20px); transition: transform 0.2s; }
    .modal-overlay.active .modal-content { transform: translateY(0); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .modal-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    .btn-close { background: transparent; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; transition: color 0.2s; }
    .btn-close:hover { color: var(--text); }

    .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
    label.field-label { font-size: 12px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; }
    input[type="text"], input[type="number"], select { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 14px; transition: all 0.2s; outline: none; appearance: none; }
    input:focus, select:focus { border-color: var(--accent); background: var(--bg); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    .select-wrapper { position: relative; }
    .select-wrapper::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
    select option { background: var(--surface2); color: var(--text); padding: 12px; }

    .pricing-preview { background: rgba(59,130,246,0.05); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px; padding: 20px; margin-top: 8px; display: flex; justify-content: space-between; align-items: center; }
    .pricing-label { font-size: 14px; color: var(--text-muted); font-weight: 500; }
    .pricing-value { font-family: var(--font-head); font-size: 28px; font-weight: 700; color: var(--accent2); }
    .pricing-sub { font-size: 12px; color: var(--text-dim); margin-top: 4px; }
</style>

<div class="content-area">
    
    <div class="page-header">
        <div>
            <h1 class="page-title">Panels</h1>
            <p class="page-sub">Deploy and manage your Proxmox control interfaces.</p>
        </div>
        <button id="openModalBtn" class="btn-primary"><i class="fa-solid fa-plus"></i> Add Panel</button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="GET" action="panels.php" class="filter-bar">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" placeholder="Search by domain name..." value="<?= htmlspecialchars($search_query) ?>" class="search-input">
        </div>
        
        <div class="filter-actions">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="hide_terminated" <?= $filter_status == 'hide_terminated' ? 'selected' : '' ?>>Hide Terminated</option>
                <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="pending" <?= $filter_status == 'pending' ? 'selected' : '' ?>>Pending & Processing</option>
                <option value="suspended" <?= $filter_status == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>Show All (Including Terminated)</option>
            </select>
            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply</button>
        </div>
    </form>

    <div class="table-container">
        <div class="toolbar-header">
            <div style="font-weight: 600; color: var(--text);"><i class="fa-solid fa-server" style="color: var(--accent); margin-right: 8px;"></i> Your Infrastructure</div>
            
            <div class="toolbar">
                <div style="display: flex; gap: 6px; align-items: center; border-right: 1px solid var(--border-strong); padding-right: 12px;">
                    <select id="bulkBeAction" class="status-select">
                        <option value="">-- Backend --</option>
                        <option value="start">Start</option>
                        <option value="stop">Stop</option>
                        <option value="restart">Restart</option>
                    </select>
                    <button class="btn-outline" type="button" onclick="runBulk('be')"><i class="fa-solid fa-bolt"></i> Run</button>
                </div>
                <div style="display: flex; gap: 6px; align-items: center; padding-left: 6px;">
                    <select id="bulkFeAction" class="status-select">
                        <option value="">-- Frontend --</option>
                        <option value="start">Start</option>
                        <option value="stop">Stop</option>
                        <option value="restart">Restart</option>
                    </select>
                    <button class="btn-outline" type="button" onclick="runBulk('fe')"><i class="fa-solid fa-bolt"></i> Run</button>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%"><input type="checkbox" id="selectAllPanels" title="Select All"></th>
                    <th width="20%">Domain</th>
                    <th width="10%">Nodes</th>
                    <th width="10%">Cycle</th>
                    <th width="10%">Status</th>
                    <th width="15%">Expiry Date</th>
                    <th width="10%" style="text-align: center;">Auto Renew</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($panels as $panel): ?>
                <tr>
                    <td>
                        <?php if($panel['status'] == 'active' || $panel['status'] == 'suspended'): ?>
                            <input type="checkbox" value="<?= $panel['id'] ?>" class="panel-checkbox">
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 600;">
                        <?php if($panel['status'] === 'active' || $panel['status'] === 'suspended'): ?>
                            <a href="manage_panel.php?id=<?= $panel['id'] ?>" style="color: var(--accent2); text-decoration: none; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-link" style="font-size: 12px; color: var(--text-muted);"></i> <?= htmlspecialchars($panel['domain']) ?>
                            </a>
                        <?php else: ?>
                            <span style="<?= $panel['status'] == 'terminated' ? 'text-decoration: line-through; color: var(--text-dim);' : '' ?>">
                                <?= htmlspecialchars($panel['domain']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($panel['nodes_count']) ?></td>
                    <td style="text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', '-', $panel['billing_cycle'])) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($panel['status']) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $panel['status'])) ?>
                        </span>
                    </td>
                    <td style="font-family: var(--font-mono); font-size: 13px;">
                        <?php 
                            if (!empty($panel['expiry_date'])) {
                                $exp = strtotime($panel['expiry_date']);
                                $color = ($exp < time()) ? 'var(--accent-red)' : 'var(--text)';
                                echo "<span style='color: $color;'>" . date('M j, Y', $exp) . "</span>";
                            } else {
                                echo "<span style='color: var(--text-muted);'>Pending</span>";
                            }
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   class="autorenew-toggle" 
                                   data-id="<?= htmlspecialchars($panel['id']) ?>" 
                                   <?= $panel['auto_renew'] == 1 ? 'checked' : '' ?>
                                   <?= in_array($panel['status'], ['suspended', 'terminated']) ? 'disabled' : '' ?> >
                            <span class="slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($panels)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">
                        <i class="fa-solid fa-server" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
                        No panels found matching your filter.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<div id="addPanelModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">Configure New Panel</div>
            <button id="closeModalBtn" class="btn-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <form method="POST" action="panels.php">
            <div class="form-group">
                <label class="field-label" for="domain">Domain Name</label>
                <input type="text" id="domain" name="domain" required placeholder="panel.yourdomain.com" autocomplete="off">
                <div id="domainFeedback" style="display: none; margin-top: 8px; font-size: 12px; font-weight: 600;"></div>
            </div>

            <div class="form-group">
                <label class="field-label" for="nodes_count">Number of Nodes</label>
                <input type="number" id="nodes_count" name="nodes_count" required min="1" value="1">
            </div>

            <div class="form-group">
                <label class="field-label" for="billing_cycle">Billing Cycle</label>
                <div class="select-wrapper">
                    <select id="billing_cycle" name="billing_cycle" required>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="semi_annually">Semi-Annually</option>
                        <option value="annually">Annually</option>
                    </select>
                </div>
            </div>

            <div class="pricing-preview">
                <div>
                    <div class="pricing-label">Estimated Total</div>
                    <div id="pricingDesc" class="pricing-sub">$10.00 / node / month</div>
                </div>
                <div id="priceDisplay" class="pricing-value">$10.00</div>
            </div>

            <div style="margin-top: 32px; display: flex; gap: 16px; justify-content: flex-end;">
                <button type="button" id="cancelModalBtn" class="btn-ghost" style="padding: 12px 24px; border: none; background: transparent; color: var(--text); cursor: pointer;">Cancel</button>
                <button type="submit" id="submitBtn" name="add_panel" class="btn-primary" style="padding: 12px 24px; border: none; border-radius: 8px; background: var(--accent2); color: white; cursor: pointer; font-weight: 600;">Deploy Panel</button>
            </div>
        </form>
    </div>
</div>

<script>
function runBulk(type) {
    const select = document.getElementById(type === 'be' ? 'bulkBeAction' : 'bulkFeAction');
    const action = select.value;
    
    if (!action) { alert('Please select an action from the dropdown first.'); return; }

    const checkboxes = document.querySelectorAll('.panel-checkbox:checked');
    if (checkboxes.length === 0) { alert('Please check at least one panel.'); return; }

    if (!confirm(`Are you sure you want to bulk ${action.toUpperCase()} the ${type.toUpperCase()} service for ${checkboxes.length} panel(s)?`)) return;

    const ids = Array.from(checkboxes).map(cb => cb.value);
    let promises = [];

    for (const id of ids) {
        const formData = new FormData();
        formData.append('ajax_action', action);
        formData.append('service_type', type);
        
        let p = fetch(`ajax_service_handler.php?id=${id}`, { method: 'POST', body: formData });
        promises.push(p);
    }

    Promise.all(promises).then(() => {
        alert('All commands dispatched successfully!');
        location.reload();
    }).catch(err => {
        alert('Some commands may have failed. Please check individually.');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const selectAllPanels = document.getElementById('selectAllPanels');
    if (selectAllPanels) {
        selectAllPanels.addEventListener('change', function() {
            document.querySelectorAll('.panel-checkbox').forEach(cb => cb.checked = this.checked);
        });
    }

    const toggles = document.querySelectorAll('.autorenew-toggle');
    toggles.forEach(toggle => {
        toggle.addEventListener('change', async function() {
            const panelId = this.getAttribute('data-id');
            const isChecked = this.checked ? 1 : 0;
            const originalState = !this.checked;
            try {
                const formData = new URLSearchParams();
                formData.append('action', 'toggle_autorenew');
                formData.append('panel_id', panelId);
                formData.append('auto_renew', isChecked);
                const response = await fetch('panels.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData });
                const data = await response.json();
                if (!data.success) throw new Error('Failed');
            } catch (error) {
                this.checked = originalState;
                alert('Failed to update Auto-Renew status.');
            }
        });
    });

    const modal = document.getElementById('addPanelModal');
    const openBtn = document.getElementById('openModalBtn');
    const closeBtn = document.getElementById('closeModalBtn');
    const cancelBtn = document.getElementById('cancelModalBtn');
    
    const nodesInput = document.getElementById('nodes_count');
    const cycleInput = document.getElementById('billing_cycle');
    const priceDisplay = document.getElementById('priceDisplay');
    const pricingDesc = document.getElementById('pricingDesc');
    const submitBtn = document.getElementById('submitBtn');
    
    const domainInput = document.getElementById('domain');
    const domainFeedback = document.getElementById('domainFeedback');
    let domainTimeout;
    let isDomainValid = false;

    function openModal() { modal.classList.add('active'); calculatePrice(); }
    function closeModal() { modal.classList.remove('active'); }

    function calculatePrice() {
        const nodes = parseInt(nodesInput.value) || 0;
        const cycle = cycleInput.value;
        let months = 1;
        
        if (cycle === 'quarterly') months = 3;
        else if (cycle === 'semi_annually') months = 6;
        else if (cycle === 'annually') months = 12;
        
        let pricePerNode = 0;

        if (nodes <= 0) {
            priceDisplay.textContent = '$0.00';
            pricingDesc.textContent = 'Invalid node count';
            submitBtn.disabled = true; submitBtn.style.opacity = '0.5';
            return;
        }

        if (nodes >= 1 && nodes <= 4) pricePerNode = 10;
        else if (nodes >= 5 && nodes <= 10) pricePerNode = 9;
        else if (nodes >= 11 && nodes <= 25) pricePerNode = 8;

        if (nodes > 25) {
            priceDisplay.textContent = 'Custom';
            pricingDesc.textContent = 'Please contact support';
            submitBtn.disabled = true; submitBtn.style.opacity = '0.5';
        } else {
            const total = (nodes * pricePerNode) * months;
            priceDisplay.textContent = '$' + total.toFixed(2);
            pricingDesc.textContent = '$' + pricePerNode.toFixed(2) + ' / node / month';
            
            const dVal = domainInput.value.trim();
            if (dVal.length > 0 && !isDomainValid) {
                submitBtn.disabled = true; submitBtn.style.opacity = '0.5';
            } else {
                submitBtn.disabled = false; submitBtn.style.opacity = '1';
            }
        }
    }

    if(openBtn) openBtn.addEventListener('click', openModal);
    if(closeBtn) closeBtn.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    if(nodesInput) nodesInput.addEventListener('input', calculatePrice);
    if(cycleInput) cycleInput.addEventListener('change', calculatePrice);

    if (domainInput) {
        domainInput.addEventListener('input', function() {
            clearTimeout(domainTimeout);
            const domain = this.value.trim();

            if (domain.length === 0) {
                domainFeedback.style.display = 'none';
                domainInput.style.borderColor = 'var(--border-strong)';
                isDomainValid = false; calculatePrice(); return;
            }

            domainFeedback.style.display = 'block';
            domainFeedback.style.color = 'var(--text-muted)';
            domainFeedback.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking availability...';
            isDomainValid = false; submitBtn.disabled = true; submitBtn.style.opacity = '0.5';

            domainTimeout = setTimeout(() => {
                fetch(`check_domain.php?domain=${encodeURIComponent(domain)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.exists) {
                            domainFeedback.style.color = 'var(--accent-red)';
                            domainFeedback.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Domain is already in use!';
                            isDomainValid = false; submitBtn.disabled = true; domainInput.style.borderColor = 'var(--accent-red)';
                        } else {
                            domainFeedback.style.color = 'var(--accent-green)';
                            domainFeedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> Domain is available!';
                            isDomainValid = true; domainInput.style.borderColor = 'var(--accent-green)'; calculatePrice();
                        }
                    })
                    .catch(err => {
                        domainFeedback.style.color = 'var(--accent-orange)';
                        domainFeedback.innerHTML = 'Error checking availability.';
                        isDomainValid = false;
                    });
            }, 600);
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'add') {
        setTimeout(() => {
            openModal();
            window.history.replaceState({}, document.title, "panels.php");
        }, 50);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
