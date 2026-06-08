<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$success = '';
$error   = '';

// ---------------------------------------------------------------------------
// Bulk Close — closes every selected ticket that belongs to this user and
// isn't already Closed. Validates each id, scopes the UPDATE by user_id so
// nobody can mass-close someone else's tickets.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_close'])) {
    csrf_require();

    $raw = $_POST['selected_tickets'] ?? [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw))));

    if (empty($ids)) {
        $error = "Select at least one ticket to close.";
    } else {
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                UPDATE tickets
                   SET status = 'Closed', updated_at = NOW()
                 WHERE user_id = ?
                   AND status <> 'Closed'
                   AND id IN ($place)
            ");
            $stmt->execute(array_merge([$_SESSION['user_id']], $ids));
            $affected = $stmt->rowCount();
            $success  = $affected > 0
                ? "Closed {$affected} ticket" . ($affected === 1 ? '' : 's') . "."
                : "Nothing changed — those tickets were already closed or not yours.";
        } catch (PDOException $e) {
            error_log("Bulk close failed: " . $e->getMessage());
            $error = "Could not close tickets right now. Please try again.";
        }
    }
}

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
        SELECT t.id, t.reference_id, t.subject, t.status, t.priority, t.updated_at, d.name AS department
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
        case 'Open':           return '<span class="badge badge-blue">Open</span>';
        case 'In Progress':    return '<span class="badge badge-purple">In Progress</span>';
        case 'Awaiting Reply': return '<span class="badge badge-orange">Awaiting Reply</span>';
        case 'Resolved':       return '<span class="badge badge-green">Resolved</span>';
        case 'Closed':         return '<span class="badge badge-gray">Closed</span>';
        default:               return '<span class="badge badge-gray">Unknown</span>';
    }
}

function getPriorityBadge($priority) {
    switch ($priority) {
        case 'Critical': return '<span class="p-dot p-red"></span> Critical';
        case 'High':     return '<span class="p-dot p-orange"></span> High';
        case 'Medium':   return '<span class="p-dot p-blue"></span> Medium';
        case 'Low':      return '<span class="p-dot p-gray"></span> Low';
        default:         return '<span class="p-dot p-gray"></span> None';
    }
}

$page_title = 'Support Tickets';
$header_title = 'Support Center';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; gap: 16px; flex-wrap: wrap; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.25); }
    .alert-error   { background: rgba(248,113,113,0.1); color: var(--accent-red);   border: 1px solid rgba(248,113,113,0.25); }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: background 0.3s, border-color 0.3s; }

    /* Filter row */
    .table-controls { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .filter-tab { font-size: 13px; font-weight: 500; color: var(--text-muted); text-decoration: none; padding: 6px 12px; border-radius: 6px; transition: all 0.2s; cursor: pointer; user-select: none; }
    .filter-tab:hover { color: var(--text); background: rgba(100,116,139,0.1); }
    .filter-tab.active { background: rgba(59,130,246,0.1); color: var(--accent2); }
    .filter-tab .count { display: inline-block; background: rgba(100,116,139,0.15); color: var(--text-dim); padding: 1px 8px; border-radius: 100px; margin-left: 6px; font-size: 11px; font-family: var(--font-mono); }
    .filter-tab.active .count { background: var(--accent2); color: #fff; }

    /* Bulk action bar */
    .bulk-bar {
        display: none;
        align-items: center;
        gap: 12px;
        padding: 12px 24px;
        background: rgba(59,130,246,0.06);
        border-bottom: 1px solid rgba(59,130,246,0.18);
        font-size: 14px;
        color: var(--text);
        animation: bulkSlide 0.18s ease-out;
    }
    @keyframes bulkSlide { from { transform: translateY(-6px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .bulk-bar .count-pill { background: var(--accent2); color: #fff; font-weight: 700; font-family: var(--font-mono); font-size: 12px; padding: 4px 10px; border-radius: 100px; }
    .bulk-bar .btn-bulk { padding: 8px 14px; background: var(--accent-red); color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: 0.2s; }
    .bulk-bar .btn-bulk:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .bulk-bar .btn-bulk:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    .bulk-bar .btn-clear { padding: 8px 14px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-size: 13px; cursor: pointer; transition: 0.2s; }
    .bulk-bar .btn-clear:hover { color: var(--text); border-color: var(--border-strong); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 14px 20px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--border-strong); background: rgba(100,116,139,0.03); }
    td { padding: 18px 20px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(100,116,139,0.05); }
    tr.is-selected td { background: rgba(59,130,246,0.05) !important; }

    /* Checkbox column */
    .col-check { width: 44px; padding-right: 0 !important; }
    input[type="checkbox"].t-check {
        appearance: none;
        width: 18px; height: 18px;
        border: 1.5px solid var(--border-strong);
        border-radius: 4px;
        background: var(--bg);
        cursor: pointer;
        position: relative;
        transition: 0.15s;
        vertical-align: middle;
    }
    input[type="checkbox"].t-check:checked { background: var(--accent2); border-color: var(--accent2); }
    input[type="checkbox"].t-check:checked::after {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free'; font-weight: 900;
        color: #fff; font-size: 10px;
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    }
    input[type="checkbox"].t-check:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .t-ref { font-family: var(--font-mono); font-size: 13px; color: var(--text-muted); }
    .t-subject { font-weight: 600; color: var(--text); font-size: 15px; margin-bottom: 4px; display: block; text-decoration: none; }
    .t-subject:hover { color: var(--accent2); }
    .t-dept { font-size: 13px; color: var(--text-dim); }

    /* Badges */
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-blue   { background: rgba(59,130,246,0.1);  color: var(--accent2);       border: 1px solid rgba(59,130,246,0.2); }
    .badge-purple { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .badge-green  { background: rgba(34,211,238,0.1);  color: var(--accent-green);  border: 1px solid rgba(34,211,238,0.2); }
    .badge-orange { background: rgba(251,146,60,0.1);  color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-gray   { background: var(--surface2);       color: var(--text-muted);    border: 1px solid var(--border); }

    .p-wrapper { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--text-muted); }
    .p-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .p-red    { background: var(--accent-red);    box-shadow: 0 0 8px rgba(248,113,113,0.4); }
    .p-orange { background: var(--accent-orange); }
    .p-blue   { background: var(--accent2); }
    .p-gray   { background: var(--text-dim); }
</style>

<div class="content-area">

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">My Tickets</h1>
        <p class="page-sub">View and manage your active support requests.</p>
      </div>
      <a href="new-ticket.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Open New Ticket</a>
    </div>

    <form method="POST" action="tickets.php" id="bulkForm">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_close" value="1">

      <div class="table-container">
        <div class="table-controls" style="overflow-x: auto; white-space: nowrap;">
          <?php
            // Pre-compute counts per status for the filter chips
            $byStatus = ['all' => count($tickets), 'Open' => 0, 'In Progress' => 0, 'Awaiting Reply' => 0, 'Resolved' => 0, 'Closed' => 0];
            foreach ($tickets as $t) { if (isset($byStatus[$t['status']])) $byStatus[$t['status']]++; }
          ?>
          <span class="filter-tab active" data-filter="all">All <span class="count"><?= $byStatus['all'] ?></span></span>
          <span class="filter-tab" data-filter="Open">Open <span class="count"><?= $byStatus['Open'] ?></span></span>
          <span class="filter-tab" data-filter="In Progress">In Progress <span class="count"><?= $byStatus['In Progress'] ?></span></span>
          <span class="filter-tab" data-filter="Awaiting Reply">Awaiting Reply <span class="count"><?= $byStatus['Awaiting Reply'] ?></span></span>
          <span class="filter-tab" data-filter="Resolved">Resolved <span class="count"><?= $byStatus['Resolved'] ?></span></span>
          <span class="filter-tab" data-filter="Closed">Closed <span class="count"><?= $byStatus['Closed'] ?></span></span>
        </div>

        <div class="bulk-bar" id="bulkBar">
            <span class="count-pill"><span id="selectedCount">0</span> selected</span>
            <button type="submit" class="btn-bulk" id="bulkCloseBtn"
                    onclick="return confirm('Close the selected tickets?\n\nClosed tickets stop receiving updates and can no longer be replied to.');">
                <i class="fa-solid fa-lock"></i> Close Selected
            </button>
            <button type="button" class="btn-clear" id="bulkClearBtn">Clear selection</button>
        </div>

        <table>
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" class="t-check" id="selectAll" title="Select all visible"></th>
              <th width="14%">ID</th>
              <th width="40%">Subject</th>
              <th width="14%">Status</th>
              <th width="14%">Priority</th>
              <th width="14%">Updated</th>
            </tr>
          </thead>
          <tbody id="tickets-table-body">
            <?php foreach ($tickets as $ticket): ?>
            <tr class="ticket-row" data-status="<?= htmlspecialchars($ticket['status']) ?>">
              <td class="col-check" onclick="event.stopPropagation();">
                <?php if ($ticket['status'] !== 'Closed'): ?>
                  <input type="checkbox" class="t-check ticket-check" name="selected_tickets[]" value="<?= (int)$ticket['id'] ?>">
                <?php else: ?>
                  <i class="fa-solid fa-lock" style="color: var(--text-dim); font-size: 12px;" title="Already closed"></i>
                <?php endif; ?>
              </td>
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
              <td colspan="6" style="text-align: center; padding: 48px; color: var(--text-dim);">
                <i class="fa-solid fa-inbox" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
                <span id="empty-state-text">No support tickets found.</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterTabs       = document.querySelectorAll('.filter-tab');
    const ticketRows       = document.querySelectorAll('.ticket-row');
    const emptyState       = document.getElementById('empty-state');
    const emptyStateText   = document.getElementById('empty-state-text');
    const selectAll        = document.getElementById('selectAll');
    const checkboxes       = () => document.querySelectorAll('.ticket-check');
    const bulkBar          = document.getElementById('bulkBar');
    const selectedCountEl  = document.getElementById('selectedCount');
    const bulkClearBtn     = document.getElementById('bulkClearBtn');
    const bulkCloseBtn     = document.getElementById('bulkCloseBtn');

    let currentFilter = 'all';

    function visibleCheckboxes() {
        return Array.from(checkboxes()).filter(cb => cb.closest('tr').style.display !== 'none');
    }

    function refreshBulkBar() {
        const checked = Array.from(checkboxes()).filter(cb => cb.checked).length;
        selectedCountEl.textContent = checked;
        bulkBar.style.display = checked > 0 ? 'flex' : 'none';
        bulkCloseBtn.disabled = checked === 0;
        // Sync row highlight + header checkbox state
        document.querySelectorAll('.ticket-row').forEach(row => {
            const cb = row.querySelector('.ticket-check');
            row.classList.toggle('is-selected', cb && cb.checked);
        });
        const vis = visibleCheckboxes();
        if (vis.length === 0) {
            selectAll.checked = false; selectAll.indeterminate = false;
        } else {
            const visChecked = vis.filter(cb => cb.checked).length;
            selectAll.checked     = visChecked === vis.length;
            selectAll.indeterminate = visChecked > 0 && visChecked < vis.length;
        }
    }

    function applyFilter() {
        let visible = 0;
        ticketRows.forEach(row => {
            const status = row.getAttribute('data-status');
            const show = (currentFilter === 'all' || status === currentFilter);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
            // hide-filtered rows shouldn't keep their selection
            if (!show) {
                const cb = row.querySelector('.ticket-check');
                if (cb) cb.checked = false;
            }
        });
        if (emptyState) {
            if (visible === 0) {
                emptyState.style.display = '';
                emptyStateText.textContent = currentFilter === 'all'
                    ? 'No support tickets found.'
                    : `No "${currentFilter}" tickets found.`;
            } else {
                emptyState.style.display = 'none';
            }
        }
        refreshBulkBar();
    }

    filterTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            filterTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter = tab.getAttribute('data-filter');
            applyFilter();
        });
    });

    selectAll.addEventListener('change', () => {
        const target = selectAll.checked;
        visibleCheckboxes().forEach(cb => cb.checked = target);
        refreshBulkBar();
    });

    // Delegate so the listeners survive filtering.
    document.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('ticket-check')) refreshBulkBar();
    });

    // Make the subject column open the ticket (rows themselves aren't clickable now
    // because the checkbox would otherwise hijack pointer events). The <a class="t-subject">
    // already handles that — no extra wiring needed here.

    bulkClearBtn.addEventListener('click', () => {
        checkboxes().forEach(cb => cb.checked = false);
        refreshBulkBar();
    });

    refreshBulkBar();
});
</script>

<?php include 'includes/footer.php'; ?>
