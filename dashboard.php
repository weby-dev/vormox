<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    csrf_require();
    $new_theme = $_POST['theme'] === 'light' ? 'light' : 'dark';
    $updateStmt = $pdo->prepare("UPDATE users SET theme = :theme WHERE id = :id");
    $updateStmt->execute(['theme' => $new_theme, 'id' => $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit;
}

try {
    $uid = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, last_login, last_ip, theme, wallet_balance FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $uid]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    // ----- Stats -----
    $statStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(status = 'active'), 0)                                       AS active_panels,
            COALESCE(SUM(CASE WHEN status = 'active' THEN nodes_count ELSE 0 END), 0) AS total_nodes,
            COALESCE(SUM(status = 'suspended'), 0)                                    AS suspended_count,
            COALESCE(SUM(pending_nodes_count IS NOT NULL), 0)                         AS pending_upgrades,
            COALESCE(SUM(
                status = 'active'
                AND expiry_date IS NOT NULL
                AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            ), 0) AS expiring_soon
        FROM user_panels
        WHERE user_id = :uid
    ");
    $statStmt->execute(['uid' => $uid]);
    $stats = $statStmt->fetch();

    $invStmt = $pdo->prepare("
        SELECT
            COUNT(*)                AS unpaid_count,
            COALESCE(SUM(amount),0) AS unpaid_total,
            COALESCE(SUM(due_date < CURDATE()), 0) AS overdue_count
        FROM invoices
        WHERE user_id = :uid AND status = 'Unpaid'
    ");
    $invStmt->execute(['uid' => $uid]);
    $invSummary = $invStmt->fetch();

    // Soonest-expiring active panel (for the alert details)
    $nextExpStmt = $pdo->prepare("
        SELECT domain, expiry_date
        FROM user_panels
        WHERE user_id = :uid AND status = 'active' AND expiry_date IS NOT NULL
          AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        ORDER BY expiry_date ASC
        LIMIT 1
    ");
    $nextExpStmt->execute(['uid' => $uid]);
    $nextExpiring = $nextExpStmt->fetch();

    // ----- Content -----
    $stmtUpdates = $pdo->prepare("
        SELECT u.id, u.version_tag, u.title, u.content, u.published_at, c.name AS category_name, c.tag_class
        FROM system_updates u
        JOIN update_categories c ON u.category_id = c.id
        WHERE u.is_published = 1
        ORDER BY u.published_at DESC LIMIT 10
    ");
    $stmtUpdates->execute();
    $updates = $stmtUpdates->fetchAll();

    $stmtTickets = $pdo->prepare("
        SELECT reference_id, subject, status, updated_at
        FROM tickets
        WHERE user_id = :uid
        ORDER BY updated_at DESC LIMIT 4
    ");
    $stmtTickets->execute(['uid' => $uid]);
    $recent_tickets = $stmtTickets->fetchAll();

} catch (PDOException $e) {
    die("An error occurred while loading your dashboard.");
}

function getStatusBadge($status) {
    switch ($status) {
        case 'Open': return '<span class="tag tag-open">Open</span>';
        case 'In Progress': return '<span class="tag" style="background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2);">In Progress</span>';
        case 'Awaiting Reply': return '<span class="tag" style="background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2);">Awaiting Reply</span>';
        case 'Resolved': return '<span class="tag" style="background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2);">Resolved</span>';
        case 'Closed': return '<span class="tag tag-closed">Closed</span>';
        default: return '<span class="tag tag-closed">Unknown</span>';
    }
}

$page_title = 'Overview';
$header_title = 'Control Panel';

include 'includes/header.php'; 
?>

  <style>
    /* Dashboard-specific additions */
    .stat-card { text-decoration: none; color: inherit; transition: transform 0.15s, border-color 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-2px); border-color: var(--border-strong); box-shadow: 0 8px 24px rgba(0,0,0,0.18); }
    .stat-card .card-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px; color: var(--text-dim); }
    .stat-card .card-foot .arrow { color: var(--accent2); }
    .stat-card.accent-green .card-value { color: var(--accent-green); }
    .stat-card.accent-orange .card-value { color: var(--accent-orange); }
    .stat-card.accent-red .card-value { color: var(--accent-red); }

    .alert-stack { display: flex; flex-direction: column; gap: 10px; margin-top: 24px; }
    .alert-row {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 18px; border-radius: 10px;
        font-size: 14px; text-decoration: none; transition: filter 0.2s;
    }
    .alert-row:hover { filter: brightness(1.1); }
    .alert-row .alert-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .alert-row .alert-body { flex: 1; }
    .alert-row .alert-title { font-weight: 600; color: var(--text); margin-bottom: 2px; }
    .alert-row .alert-sub { font-size: 12px; color: var(--text-muted); }
    .alert-row .alert-cta { color: var(--text-muted); font-size: 13px; font-weight: 600; }
    .alert-warn  { background: rgba(251,146,60,0.08); border: 1px solid rgba(251,146,60,0.25); }
    .alert-warn .alert-icon { background: rgba(251,146,60,0.15); color: var(--accent-orange); }
    .alert-danger { background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.25); }
    .alert-danger .alert-icon { background: rgba(248,113,113,0.15); color: var(--accent-red); }
    .alert-info { background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.25); }
    .alert-info .alert-icon { background: rgba(59,130,246,0.15); color: var(--accent2); }

    .security-strip {
        display: flex; gap: 24px; flex-wrap: wrap; align-items: center;
        margin-top: 24px; padding: 14px 20px;
        background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
        font-size: 13px; color: var(--text-muted);
    }
    .security-strip .sec-item { display: flex; align-items: center; gap: 8px; }
    .security-strip i { color: var(--accent2); }
  </style>

  <div class="content-area">
    <div class="welcome-banner">
      <div class="welcome-text">
        <h2>Welcome back, <?= htmlspecialchars($user['first_name']) ?></h2>
        <p>
          <?php if ((int)$stats['active_panels'] === 0): ?>
            Your control plane is ready. Deploy your first Proxmox panel to start automating.
          <?php else: ?>
            <?= (int)$stats['active_panels'] ?> active panel<?= $stats['active_panels'] == 1 ? '' : 's' ?> running <?= (int)$stats['total_nodes'] ?> node<?= $stats['total_nodes'] == 1 ? '' : 's' ?>.
          <?php endif; ?>
        </p>
      </div>
      <div>
        <a href="panels.php?action=add" class="btn-primary"><i class="fa-solid fa-plus"></i> Add Panel</a>
      </div>
    </div>

    <?php
      $hasAlerts = ($stats['expiring_soon'] > 0)
                || ($stats['suspended_count'] > 0)
                || ($invSummary['unpaid_count'] > 0)
                || ($stats['pending_upgrades'] > 0);
    ?>
    <?php if ($hasAlerts): ?>
      <div class="alert-stack">

        <?php if ($invSummary['overdue_count'] > 0): ?>
          <a href="invoices.php" class="alert-row alert-danger">
            <div class="alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="alert-body">
              <div class="alert-title"><?= (int)$invSummary['overdue_count'] ?> overdue invoice<?= $invSummary['overdue_count'] == 1 ? '' : 's' ?></div>
              <div class="alert-sub">Pay now to avoid service suspension.</div>
            </div>
            <div class="alert-cta">View &rarr;</div>
          </a>
        <?php endif; ?>

        <?php if ($stats['suspended_count'] > 0): ?>
          <a href="panels.php?status=suspended" class="alert-row alert-danger">
            <div class="alert-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="alert-body">
              <div class="alert-title"><?= (int)$stats['suspended_count'] ?> suspended panel<?= $stats['suspended_count'] == 1 ? '' : 's' ?></div>
              <div class="alert-sub">Settle the outstanding invoice to reactivate.</div>
            </div>
            <div class="alert-cta">Review &rarr;</div>
          </a>
        <?php endif; ?>

        <?php if ($stats['expiring_soon'] > 0 && $nextExpiring): ?>
          <?php
            $daysLeft = max(0, (int) ceil((strtotime($nextExpiring['expiry_date']) - time()) / 86400));
          ?>
          <a href="panels.php" class="alert-row alert-warn">
            <div class="alert-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="alert-body">
              <div class="alert-title"><?= (int)$stats['expiring_soon'] ?> panel<?= $stats['expiring_soon'] == 1 ? '' : 's' ?> expiring within 7 days</div>
              <div class="alert-sub">Soonest: <strong><?= htmlspecialchars($nextExpiring['domain']) ?></strong> in <?= $daysLeft ?> day<?= $daysLeft == 1 ? '' : 's' ?>.</div>
            </div>
            <div class="alert-cta">Renew &rarr;</div>
          </a>
        <?php endif; ?>

        <?php if ($stats['pending_upgrades'] > 0): ?>
          <a href="invoices.php" class="alert-row alert-info">
            <div class="alert-icon"><i class="fa-solid fa-arrow-up-right-dots"></i></div>
            <div class="alert-body">
              <div class="alert-title"><?= (int)$stats['pending_upgrades'] ?> plan upgrade<?= $stats['pending_upgrades'] == 1 ? '' : 's' ?> awaiting payment</div>
              <div class="alert-sub">Nodes will be added as soon as the invoice is paid.</div>
            </div>
            <div class="alert-cta">Pay &rarr;</div>
          </a>
        <?php endif; ?>

        <?php if ($invSummary['unpaid_count'] > 0 && $invSummary['overdue_count'] == 0): ?>
          <a href="invoices.php" class="alert-row alert-info">
            <div class="alert-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div class="alert-body">
              <div class="alert-title"><?= (int)$invSummary['unpaid_count'] ?> unpaid invoice<?= $invSummary['unpaid_count'] == 1 ? '' : 's' ?> &middot; $<?= number_format($invSummary['unpaid_total'], 2) ?> due</div>
              <div class="alert-sub">Pay from your wallet or via UPI.</div>
            </div>
            <div class="alert-cta">View &rarr;</div>
          </a>
        <?php endif; ?>

      </div>
    <?php endif; ?>

    <div class="dashboard-grid">

      <a href="billing.php" class="card stat-card accent-green">
        <div class="card-label"><i class="fa-solid fa-wallet" style="margin-right: 6px;"></i> Wallet Balance</div>
        <div class="card-value">$<?= number_format((float)$user['wallet_balance'], 2) ?></div>
        <div class="card-foot">
          <span>USD available</span>
          <span class="arrow">Add funds &rarr;</span>
        </div>
      </a>

      <a href="panels.php?status=active" class="card stat-card">
        <div class="card-label"><i class="fa-solid fa-server" style="margin-right: 6px;"></i> Active Panels</div>
        <div class="card-value"><?= (int)$stats['active_panels'] ?></div>
        <div class="card-foot">
          <span><?= (int)$stats['total_nodes'] ?> node<?= $stats['total_nodes'] == 1 ? '' : 's' ?> running</span>
          <span class="arrow">Manage &rarr;</span>
        </div>
      </a>

      <a href="panels.php" class="card stat-card<?= $stats['suspended_count'] > 0 ? ' accent-red' : '' ?>">
        <div class="card-label"><i class="fa-solid fa-network-wired" style="margin-right: 6px;"></i> Suspended</div>
        <div class="card-value"><?= (int)$stats['suspended_count'] ?></div>
        <div class="card-foot">
          <span><?= $stats['suspended_count'] > 0 ? 'Needs attention' : 'All good' ?></span>
          <span class="arrow">View all &rarr;</span>
        </div>
      </a>

      <a href="invoices.php" class="card stat-card<?= $invSummary['overdue_count'] > 0 ? ' accent-red' : ($invSummary['unpaid_count'] > 0 ? ' accent-orange' : '') ?>">
        <div class="card-label"><i class="fa-solid fa-file-invoice-dollar" style="margin-right: 6px;"></i> Amount Due</div>
        <div class="card-value">$<?= number_format((float)$invSummary['unpaid_total'], 2) ?></div>
        <div class="card-foot">
          <span><?= (int)$invSummary['unpaid_count'] ?> unpaid<?= $invSummary['overdue_count'] > 0 ? ' &middot; ' . (int)$invSummary['overdue_count'] . ' overdue' : '' ?></span>
          <span class="arrow">Pay &rarr;</span>
        </div>
      </a>

    </div>

    <div class="split-grid">
      
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-bolt" style="color: var(--accent2); margin-right: 8px;"></i> System Updates</div>
          <a href="updates.php" class="btn-ghost">View All</a>
        </div>
        <div class="list-group">
          <?php if (empty($updates)): ?>
            <div class="list-item" style="justify-content: center; color: var(--text-muted);">No recent updates available.</div>
          <?php else: ?>
            <?php foreach ($updates as $update): ?>
              <div class="list-item" style="cursor: pointer;" onclick="window.location='view-update.php?id=<?= htmlspecialchars($update['id']) ?>'">
                <div class="list-icon"><i class="fa-solid fa-code-merge"></i></div>
                <div class="list-content">
                  <div class="list-title"><?= htmlspecialchars($update['title']) ?></div>
                  <div class="list-desc"><?= htmlspecialchars(substr($update['content'], 0, 80)) ?>...</div>
                </div>
                <div class="list-meta">
                  <span class="tag" style="border: 1px solid var(--border); background: var(--surface2); color: var(--text);"><?= htmlspecialchars($update['category_name']) ?></span>
                  <span class="list-time"><?= date('M j', strtotime($update['published_at'])) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-ticket" style="color: var(--accent-green); margin-right: 8px;"></i> Recent Tickets</div>
          <a href="new-ticket.php" class="btn-ghost"><i class="fa-solid fa-plus"></i> New Ticket</a>
        </div>
        <div class="list-group">
          <?php if (empty($recent_tickets)): ?>
            <div class="list-item" style="justify-content: center; color: var(--text-muted);">No open tickets.</div>
          <?php else: ?>
            <?php foreach ($recent_tickets as $ticket): ?>
              <div class="list-item" style="cursor: pointer;" onclick="window.location='view-ticket.php?id=<?= htmlspecialchars($ticket['reference_id']) ?>'">
                <div class="list-content">
                  <div class="list-title"><?= htmlspecialchars($ticket['subject']) ?></div>
                  <div class="list-desc">Ticket #<?= htmlspecialchars($ticket['reference_id']) ?></div>
                </div>
                <div class="list-meta">
                  <?= getStatusBadge($ticket['status']) ?>
                  <span class="list-time">
                    <?php 
                      $time = strtotime($ticket['updated_at']);
                      echo (time() - $time < 86400) ? date('g:i A', $time) : date('M j', $time);
                    ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <div class="security-strip">
      <div class="sec-item">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Security signal:</span>
      </div>
      <div class="sec-item">
        <i class="fa-solid fa-clock"></i>
        <span>Last login <strong style="color: var(--text);"><?= $user['last_login'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($user['last_login']))) : 'never' ?></strong></span>
      </div>
      <div class="sec-item">
        <i class="fa-solid fa-location-crosshairs"></i>
        <span>From IP <strong style="color: var(--text); font-family: var(--font-mono);"><?= htmlspecialchars($user['last_ip'] ?? '—') ?></strong></span>
      </div>
      <a href="profile.php" style="margin-left: auto; color: var(--accent2); text-decoration: none; font-weight: 600; font-size: 13px;">Manage account &rarr;</a>
    </div>
  </div>

<?php include 'includes/footer.php'; ?>
