<?php
session_start();
require_once 'auth_guard.php';
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.php");
    exit;
}

require_once 'config.php';

$update_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$update_id) {
    header("Location: updates.php");
    exit;
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

$update = null;
try {
    $stmt = $pdo->prepare("
        SELECT u.version_tag, u.title, u.content, u.published_at, c.name AS category_name, c.tag_class 
        FROM system_updates u
        JOIN update_categories c ON u.category_id = c.id
        WHERE u.id = :id AND u.is_published = 1
        LIMIT 1
    ");
    $stmt->execute(['id' => $update_id]);
    $update = $stmt->fetch();
    
    if (!$update) {
        header("Location: updates.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Update fetch error: " . $e->getMessage());
    header("Location: updates.php");
    exit;
}

$page_title = htmlspecialchars($update['title']);
$header_title = 'System Updates';

include 'includes/header.php';
?>

<style>
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 32px; transition: color 0.2s; }
    .back-link:hover { color: var(--text); }

    /* Removed max-width and margin: 0 auto to let it fill the page */
    .article-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 56px; width: 100%; transition: background 0.3s, border-color 0.3s; box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
    
    .article-meta { display: flex; align-items: center; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
    .article-date { font-family: var(--font-mono); font-size: 14px; color: var(--text-dim); }
    .article-version { font-family: var(--font-mono); font-size: 13px; color: var(--text); background: var(--surface2); padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border); }

    .article-title { font-family: var(--font-head); font-size: 40px; font-weight: 800; color: var(--text); margin-bottom: 32px; letter-spacing: -.02em; line-height: 1.2; }
    
    /* Increased font size slightly for readability on a wider screen */
    .article-content { font-size: 17px; color: var(--text-muted); line-height: 1.8; }
    .article-content p { margin-bottom: 1.5em; }

    .tag { padding: 6px 14px; border-radius: 100px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono); }
    .tag-release { background: rgba(59,130,246,0.1); color: var(--accent2); border: 1px solid rgba(59,130,246,0.2); }
    .tag-feature { background: rgba(167,139,250,0.1); color: var(--accent-purple); border: 1px solid rgba(167,139,250,0.2); }
    .tag-bugfix { background: rgba(251,146,60,0.1); color: var(--accent-orange); border: 1px solid rgba(251,146,60,0.2); }
    .tag-security { background: rgba(248,113,113,0.1); color: var(--accent-red); border: 1px solid rgba(248,113,113,0.2); }
</style>

<div class="content-area">
    
    <a href="updates.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to All Updates</a>

    <article class="article-container">
        <div class="article-meta">
            <span class="tag <?= htmlspecialchars($update['tag_class']) ?>"><?= htmlspecialchars($update['category_name']) ?></span>
            <?php if (!empty($update['version_tag'])): ?>
                <span class="article-version"><?= htmlspecialchars($update['version_tag']) ?></span>
            <?php endif; ?>
            <span class="article-date"><?= date('F j, Y', strtotime($update['published_at'])) ?></span>
        </div>

        <h1 class="article-title"><?= htmlspecialchars($update['title']) ?></h1>
        
        <div class="article-content">
            <?= nl2br(htmlspecialchars($update['content'])) ?>
        </div>
    </article>

</div>

<?php include 'includes/footer.php'; ?>
