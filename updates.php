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

$updates = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.version_tag, u.title, u.published_at, c.name AS category_name, c.tag_class 
        FROM system_updates u
        JOIN update_categories c ON u.category_id = c.id
        WHERE u.is_published = 1
        ORDER BY u.published_at DESC
    ");
    $stmt->execute();
    $updates = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Updates fetch error: " . $e->getMessage());
}

$page_title = 'System Updates';
$header_title = 'System Updates';

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

    .u-version { font-family: var(--font-mono); font-size: 13px; color: var(--text-muted); background: var(--surface2); padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); }
    .u-title { font-weight: 600; color: var(--text); font-size: 15px; display: block; text-decoration: none; }
    .u-title:hover { color: var(--accent2); }

    .tag { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .tag-release { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .tag-feature { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .tag-bugfix { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .tag-security { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
</style>

<div class="content-area">
    
    <div class="page-header">
      <div>
        <h1 class="page-title">Changelog</h1>
        <p class="page-sub">New features, performance upgrades, and fixes for the Vormox control plane.</p>
      </div>
    </div>

    <div class="table-container">
      <div class="table-controls" style="overflow-x: auto; white-space: nowrap; padding-bottom: 8px;">
        <a class="filter-tab active" data-filter="all">All Updates</a>
        <a class="filter-tab" data-filter="Release">Release</a>
        <a class="filter-tab" data-filter="Feature">Feature</a>
        <a class="filter-tab" data-filter="Bugfix">Bugfix</a>
        <a class="filter-tab" data-filter="Security">Security</a>
      </div>
      
      <table>
        <thead>
          <tr>
            <th width="15%">Version</th>
            <th width="50%">Title</th>
            <th width="15%">Category</th>
            <th width="20%">Published</th>
          </tr>
        </thead>
        <tbody id="updates-table-body">
          <?php foreach ($updates as $update): ?>
          <tr class="update-row" data-category="<?= htmlspecialchars($update['category_name']) ?>" onclick="window.location='view-update.php?id=<?= htmlspecialchars($update['id']) ?>'">
            <td>
              <?php if (!empty($update['version_tag'])): ?>
                <span class="u-version"><?= htmlspecialchars($update['version_tag']) ?></span>
              <?php else: ?>
                <span style="color: var(--text-dim);">-</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="view-update.php?id=<?= htmlspecialchars($update['id']) ?>" class="u-title"><?= htmlspecialchars($update['title']) ?></a>
            </td>
            <td><span class="tag <?= htmlspecialchars($update['tag_class']) ?>"><?= htmlspecialchars($update['category_name']) ?></span></td>
            <td style="color: var(--text-muted); font-size: 13px;">
              <?= date('M j, Y', strtotime($update['published_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          
          <tr id="empty-state" style="display: <?= empty($updates) ? 'table-row' : 'none' ?>;">
            <td colspan="4" style="text-align: center; padding: 48px; color: var(--text-dim);">
              <i class="fa-solid fa-code-merge" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
              <span id="empty-state-text">No updates available.</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterTabs = document.querySelectorAll('.filter-tab');
    const updateRows = document.querySelectorAll('.update-row');
    const emptyState = document.getElementById('empty-state');
    const emptyStateText = document.getElementById('empty-state-text');

    filterTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            
            filterTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            const filterValue = tab.getAttribute('data-filter');
            let visibleCount = 0;

            updateRows.forEach(row => {
                if (filterValue === 'all' || row.getAttribute('data-category') === filterValue) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (visibleCount === 0) {
                emptyState.style.display = '';
                emptyStateText.textContent = filterValue === 'all' ? 'No updates available.' : `No ${filterValue}s found.`;
            } else {
                emptyState.style.display = 'none';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
