<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("User fetch error: " . $e->getMessage());
    die("A system error occurred.");
}

$tickets = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.reference_id, t.subject, t.status, t.priority, t.updated_at, d.name AS department
        FROM tickets t
        JOIN support_departments d ON t.department_id = d.id
        WHERE t.user_id = :uid
        ORDER BY t.updated_at DESC
    ");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $tickets = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Tickets fetch error: " . $e->getMessage());
}

function getStatusBadge($status) {
    switch ($status) {
        case 'Open': return '<span class="badge badge-blue">Open</span>';
        case 'In Progress': return '<span class="badge badge-purple">In Progress</span>';
        case 'Awaiting Reply': return '<span class="badge badge-orange">Awaiting Reply</span>';
        case 'Resolved': return '<span class="badge badge-green">Resolved</span>';
        case 'Closed': return '<span class="badge badge-gray">Closed</span>';
        default: return '<span class="badge badge-gray">Unknown</span>';
    }
}

function getPriorityBadge($priority) {
    switch ($priority) {
        case 'Critical': return '<span class="p-dot p-red"></span> Critical';
        case 'High': return '<span class="p-dot p-orange"></span> High';
        case 'Medium': return '<span class="p-dot p-blue"></span> Medium';
        case 'Low': return '<span class="p-dot p-gray"></span> Low';
        default: return '<span class="p-dot p-gray"></span> None';
    }
}

$page_title = 'Support Tickets';
$header_title = 'Support Center';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
    
    .table-controls { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; gap: 16px; }
    .filter-tab { font-size: 14px; font-weight: 500; color: var(--text-muted); text-decoration: none; padding: 6px 12px; border-radius: 6px; transition: all 0.2s; cursor: pointer; }
    .filter-tab:hover { color: var(--text); background: rgba(100,116,139,0.1); }
    .filter-tab.active { background: rgba(59,130,246,0.1); color: var(--accent2); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--border-strong); background: rgba(100,116,139,0.03); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(100,116,139,0.05); cursor: pointer; }

    .t-ref { font-family: var(--font-mono); font-size: 13px; color: var(--text-muted); }
    .t-subject { font-weight: 600; color: var(--text); font-size: 15px; margin-bottom: 4px; display: block; text-decoration: none; }
    .t-subject:hover { color: var(--accent2); }
    .t-dept { font-size: 13px; color: var(--text-dim); }

    /* Badges */
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-blue { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .badge-purple { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .badge-green { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-orange { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-gray { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .p-wrapper { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--text-muted); }
    .p-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .p-red { background: var(--accent-red); box-shadow: 0 0 8px rgba(248,113,113,0.4); }
    .p-orange { background: var(--accent-orange); }
    .p-blue { background: var(--accent2); }
    .p-gray { background: var(--text-dim); }
</style>

<div class="content-area">
    
    <div class="page-header">
      <div>
        <h1 class="page-title">My Tickets</h1>
        <p class="page-sub">View and manage your active support requests.</p>
      </div>
      <a href="new-ticket.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Open New Ticket</a>
    </div>

    <div class="table-container">
      <div class="table-controls" style="overflow-x: auto; white-space: nowrap; padding-bottom: 8px;">
        <a class="filter-tab active" data-filter="all">All Tickets</a>
        <a class="filter-tab" data-filter="Open">Open</a>
        <a class="filter-tab" data-filter="In Progress">In Progress</a>
        <a class="filter-tab" data-filter="Awaiting Reply">Awaiting Reply</a>
        <a class="filter-tab" data-filter="Resolved">Resolved</a>
        <a class="filter-tab" data-filter="Closed">Closed</a>
      </div>
      
      <table>
        <thead>
          <tr>
            <th width="15%">ID</th>
            <th width="40%">Subject</th>
            <th width="15%">Status</th>
            <th width="15%">Priority</th>
            <th width="15%">Last Updated</th>
          </tr>
        </thead>
        <tbody id="tickets-table-body">
          <?php foreach ($tickets as $ticket): ?>
          <tr class="ticket-row" data-status="<?= htmlspecialchars($ticket['status']) ?>" onclick="window.location='view-ticket.php?id=<?= htmlspecialchars($ticket['reference_id']) ?>'">
            <td class="t-ref"><?= htmlspecialchars($ticket['reference_id']) ?></td>
            <td>
              <a href="view-ticket.php?id=<?= htmlspecialchars($ticket['reference_id']) ?>" class="t-subject"><?= htmlspecialchars($ticket['subject']) ?></a>
              <div class="t-dept"><?= htmlspecialchars($ticket['department']) ?></div>
            </td>
            <td><?= getStatusBadge($ticket['status']) ?></td>
            <td><div class="p-wrapper"><?= getPriorityBadge($ticket['priority']) ?></div></td>
            <td style="color: var(--text-muted); font-size: 13px;">
              <?php 
                $time = strtotime($ticket['updated_at']);
                echo (time() - $time < 86400) ? date('g:i A', $time) : date('M j, Y', $time);
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
          
          <tr id="empty-state" style="display: <?= empty($tickets) ? 'table-row' : 'none' ?>;">
            <td colspan="5" style="text-align: center; padding: 48px; color: var(--text-dim);">
              <i class="fa-solid fa-inbox" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
              <span id="empty-state-text">No support tickets found.</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterTabs = document.querySelectorAll('.filter-tab');
    const ticketRows = document.querySelectorAll('.ticket-row');
    const emptyState = document.getElementById('empty-state');
    const emptyStateText = document.getElementById('empty-state-text');

    filterTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Handle active tab state
            filterTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            const filterValue = tab.getAttribute('data-filter');
            let visibleCount = 0;

            // Filter rows based on exact status match
            ticketRows.forEach(row => {
                const status = row.getAttribute('data-status');

                if (filterValue === 'all' || status === filterValue) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Handle empty state messaging
            if (visibleCount === 0) {
                emptyState.style.display = '';
                emptyStateText.textContent = filterValue === 'all' ? 'No support tickets found.' : `No "${filterValue}" tickets found.`;
            } else {
                emptyState.style.display = 'none';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
