<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_invoice'])) {
    $invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    
    if ($invoice_id) {
        try {
            $pdo->beginTransaction();
            
            $invStmt = $pdo->prepare("SELECT id, amount, status FROM invoices WHERE id = :inv_id AND user_id = :uid FOR UPDATE");
            $invStmt->execute(['inv_id' => $invoice_id, 'uid' => $_SESSION['user_id']]);
            $invoice = $invStmt->fetch();
            
            if (!$invoice) {
                throw new Exception("Invoice not found.");
            }
            if ($invoice['status'] !== 'Unpaid') {
                throw new Exception("This invoice is already paid or cancelled.");
            }
            
            $userStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
            $userStmt->execute(['uid' => $_SESSION['user_id']]);
            $userData = $userStmt->fetch();
            
            if ($userData['wallet_balance'] < $invoice['amount']) {
                throw new Exception("Insufficient wallet balance. Please add funds to your account.");
            }
            
            $new_balance = $userData['wallet_balance'] - $invoice['amount'];
            $updUser = $pdo->prepare("UPDATE users SET wallet_balance = :bal WHERE id = :uid");
            $updUser->execute(['bal' => $new_balance, 'uid' => $_SESSION['user_id']]);
            
            $updInv = $pdo->prepare("UPDATE invoices SET status = 'Paid', updated_at = NOW() WHERE id = :inv_id");
            $updInv->execute(['inv_id' => $invoice['id']]);
            
            $pdo->commit();
            $success = "Invoice paid successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme, wallet_balance FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }
} catch (PDOException $e) {
    die("A system error occurred.");
}

$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, invoice_number, amount, status, due_date, created_at 
        FROM invoices 
        WHERE user_id = :uid 
        ORDER BY created_at DESC
    ");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Invoices fetch error: " . $e->getMessage());
}

$page_title = 'Invoices';
$header_title = 'Billing & Payments';

include 'includes/header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
    .page-title { font-family: var(--font-head); font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -.03em; margin-bottom: 8px; }
    .page-sub { font-size: 16px; color: var(--text-muted); }

    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .wallet-card { background: linear-gradient(135deg, var(--bg2), var(--surface)); border: 1px solid var(--border-strong); border-radius: 16px; padding: 32px; margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .wallet-label { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; }
    .wallet-balance { font-family: var(--font-head); font-size: 40px; font-weight: 800; color: var(--text); }
    .wallet-actions { display: flex; gap: 16px; }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
    .table-controls { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; gap: 16px; overflow-x: auto; white-space: nowrap; }
    .filter-tab { font-size: 14px; font-weight: 500; color: var(--text-muted); text-decoration: none; padding: 6px 12px; border-radius: 6px; transition: all 0.2s; cursor: pointer; }
    .filter-tab:hover { color: var(--text); background: rgba(100,116,139,0.1); }
    .filter-tab.active { background: rgba(59,130,246,0.1); color: var(--accent2); }

    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--border-strong); background: rgba(100,116,139,0.03); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }

    .inv-number { font-family: var(--font-mono); font-weight: 600; color: var(--text); font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .inv-date { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
    .inv-amount { font-family: var(--font-mono); font-size: 16px; font-weight: 600; color: var(--text); }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-paid { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-unpaid { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-cancelled { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }

    .btn-pay { padding: 8px 16px; background: var(--accent); color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s; }
    .btn-pay:hover { background: #2563eb; transform: translateY(-1px); }

    @media (max-width: 768px) {
        .wallet-card { flex-direction: column; align-items: flex-start; gap: 24px; }
    }
</style>

<div class="content-area">
    
    <div class="page-header">
        <div>
            <h1 class="page-title">Invoices</h1>
            <p class="page-sub">View and pay your billing statements.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="wallet-card">
        <div>
            <div class="wallet-label">Available Wallet Balance</div>
            <div class="wallet-balance">$<?= number_format($user['wallet_balance'], 2) ?></div>
        </div>
        <div class="wallet-actions">
            <a href="billing.php" class="btn-ghost"><i class="fa-solid fa-gear"></i> Billing Settings</a>
            <a href="billing.php?action=add_funds" class="btn-primary" style="text-decoration: none;"><i class="fa-solid fa-wallet"></i> Add Funds</a>
        </div>
    </div>

    <div class="table-container">
        <div class="table-controls">
            <a class="filter-tab active" data-filter="all">All Invoices</a>
            <a class="filter-tab" data-filter="Unpaid">Unpaid</a>
            <a class="filter-tab" data-filter="Paid">Paid</a>
            <a class="filter-tab" data-filter="Cancelled">Cancelled</a>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="30%">Invoice</th>
                    <th width="15%">Amount</th>
                    <th width="20%">Due Date</th>
                    <th width="15%">Status</th>
                    <th width="20%">Action</th>
                </tr>
            </thead>
            <tbody id="invoices-table-body">
                <?php foreach ($invoices as $inv): ?>
                <tr class="inv-row" data-status="<?= htmlspecialchars($inv['status']) ?>">
                    <td>
                        <div class="inv-number">
    <i class="fa-solid fa-file-invoice" style="color: var(--text-dim);"></i> 
    <a href="view-invoice.php?id=<?= htmlspecialchars($inv['invoice_number']) ?>" style="color: var(--text); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text)'">
        <?= htmlspecialchars($inv['invoice_number']) ?>
    </a>
</div>
                        <div class="inv-date">Issued: <?= date('M j, Y', strtotime($inv['created_at'])) ?></div>
                    </td>
                    <td class="inv-amount">$<?= number_format($inv['amount'], 2) ?></td>
                    <td style="color: <?= (strtotime($inv['due_date']) < time() && $inv['status'] === 'Unpaid') ? 'var(--accent-red)' : 'var(--text-muted)' ?>; font-size: 13px;">
                        <?= date('M j, Y', strtotime($inv['due_date'])) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= strtolower($inv['status']) ?>">
                            <?= htmlspecialchars($inv['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($inv['status'] === 'Unpaid'): ?>
                            <form method="POST" action="invoices.php" style="margin: 0;">
                                <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($inv['id']) ?>">
                                <button type="submit" name="pay_invoice" class="btn-pay">Pay from Wallet</button>
                            </form>
                        <?php elseif ($inv['status'] === 'Paid'): ?>
                            <span style="color: var(--text-dim); font-size: 13px;"><i class="fa-solid fa-check"></i> Settled</span>
                        <?php else: ?>
                            <span style="color: var(--text-dim); font-size: 13px;"><i class="fa-solid fa-ban"></i> Cancelled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <tr id="empty-state" style="display: <?= empty($invoices) ? 'table-row' : 'none' ?>;">
                    <td colspan="5" style="text-align: center; padding: 48px; color: var(--text-dim);">
                        <i class="fa-solid fa-receipt" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
                        <span id="empty-state-text">No invoices found.</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterTabs = document.querySelectorAll('.filter-tab');
    const invRows = document.querySelectorAll('.inv-row');
    const emptyState = document.getElementById('empty-state');
    const emptyStateText = document.getElementById('empty-state-text');

    filterTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            
            filterTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            const filterValue = tab.getAttribute('data-filter');
            let visibleCount = 0;

            invRows.forEach(row => {
                const status = row.getAttribute('data-status');

                if (filterValue === 'all' || status === filterValue) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (visibleCount === 0) {
                emptyState.style.display = '';
                emptyStateText.textContent = filterValue === 'all' ? 'No invoices found.' : `No ${filterValue.toLowerCase()} invoices found.`;
            } else {
                emptyState.style.display = 'none';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
