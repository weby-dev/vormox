<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    $new_theme = $_POST['theme'] === 'light' ? 'light' : 'dark';
    $updateStmt = $pdo->prepare("UPDATE users SET theme = :theme WHERE id = :id");
    $updateStmt->execute(['theme' => $new_theme, 'id' => $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, last_login, last_ip, theme FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

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
        ORDER BY updated_at DESC LIMIT 2
    ");
    $stmtTickets->execute(['uid' => $_SESSION['user_id']]);
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

  <div class="content-area">
    <div class="welcome-banner">
      <div class="welcome-text">
        <h2>Welcome back, <?= htmlspecialchars($user['first_name']) ?></h2>
        <p>Your control plane is ready. Connect your first Proxmox panel to start automating deployments.</p>
      </div>
      <div>
        <a href="panels.php?action=add" class="btn-primary"><i class="fa-solid fa-plus"></i> Add Panel</a>
      </div>
    </div>

    <div class="dashboard-grid">
      <div class="card">
        <div class="card-label">Active Nodes</div>
        <div class="card-value">0</div>
        <div class="card-sub">Awaiting panel connection</div>
      </div>
      <div class="card">
        <div class="card-label">Running VMs</div>
        <div class="card-value">0</div>
        <div class="card-sub">0 stopped &middot; 0 suspended</div>
      </div>
      <div class="card">
        <div class="card-label">Security & Access</div>
        <div class="card-value" style="font-size: 16px; margin-top: 8px;">
          <div style="margin-bottom: 8px;"><i class="fa-solid fa-clock" style="color: var(--accent2); width: 20px;"></i> Last Login: <?= htmlspecialchars(date('M j, Y', strtotime($user['last_login']))) ?></div>
          <div><i class="fa-solid fa-location-crosshairs" style="color: var(--accent-green); width: 20px;"></i> IP Address: <span style="font-family: var(--font-mono); color: var(--text-muted);"><?= htmlspecialchars($user['last_ip']) ?></span></div>
        </div>
      </div>
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
  </div>

<?php include 'includes/footer.php'; ?>
