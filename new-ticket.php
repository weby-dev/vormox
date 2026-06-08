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
$user = null;
$departments = [];

try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, theme FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        header("Location: signin.php");
        exit;
    }

    $deptStmt = $pdo->query("SELECT id, name FROM support_departments WHERE is_active = 1 ORDER BY name ASC");
    $departments = $deptStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Initialization error: " . $e->getMessage());
    die("A system error occurred.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_SPECIAL_CHARS);
    $subject = trim(filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_SPECIAL_CHARS));
    $message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_SPECIAL_CHARS));
    
    if (empty($department_id) || empty($priority) || empty($subject) || empty($message)) {
        $error = "Please fill out all required fields.";
    } else {
        $reference_id = 'TKN-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        try {
            $pdo->beginTransaction();

            $ticketStmt = $pdo->prepare("
                INSERT INTO tickets (reference_id, user_id, department_id, subject, priority, status, created_at, updated_at) 
                VALUES (:ref, :uid, :dept, :subj, :pri, 'Open', NOW(), NOW())
            ");
            $ticketStmt->execute([
                'ref' => $reference_id,
                'uid' => $_SESSION['user_id'],
                'dept' => $department_id,
                'subj' => $subject,
                'pri' => $priority
            ]);

            $new_ticket_id = $pdo->lastInsertId();

            $msgStmt = $pdo->prepare("
                INSERT INTO ticket_messages (ticket_id, sender_id, message, is_staff_reply, created_at) 
                VALUES (:tid, :sid, :msg, 0, NOW())
            ");
            $msgStmt->execute([
                'tid' => $new_ticket_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $message
            ]);

            $pdo->commit();

            header("Location: view-ticket.php?id=" . $reference_id);
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Failed to create ticket: " . $e->getMessage());
            $error = "A system error occurred while creating your ticket. Please try again.";
        }
    }
}

$page_title = 'Open New Ticket';
$header_title = 'Support Center';

include 'includes/header.php';
?>

<style>
    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
    .alert-info { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); margin-bottom: 32px; transition: background 0.3s, border-color 0.3s; }
    
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 16px; transition: color 0.2s; }
    .back-link:hover { color: var(--text); }
    
    .page-title { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
    .page-sub { font-size: 15px; color: var(--text-muted); margin-bottom: 32px; }
    
    .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 32px; box-shadow: 0 12px 40px rgba(0,0,0,0.2); transition: background 0.3s, border-color 0.3s; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px; }
    .form-group:last-child { margin-bottom: 0; }
    
    label { font-size: 13px; font-weight: 600; color: var(--text); font-family: var(--font-mono); letter-spacing: 0.05em; text-transform: uppercase; }
    
    input[type="text"], select, textarea { width: 100%; padding: 14px 16px; background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 8px; color: var(--text); font-family: var(--font-body); font-size: 15px; transition: all 0.2s; outline: none; appearance: none; }
    input:focus, select:focus, textarea:focus { border-color: var(--accent); background: var(--bg); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    
    .select-wrapper { position: relative; }
    .select-wrapper::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
    select option { background: var(--surface2); color: var(--text); padding: 12px; }
    textarea { min-height: 200px; resize: vertical; line-height: 1.6; }
    
    .attachment-zone { border: 2px dashed var(--border-strong); border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.2s; background: rgba(255,255,255,0.02); margin-bottom: 32px; }
    .attachment-zone:hover { border-color: var(--accent); background: rgba(59,130,246,0.05); }
    .attachment-zone i { font-size: 24px; color: var(--text-muted); margin-bottom: 12px; }
    .attachment-zone p { font-size: 14px; color: var(--text-dim); margin: 0; }
    
    .form-actions { display: flex; justify-content: flex-end; gap: 16px; padding-top: 24px; border-top: 1px solid var(--border); align-items: center; }
    
    .btn-ghost { padding: 12px 24px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; font-family: var(--font-body); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ghost:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    
    .btn-submit { padding: 12px 24px; background: var(--accent); color: #fff; border: none; border-radius: 8px; font-family: var(--font-body); font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 20px rgba(59,130,246,.3); display: inline-flex; align-items: center; justify-content: center; }
    .btn-submit:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 28px rgba(59,130,246,.5); }
    
    @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; gap: 0; } }
</style>

<div class="content-area">
  <a href="tickets.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Tickets</a>
  
  <div class="page-header">
    <h1 class="page-title">Submit a Request</h1>
    <p class="page-sub">Provide as much detail as possible so our engineering team can assist you efficiently.</p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="alert alert-info">
    <i class="fa-solid fa-book-open"></i> 
    <span>Before submitting a ticket, check our <a href="#" style="color: var(--text); font-weight: 600;">Documentation</a> to see if your issue is covered in the API guides or Troubleshooting section.</span>
  </div>

  <div class="form-card">
    <form method="POST" action="new-ticket.php" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group">
          <label for="department_id">Department</label>
          <div class="select-wrapper">
            <select name="department_id" id="department_id" required>
              <option value="" disabled selected>Select a department...</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= htmlspecialchars($dept['id']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label for="priority">Priority Level</label>
          <div class="select-wrapper">
            <select name="priority" id="priority" required>
              <option value="Low">Low - General inquiry or minor issue</option>
              <option value="Medium" selected>Medium - Core function impaired</option>
              <option value="High">High - Severe performance degradation</option>
              <option value="Critical">Critical - Production outage / Down completely</option>
            </select>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" placeholder="e.g., VLAN configuration timeout on Node 2" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="message">Detailed Description</label>
        <textarea id="message" name="message" placeholder="Please include steps to reproduce the issue, any error logs, and expected outcomes..." required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
      </div>

      <div class="attachment-zone">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <p>Drag and drop error logs or screenshots here, or click to browse files.</p>
      </div>

      <div class="form-actions">
        <a href="tickets.php" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-submit"><i class="fa-solid fa-paper-plane" style="margin-right: 8px;"></i> Submit Ticket</button>
      </div>
    </form>
  </div>

</div>

<?php include 'includes/footer.php'; ?>
