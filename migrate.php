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

// MySQL error codes that mean "the state this DDL was trying to create
// already exists" — safe to mark the migration as applied and move on.
// Anything outside this list is treated as a real failure.
//   1050 — table already exists
//   1060 — duplicate column name
//   1061 — duplicate key name
//   1091 — can't drop; column/index doesn't exist
const TOLERABLE_DDL_CODES = [1050, 1060, 1061, 1091];

// ----- Apply pending migrations -----
$ran = 0; $reconciled = 0; $failed = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (isset($appliedSet[$name])) continue;

    echo "Applying {$name} … ";
    $sql = file_get_contents($f);
    if ($sql === false || trim($sql) === '') {
        echo "skipped (empty file)\n";
        continue;
    }

    // DDL on MySQL is auto-committed, so wrapping in BEGIN/COMMIT doesn't
    // actually let us roll back a half-finished ALTER. Run it straight and
    // classify the result.
    $ddl_ok       = false;
    $ddl_already  = false;
    $ddl_err      = null;
    try {
        $pdo->exec($sql);
        $ddl_ok = true;
    } catch (PDOException $e) {
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if (in_array($code, TOLERABLE_DDL_CODES, true)) {
            // The schema already matches what this migration would create.
            // Record it as applied — the desired end-state is what matters.
            $ddl_already = true;
        } else {
            $ddl_err = $e->getMessage();
        }
    }

    if ($ddl_ok || $ddl_already) {
        try {
            $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")->execute([$name]);
            if ($ddl_already) {
                echo "reconciled (schema already matched)\n";
                $reconciled++;
            } else {
                echo "ok\n";
                $ran++;
            }
        } catch (PDOException $e) {
            echo "FAILED to record in schema_migrations\n";
            fwrite(STDERR, "  {$e->getMessage()}\n");
            $failed++;
            break;
        }
    } else {
        echo "FAILED\n";
        fwrite(STDERR, "  {$ddl_err}\n");
        $failed++;
        break; // stop on first real failure so we don't apply later migrations against an inconsistent schema
    }
}

if ($ran === 0 && $reconciled === 0 && $failed === 0) {
    echo "Nothing to do — schema is up to date.\n";
} else {
    $parts = [];
    if ($ran        > 0) $parts[] = "{$ran} applied";
    if ($reconciled > 0) $parts[] = "{$reconciled} reconciled";
    if ($failed     > 0) $parts[] = "{$failed} failed";
    echo implode(", ", $parts) . ".\n";
}

exit($failed > 0 ? 1 : 0);
