<?php
// migrate.php — idempotent SQL migration runner.
//
//   php migrate.php          → run all pending migrations
//   php migrate.php --status → list applied/pending, don't run anything
//
// Tracks applied migrations in a `schema_migrations` table. Each file in
// migrations/ is executed at most once per database.
//
// CLI-only, intentional: refuses to run via web so a stray request can't
// trigger schema changes.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php must be executed from the command line.\n");
}

require_once __DIR__ . '/config.php';

$showStatusOnly = in_array('--status', $argv ?? [], true);
$migrationsDir  = __DIR__ . '/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "No migrations/ directory found at {$migrationsDir}\n");
    exit(1);
}

// ----- Ensure the ledger table exists -----
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename    VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to create schema_migrations table: " . $e->getMessage() . "\n");
    exit(1);
}

// ----- Discover files -----
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if (!$files) {
    echo "No migration files found in migrations/.\n";
    exit(0);
}

// ----- Read what's already applied -----
$applied = [];
try {
    $applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to read schema_migrations: " . $e->getMessage() . "\n");
    exit(1);
}
$appliedSet = array_flip($applied);

// ----- Status mode: print and exit -----
if ($showStatusOnly) {
    echo "Migration status:\n";
    foreach ($files as $f) {
        $name = basename($f);
        $mark = isset($appliedSet[$name]) ? '[applied]' : '[pending]';
        echo "  {$mark}  {$name}\n";
    }
    exit(0);
}

// ----- Apply pending migrations -----
$ran = 0; $failed = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (isset($appliedSet[$name])) continue;

    echo "Applying {$name} … ";
    $sql = file_get_contents($f);
    if ($sql === false || trim($sql) === '') {
        echo "skipped (empty file)\n";
        continue;
    }

    try {
        $pdo->beginTransaction();

        // PDO::exec runs the whole script; many DDL statements implicitly
        // commit on MySQL, but we still bracket logical work in a transaction
        // so the ledger update either succeeds with the migration or we know
        // we have a partial state to investigate.
        $pdo->exec($sql);

        $ins = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $ins->execute([$name]);

        $pdo->commit();
        echo "ok\n";
        $ran++;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
        }
        echo "FAILED\n";
        fwrite(STDERR, "  {$e->getMessage()}\n");
        $failed++;
        break; // stop on first failure so later migrations don't apply against an inconsistent state
    }
}

if ($ran === 0 && $failed === 0) {
    echo "Nothing to do — schema is up to date.\n";
} else {
    echo "Applied {$ran} migration(s)";
    if ($failed > 0) echo ", {$failed} failed";
    echo ".\n";
}

exit($failed > 0 ? 1 : 0);
