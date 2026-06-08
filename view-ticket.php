<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$ticket_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
$error = '';
$success = '';

if (!$ticket_id) {
    header("Location: tickets.php");
    exit;
}

// Handle Close Ticket (user-side)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    csrf_require();
    try {
        $stmt = $pdo->prepare("
            UPDATE tickets
               SET status = 'Closed', updated_at = NOW()
             WHERE reference_id = :ref AND user_id = :uid
        ");
        $stmt->execute(['ref' => $ticket_id, 'uid' => $_SESSION['user_id']]);

        header("Location: view-ticket.php?id=" . urlencode($ticket_id));
        exit;
    } catch (PDOException $e) {
        error_log("Close ticket error: " . $e->getMessage());
        $error = "Failed to close the ticket. Please try again.";
    }
}

// Handle New Message Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    csrf_require();
    $reply_message = trim(filter_input(INPUT_POST, 'reply_message', FILTER_SANITIZE_SPECIAL_CHARS));
    
    if (empty($reply_message)) {
        $error = "Message cannot be empty.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE reference_id = :ref AND user_id = :uid LIMIT 1");
            $stmt->execute(['ref' => $ticket_id, 'uid' => $_SESSION['user_id']]);
            $ticket = $stmt->fetch();

            if ($ticket) {
                $insertStmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_staff_reply) VALUES (:tid, :sid, :msg, 0)");
                $insertStmt->execute([
                    'tid' => $ticket['id'],
                    'sid' => $_SESSION['user_id'],
                    'msg' => $reply_message
                ]);
                
                $updateStmt = $pdo->prepare("UPDATE tickets SET status = 'Open', updated_at = NOW() WHERE id = :tid");
                $updateStmt->execute(['tid' => $ticket['id']]);

                header("Location: view-ticket.php?id=" . $ticket_id);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Reply error: " . $e->getMessage());
            $error = "Failed to send reply. Please try again.";
        }
    }
}

// Fetch user data needed for header.php
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

$ticket_details = null;
$messages = [];

try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.reference_id, t.subject, t.status, t.priority, t.created_at, d.name AS department
        FROM tickets t
        JOIN support_departments d ON t.department_id = d.id
        WHERE t.reference_id = :ref AND t.user_id = :uid LIMIT 1
    ");
    $stmt->execute(['ref' => $ticket_id, 'uid' => $_SESSION['user_id']]);
    $ticket_details = $stmt->fetch();

    if ($ticket_details) {
        $msgStmt = $pdo->prepare("
            SELECT m.message, m.is_staff_reply, m.created_at, u.first_name, u.last_name
            FROM ticket_messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.ticket_id = :tid
            ORDER BY m.created_at ASC
        ");
        $msgStmt->execute(['tid' => $ticket_details['id']]);
        $messages = $msgStmt->fetchAll();
    } else {
        header("Location: tickets.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("View ticket error: " . $e->getMessage());
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

$page_title = htmlspecialchars($ticket_details['reference_id']) . ' — Support';
$header_title = 'Support Center';

include 'includes/header.php';
?>

<style>
    .content-area { display: flex; gap: 32px; flex-direction: row; }
    
    .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .alert-success { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .alert-error { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }

    .ticket-main { flex: 1; }
    .ticket-sidebar { width: 320px; flex-shrink: 0; }
    .ticket-header { margin-bottom: 32px; }
    
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 16px; transition: color 0.2s; }
    .back-link:hover { color: var(--text); }
    
    .ticket-title { font-family: var(--font-head); font-size: 28px; font-weight: 700; color: var(--text); margin-bottom: 8px; line-height: 1.3; }
    .ticket-ref { font-family: var(--font-mono); font-size: 14px; color: var(--text-dim); }
    
    .message-thread { display: flex; flex-direction: column; gap: 24px; margin-bottom: 40px; }
    .message { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 24px; transition: background 0.3s, border-color 0.3s; }
    .message.staff { background: linear-gradient(160deg, rgba(59,130,246,0.05), transparent); border-color: rgba(59,130,246,0.3); }
    
    .msg-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
    .msg-author { display: flex; align-items: center; gap: 12px; }
    .author-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--surface2); border: 1px solid var(--border-strong); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; transition: background 0.3s; }
    .staff .author-avatar { background: var(--accent); color: #fff; border: none; }
    .author-name { font-weight: 600; font-size: 14px; color: var(--text); }
    .staff .author-name { color: var(--accent2); }
    .msg-time { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
    .msg-body { font-size: 15px; color: var(--text-muted); line-height: 1.7; white-space: pre-wrap; }
    
    .reply-box { background: var(--bg2); border: 1px solid var(--border-strong); border-radius: 16px; padding: 24px; transition: background 0.3s, border-color 0.3s; }
    .reply-title { font-weight: 600; font-size: 16px; color: var(--text); margin-bottom: 16px; }
    textarea { width: 100%; min-height: 150px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 16px; color: var(--text); font-family: var(--font-body); font-size: 14px; line-height: 1.6; outline: none; resize: vertical; transition: border-color 0.2s, background 0.3s; margin-bottom: 16px; }
    textarea:focus { border-color: var(--accent); }
    
    .detail-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 24px; position: sticky; top: 112px; transition: background 0.3s, border-color 0.3s; }
    .detail-item { margin-bottom: 24px; }
    .detail-item:last-child { margin-bottom: 0; }
    .detail-label { font-family: var(--font-mono); font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); margin-bottom: 8px; }
    .detail-value { font-size: 14px; font-weight: 500; color: var(--text); }
    
    .badge { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); display: inline-block; }
    .badge-blue { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .badge-purple { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .badge-green { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .badge-orange { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .badge-gray { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); transition: background 0.3s, border-color 0.3s; }

    @media (max-width: 1024px) {
        .content-area { flex-direction: column; }
        .ticket-sidebar { width: 100%; }
        .detail-card { position: static; display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
        .detail-item { margin-bottom: 0; }
    }
</style>

<div class="content-area">
  
  <div class="ticket-main">
    <a href="tickets.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Tickets</a>
    
    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="ticket-header">
      <h1 class="ticket-title"><?= htmlspecialchars($ticket_details['subject']) ?></h1>
      <div class="ticket-ref">Ticket ID: <?= htmlspecialchars($ticket_details['reference_id']) ?></div>
    </div>

    <div class="message-thread">
      <?php foreach ($messages as $msg): ?>
        <div class="message <?= $msg['is_staff_reply'] ? 'staff' : '' ?>">
          <div class="msg-header">
            <div class="msg-author">
              <div class="author-avatar">
                  <?= $msg['is_staff_reply'] ? '<i class="fa-solid fa-headset"></i>' : strtoupper(substr($msg['first_name'], 0, 1)) ?>
              </div>
              <div class="author-name"><?= htmlspecialchars($msg['first_name'] . ' ' . $msg['last_name']) ?></div>
            </div>
            <div class="msg-time"><?= date('F j, Y, g:i a', strtotime($msg['created_at'])) ?></div>
          </div>
          <div class="msg-body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($ticket_details['status'] !== 'Closed'): ?>
      <div class="reply-box">
        <div class="reply-title">Add a Reply</div>
        <form method="POST" action="view-ticket.php?id=<?= htmlspecialchars($ticket_id) ?><?= csrf_field() ?>"><?= csrf_field() ?>
          <textarea name="reply_message" placeholder="Type your message here..." required></textarea>
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
            <button type="submit" class="btn-submit"><i class="fa-solid fa-paper-plane" style="margin-right: 8px;"></i> Send Reply</button>
            <button type="submit"
                    name="close_ticket"
                    value="1"
                    formnovalidate
                    onclick="return confirm('Close this ticket? You can always open a new one if you need more help.');"
                    style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-strong); padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                <i class="fa-solid fa-circle-check"></i> Close Ticket
            </button>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="alert alert-error" style="justify-content: center; background: rgba(255,255,255,0.05); border-color: var(--border); color: var(--text-muted);">
          <i class="fa-solid fa-lock"></i> This ticket is closed and cannot receive new replies.
      </div>
    <?php endif; ?>

  </div>

  <div class="ticket-sidebar">
    <div class="detail-card">
      <div class="detail-item">
        <div class="detail-label">Status</div>
        <div class="detail-value"><?= getStatusBadge($ticket_details['status']) ?></div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Priority</div>
        <div class="detail-value">
          <span style="font-weight: 600; color: <?= $ticket_details['priority'] == 'High' || $ticket_details['priority'] == 'Critical' ? 'var(--accent-red)' : 'var(--text)' ?>">
            <?= htmlspecialchars($ticket_details['priority']) ?>
          </span>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Department</div>
        <div class="detail-value"><?= htmlspecialchars($ticket_details['department']) ?></div>
      </div>
      <div class="detail-item">
        <div class="detail-label">Created On</div>
        <div class="detail-value" style="font-family: var(--font-mono); font-size: 13px; color: var(--text-muted);">
          <?= date('M j, Y, H:i T', strtotime($ticket_details['created_at'])) ?>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include 'includes/footer.php'; ?>
