<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';
require_once 'auth_guard.php'; // Ensures banned/unverified users are blocked with the popup

$error = '';
$success = '';

// Handle AJAX Auto-Renew Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_autorenew') {
    $panel_id = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
    $auto_renew = filter_input(INPUT_POST, 'auto_renew', FILTER_VALIDATE_INT);

    if ($panel_id && ($auto_renew === 0 || $auto_renew === 1)) {
        try {
            $stmt = $pdo->prepare("UPDATE user_panels SET auto_renew = :renew WHERE id = :pid AND user_id = :uid");
            $stmt->execute(['renew' => $auto_renew, 'pid' => $panel_id, 'uid' => $_SESSION['user_id']]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

// Handle Add Panel Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_panel'])) {
    $domain = trim(filter_input(INPUT_POST, 'domain', FILTER_SANITIZE_URL));
    $nodes = filter_input(INPUT_POST, 'nodes_count', FILTER_VALIDATE_INT);
    $billing_cycle = filter_input(INPUT_POST, 'billing_cycle', FILTER_SANITIZE_SPECIAL_CHARS);

    $cycle_months = [
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annually' => 6,
        'annually' => 12
    ];

    $months = $cycle_months[$billing_cycle] ?? 0;

    if (empty($domain) || !$nodes || !$months) {
        $error = "Please provide valid inputs for all fields.";
    } elseif ($nodes > 25) {
        $error = "For more than 25 nodes, please contact our enterprise support team.";
    } else {
        
        // ---> NEW DOMAIN AVAILABILITY CHECK (Backend Security) <---
        $checkStmt = $pdo->prepare("SELECT id FROM user_panels WHERE domain = :domain LIMIT 1");
        $checkStmt->execute(['domain' => $domain]);
        if ($checkStmt->fetch()) {
            $error = "The domain '$domain' is already in use by another panel.";
        } else {
            // ORIGINAL PRICING & INSERT LOGIC
            $price_per_node = 0;
            if ($nodes >= 1 && $nodes <= 4) {
                $price_per_node = 10;
            } elseif ($nodes >= 5 && $nodes <= 10) {
                $price_per_node = 9;
            } elseif ($nodes >= 11 && $nodes <= 25) {
                $price_per_node = 8;
            }

            $total_price = ($nodes * $price_per_node) * $months;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO user_panels (user_id, domain, nodes_count, billing_cycle, total_price, status, auto_renew) 
                    VALUES (:uid, :dom, :nodes, :cycle, :total, 'payment_pending', 1)
                ");
                $stmt->execute([
                    'uid' => $_SESSION['user_id'],
                    'dom' => $domain,
                    'nodes' => $nodes,
                    'cycle' => $billing_cycle,
                    'total' => $total_price
                ]);

                $invoice_number = 'INV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $due_date = date('Y-m-d', strtotime('+3 days'));

                $invStmt = $pdo->prepare("
                    INSERT INTO invoices (user_id, invoice_number, amount, status, due_date, created_at) 
                    VALUES (:uid, :inv_num, :amount, 'Unpaid', :due, NOW())
                ");
                $invStmt->execute([
                    'uid' => $_SESSION['user_id'],
                    'inv_num' => $invoice_number,
                    'amount' => $total_price,
                    'due' => $due_date
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

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    $panelsStmt = $pdo->prepare("SELECT * FROM user_panels WHERE user_id = :id ORDER BY created_at DESC");
    $panelsStmt->execute(['id' => $_SESSION['user_id']]);
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

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 16px 24px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--border-strong); background: rgba(100,116,139,0.03); }
    td { padding: 20px 24px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text); }
    tr:last-child td { border-bottom: none; }

    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; white-space: nowrap; }
    .badge-payment_pending { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-pending { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .badge-creating { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .badge-active { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-restarting { background: rgba(251,191,36,0.1); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }
    .badge-suspended { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    /* Custom Toggle Switch CSS */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .toggle-switch input { 
        opacity: 0;
        width: 0;
        height: 0;
    }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: var(--surface2);
        border: 1px solid var(--border-strong);
        transition: .3s;
        border-radius: 24px;
    }
    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background-color: var(--text-muted);
        transition: .3s;
        border-radius: 50%;
    }
    input:checked + .slider {
        background-color: rgba(34,211,238,0.15);
        border-color: rgba(34,211,238,0.3);
    }
    input:checked + .slider:before {
        background-color: var(--accent-green);
        transform: translateX(20px);
    }
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

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th width="25%">Domain</th>
                    <th width="10%">Nodes</th>
                    <th width="15%">Cycle</th>
                    <th width="15%">Total</th>
                    <th width="10%">Status</th>
                    <th width="10%" style="text-align: center;">Auto Renew</th>
                    <th width="15%">Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($panels as $panel): ?>
                <tr>
                    <td style="font-weight: 600;"><?= htmlspecialchars($panel['domain']) ?></td>
                    <td><?= htmlspecialchars($panel['nodes_count']) ?></td>
                    <td style="text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', '-', $panel['billing_cycle'])) ?></td>
                    <td style="font-family: var(--font-mono);">$<?= number_format($panel['total_price'], 2) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($panel['status']) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $panel['status'])) ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   class="autorenew-toggle" 
                                   data-id="<?= htmlspecialchars($panel['id']) ?>" 
                                   <?= $panel['auto_renew'] == 1 ? 'checked' : '' ?>
                                   <?= $panel['status'] === 'suspended' ? 'disabled' : '' ?> >
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td style="color: var(--text-muted); font-size: 13px;">
                        <?= date('M j, Y', strtotime($panel['created_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($panels)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-dim);">
                        <i class="fa-solid fa-server" style="font-size: 32px; margin-bottom: 16px; opacity: 0.5;"></i><br>
                        No panels deployed yet.
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
                <input type="text" id="domain" name="domain" required placeholder="panel.yourdomain.com">
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
                <button type="button" id="cancelModalBtn" class="btn-ghost">Cancel</button>
                <button type="submit" id="submitBtn" name="add_panel" class="btn-primary">Deploy Panel</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Handle Auto-Renew Toggles
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

                const response = await fetch('panels.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                });

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error('Server returned false');
                }
            } catch (error) {
                this.checked = originalState;
                alert('Failed to update Auto-Renew status. Please try again.');
            }
        });
    });

    // Modal Logic
    const modal = document.getElementById('addPanelModal');
    const openBtn = document.getElementById('openModalBtn');
    const closeBtn = document.getElementById('closeModalBtn');
    const cancelBtn = document.getElementById('cancelModalBtn');
    
    const nodesInput = document.getElementById('nodes_count');
    const cycleInput = document.getElementById('billing_cycle');
    const priceDisplay = document.getElementById('priceDisplay');
    const pricingDesc = document.getElementById('pricingDesc');
    const submitBtn = document.getElementById('submitBtn');
    
    // Domain Check Logic Vars
    const domainInput = document.getElementById('domain');
    const domainFeedback = document.getElementById('domainFeedback');
    let domainTimeout;
    let isDomainValid = false;

    function openModal() {
        modal.classList.add('active');
        calculatePrice();
    }

    function closeModal() {
        modal.classList.remove('active');
    }

    function calculatePrice() {
        const nodes = parseInt(nodesInput.value) || 0;
        const cycle = cycleInput.value;
        let months = 1;
        
        switch(cycle) {
            case 'quarterly': months = 3; break;
            case 'semi_annually': months = 6; break;
            case 'annually': months = 12; break;
            default: months = 1;
        }
        
        let pricePerNode = 0;

        if (nodes <= 0) {
            priceDisplay.textContent = '$0.00';
            pricingDesc.textContent = 'Invalid node count';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            return;
        }

        if (nodes >= 1 && nodes <= 4) pricePerNode = 10;
        else if (nodes >= 5 && nodes <= 10) pricePerNode = 9;
        else if (nodes >= 11 && nodes <= 25) pricePerNode = 8;

        if (nodes > 25) {
            priceDisplay.textContent = 'Custom';
            pricingDesc.textContent = 'Please contact support';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
        } else {
            const total = (nodes * pricePerNode) * months;
            priceDisplay.textContent = '$' + total.toFixed(2);
            pricingDesc.textContent = '$' + pricePerNode.toFixed(2) + ' / node / month';
            
            // Only enable submit if the domain is ALSO valid or empty (which required handles)
            const dVal = domainInput.value.trim();
            if (dVal.length > 0 && !isDomainValid) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
            } else {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
        }
    }

    if(openBtn) openBtn.addEventListener('click', openModal);
    if(closeBtn) closeBtn.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    
    if(modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    if(nodesInput) nodesInput.addEventListener('input', calculatePrice);
    if(cycleInput) cycleInput.addEventListener('change', calculatePrice);

    // --- NEW AJAX DOMAIN CHECKER INTEGRATED HERE ---
    if (domainInput) {
        domainInput.addEventListener('input', function() {
            clearTimeout(domainTimeout);
            const domain = this.value.trim();

            if (domain.length === 0) {
                domainFeedback.style.display = 'none';
                domainInput.style.borderColor = 'var(--border-strong)';
                isDomainValid = false;
                calculatePrice(); // Re-evaluates button state
                return;
            }

            domainFeedback.style.display = 'block';
            domainFeedback.style.color = 'var(--text-muted)';
            domainFeedback.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking availability...';
            isDomainValid = false;
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';

            domainTimeout = setTimeout(() => {
                fetch(`check_domain.php?domain=${encodeURIComponent(domain)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.exists) {
                            domainFeedback.style.color = 'var(--accent-red)';
                            domainFeedback.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> This domain is already registered.';
                            isDomainValid = false;
                            submitBtn.disabled = true;
                            domainInput.style.borderColor = 'var(--accent-red)';
                        } else {
                            domainFeedback.style.color = 'var(--accent-green)';
                            domainFeedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> Domain is available!';
                            isDomainValid = true;
                            domainInput.style.borderColor = 'var(--accent-green)';
                            calculatePrice(); // Will enable button if nodes are valid
                        }
                    })
                    .catch(err => {
                        domainFeedback.style.color = 'var(--accent-orange)';
                        domainFeedback.innerHTML = 'Error checking domain availability.';
                        isDomainValid = false;
                    });
            }, 500);
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
