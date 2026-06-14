<?php
// cron/db_backup.php
//
// Twice-a-day per-panel MySQL backup. For every active panel with backend
// SSH credentials AND DB credentials we:
//
//   1. SSH into the panel's BE host
//   2. Template a bash script (based on the user-supplied backup.sh pattern:
//      FK-safe mysqldump, xz -9 -e -T 0 compression, integrity verification,
//      retention pruning) with the panel's specific DB creds + recipient list
//   3. base64-ship it to /tmp on the backend and run it synchronously
//   4. The script itself emails the artifact via ZeptoMail to:
//         • every BACKUP_ADMIN_EMAILS in .env (comma-separated, with names
//           via "addr|Display Name")
//         • the panel owner's email
//      Falls back to "notification only" if the compressed dump exceeds 14MB
//      (ZeptoMail's attachment ceiling).
//
// Recommended schedule:   0 2,14 * * *      (02:00 and 14:00 — 12h apart,
//                                            avoids the auto_renew cron at 03:00)
//
// Per-script timeout: 12 minutes (Bash + mysqldump + xz extreme can be slow).

require_once __DIR__ . '/_bootstrap.php';

cron_log("== db_backup start ==");

// --- Admin recipients come from the admins table -------------------------
// Every row in `admins` gets a copy of every backup (alongside the panel's
// own customer). No .env duplication — the source of truth is the DB.
$admin_recipients = [];
try {
    $aStmt = $pdo->query("SELECT first_name, last_name, email FROM admins WHERE email IS NOT NULL AND email <> ''");
    foreach ($aStmt->fetchAll() as $a) {
        $admin_recipients[] = [
            'addr' => $a['email'],
            'name' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'Vormox Admin',
        ];
    }
} catch (PDOException $e) {
    cron_log("ABORT: could not read admins table: " . $e->getMessage());
    exit(1);
}

// Mail transport config — these stay in .env because they're infrastructure
// (the ZeptoMail key + envelope sender), not per-business data.
$zepto_key   = (string) vormox_env('ZEPTOMAIL_AUTH', '');
$from_email  = (string) vormox_env('MAIL_FROM_ADDRESS', 'noreply@getwebup.com');
$from_name   = (string) vormox_env('MAIL_FROM_NAME', 'Vormox Backup System');
$per_script_timeout = (int) vormox_env('BACKUP_PER_PANEL_TIMEOUT_SEC', 720); // 12 min

if ($zepto_key === '') {
    cron_log("ABORT: ZEPTOMAIL_AUTH not set in .env — can't send backup emails.");
    exit(1);
}
if (empty($admin_recipients)) {
    cron_log("WARNING: admins table has no rows with an email — only the customer will receive backups.");
} else {
    cron_log("Admin recipients (" . count($admin_recipients) . "): " . implode(', ', array_column($admin_recipients, 'addr')));
}

// --- Candidate panels ----------------------------------------------------
// Active panels with every column we need to run the backup. Anything
// missing → skipped silently with a log note.
try {
    $stmt = $pdo->query("
        SELECT p.id, p.domain, p.user_id,
               pd.be_server_ip, pd.be_ssh_port, pd.be_ssh_user, pd.be_ssh_pass,
               pd.db_server_ip, pd.db_name,    pd.db_user,      pd.db_pass,
               u.email AS user_email, u.first_name, u.last_name
          FROM user_panels   p
          JOIN panel_details pd ON pd.panel_id = p.id
          JOIN users         u  ON u.id        = p.user_id
         WHERE p.status = 'active'
    ");
    $panels = $stmt->fetchAll();
} catch (PDOException $e) {
    cron_log("Candidate query failed: " . $e->getMessage());
    exit(1);
}

cron_log("Considering " . count($panels) . " active panel(s).");

$ok = 0; $skipped = 0; $failed = 0;
foreach ($panels as $p) {
    $tag = "[{$p['domain']}]";

    $required = ['be_server_ip','be_ssh_user','be_ssh_pass',
                 'db_server_ip','db_name','db_user','db_pass','user_email'];
    $missing = [];
    foreach ($required as $col) {
        if (empty($p[$col])) $missing[] = $col;
    }
    if ($missing) {
        cron_log("  · {$tag} skipped — missing: " . implode(', ', $missing));
        $skipped++;
        continue;
    }

    // Recipient list = admins + this panel's owner
    $recipients = $admin_recipients;
    $customer_name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: 'Customer';
    $recipients[] = ['addr' => $p['user_email'], 'name' => $customer_name];

    $script  = vormox_render_backup_script($p, $recipients, $zepto_key, $from_email, $from_name);
    $outcome = vormox_run_remote_backup($p, $script, $per_script_timeout);

    if ($outcome['ok']) {
        cron_log("  ✓ {$tag} backup sent. " . $outcome['summary']);
        $ok++;
    } else {
        cron_log("  ✗ {$tag} backup FAILED: " . $outcome['summary']);
        $failed++;
    }
}

cron_log("Done: {$ok} ok, {$skipped} skipped, {$failed} failed.");
cron_log("== db_backup done ==");
exit($failed > 0 ? 1 : 0);


// =========================================================================
// Helpers
// =========================================================================

/**
 * Build the bash backup script for one panel. Mirrors the user-supplied
 * backup.sh pattern: FK-safe mysqldump, xz -9 -e -T 0 compression, integrity
 * verification, 14MB attachment ceiling, retention prune.
 *
 * All credentials get base64'd onto the wire so password special chars
 * ($, !, ", `, etc.) can't break shell quoting.
 */
function vormox_render_backup_script(array $p, array $recipients, string $zepto_key, string $from_email, string $from_name): string
{
    // Build the JSON "to" array fragment — assembled bash-side from a base64'd JSON.
    $to_json = [];
    foreach ($recipients as $r) {
        $to_json[] = ['email_address' => ['address' => $r['addr'], 'name' => $r['name']]];
    }
    $to_b64 = base64_encode(json_encode($to_json, JSON_UNESCAPED_SLASHES));

    // Per-panel paths so concurrent panels don't fight over a shared file
    $domain_safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $p['domain']);
    $panel_id    = (int) $p['id'];

    // sh-escape every interpolation
    $sh = function (string $v): string { return "'" . str_replace("'", "'\\''", $v) . "'"; };

    $DB_NAME    = $sh($p['db_name']);
    $DB_USER    = $sh($p['db_user']);
    $DB_PASS    = $sh($p['db_pass']);
    $DB_HOST    = $sh($p['db_server_ip']);
    $DOMAIN     = $sh($p['domain']);
    $ZEPTO_KEY  = $sh($zepto_key);
    $FROM_EMAIL = $sh($from_email);
    $FROM_NAME  = $sh($from_name);
    $BACKUP_DIR = $sh("/var/backups/vormox/{$panel_id}");
    $LOG_FILE   = $sh("/var/log/vormox/{$domain_safe}-backup.log");
    $TO_B64     = $sh($to_b64);

    // Note: the user's original script's tail-check looked for "Dump completed"
    // OR foreign-key-checks restore — keep that liberal check.
    return <<<BASH
#!/bin/bash
# Auto-generated MySQL backup for {$p['domain']} (panel {$panel_id})
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

DB_NAME={$DB_NAME}
DB_USER={$DB_USER}
DB_PASS={$DB_PASS}
DB_HOST={$DB_HOST}
DOMAIN={$DOMAIN}
PANEL_ID={$panel_id}

ZEPTO_API_KEY={$ZEPTO_KEY}
FROM_EMAIL={$FROM_EMAIL}
FROM_NAME={$FROM_NAME}
TO_RECIPIENTS_B64={$TO_B64}

BACKUP_DIR={$BACKUP_DIR}
LOG_FILE={$LOG_FILE}
MAX_EMAIL_BYTES=\$((14 * 1024 * 1024))

TIMESTAMP=\$(date +"%Y-%m-%d_%H-%M-%S")
RAW_FILE="\${BACKUP_DIR}/\${DB_NAME}_\${TIMESTAMP}.sql"
BACKUP_FILE="\${RAW_FILE}.xz"
HOSTNAME_TXT=\$(hostname)

mkdir -p "\$BACKUP_DIR" "\$(dirname "\$LOG_FILE")"

log() { echo "[\$(date '+%Y-%m-%d %H:%M:%S')] \$1" | tee -a "\$LOG_FILE"; }

# Ensure required tools
for bin in mysql mysqldump xz curl base64; do
    command -v "\$bin" >/dev/null 2>&1 || { log "ERROR: '\$bin' not installed"; exit 1; }
done

send_email() {
    local subject="\$1"
    local htmlbody="\$2"
    local attachment_b64="\${3:-}"
    local attachment_name="\${4:-}"

    local payload_file
    payload_file=\$(mktemp /tmp/vormox_zepto.XXXXXX.json)

    # Use python3 for safe JSON encoding (handles every special char correctly).
    # Falls back to a minimal sed-escape if python3 isn't available.
    if command -v python3 >/dev/null 2>&1; then
        python3 - "\$payload_file" <<PYEOF
import json, os, sys, base64
payload_file = sys.argv[1]
to_list = json.loads(base64.b64decode(os.environ["TO_RECIPIENTS_B64"]))
out = {
    "from": {"address": os.environ["FROM_EMAIL"], "name": os.environ["FROM_NAME"]},
    "to":   to_list,
    "subject":  os.environ["SUBJECT"],
    "htmlbody": os.environ["HTMLBODY"],
}
att = os.environ.get("ATTACHMENT_B64", "")
if att:
    out["attachments"] = [{
        "content":   att,
        "mime_type": "application/x-xz",
        "name":      os.environ["ATTACHMENT_NAME"],
    }]
with open(payload_file, "w") as f:
    json.dump(out, f)
PYEOF
    else
        # Minimal fallback — covers most cases but won't survive curly braces in subject.
        local s_esc h_esc
        s_esc=\$(printf '%s' "\$subject"  | sed 's/\\\\/\\\\\\\\/g; s/"/\\\\"/g')
        h_esc=\$(printf '%s' "\$htmlbody" | sed 's/\\\\/\\\\\\\\/g; s/"/\\\\"/g' | tr '\n' ' ')
        {
            printf '{"from":{"address":"%s","name":"%s"},' "\$FROM_EMAIL" "\$FROM_NAME"
            printf '"to":%s,' "\$(echo "\$TO_RECIPIENTS_B64" | base64 -d)"
            printf '"subject":"%s","htmlbody":"%s"' "\$s_esc" "\$h_esc"
            if [[ -n "\$attachment_b64" ]]; then
                printf ',"attachments":[{"content":"%s","mime_type":"application/x-xz","name":"%s"}]' \\
                    "\$attachment_b64" "\$attachment_name"
            fi
            printf '}'
        } > "\$payload_file"
    fi

    SUBJECT="\$subject" HTMLBODY="\$htmlbody" \\
    ATTACHMENT_B64="\$attachment_b64" ATTACHMENT_NAME="\$attachment_name" \\
    TO_RECIPIENTS_B64="\$TO_RECIPIENTS_B64" \\
    FROM_EMAIL="\$FROM_EMAIL" FROM_NAME="\$FROM_NAME" \\
    : # vars now exported into Python's env via the `XYZ=val python3 ...` pattern handled above

    local resp http
    resp=\$(curl -s -o /dev/null -w '%{http_code}' \\
        -X POST "https://api.zeptomail.in/v1.1/email" \\
        -H "Accept: application/json" \\
        -H "Content-Type: application/json" \\
        -H "Authorization: \${ZEPTO_API_KEY}" \\
        --data-binary "@\${payload_file}")
    http="\$resp"

    rm -f "\$payload_file"

    if [[ "\$http" =~ ^2 ]]; then
        log "ZeptoMail OK (HTTP \$http)"
        return 0
    else
        log "ZeptoMail FAILED (HTTP \$http)"
        return 1
    fi
}

# Export so the Python block can see them
export TO_RECIPIENTS_B64 FROM_EMAIL FROM_NAME

on_error() {
    local err="\$1"
    log "ERROR: \$err"
    SUBJECT="❌ Backup FAILED — \$DB_NAME (\$DOMAIN)" \\
    HTMLBODY="<h3>Backup Failed</h3><p><b>Database:</b> \$DB_NAME</p><p><b>Domain:</b> \$DOMAIN</p><p><b>Host:</b> \$HOSTNAME_TXT</p><p><b>Time:</b> \$TIMESTAMP</p><p><b>Error:</b> \$err</p>" \\
    send_email "❌ Backup FAILED — \$DB_NAME (\$DOMAIN)" \\
        "<h3>Backup Failed</h3><p><b>Database:</b> \$DB_NAME</p><p><b>Domain:</b> \$DOMAIN</p><p><b>Time:</b> \$TIMESTAMP</p><p><b>Error:</b> \$err</p>" || true
    rm -f "\$RAW_FILE" "\$BACKUP_FILE"
    exit 1
}
trap 'on_error "Script terminated unexpectedly at line \$LINENO"' ERR

log "=== Starting backup of \$DB_NAME (\$DOMAIN) ==="

# 1. Pre-flight
TABLE_COUNT=\$(mysql --host="\$DB_HOST" --user="\$DB_USER" --password="\$DB_PASS" \\
    -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='\$DB_NAME';" 2>/dev/null) \\
    || on_error "Cannot connect to MySQL or database '\$DB_NAME' not found at \$DB_HOST"
FK_COUNT=\$(mysql --host="\$DB_HOST" --user="\$DB_USER" --password="\$DB_PASS" \\
    -N -B -e "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='\$DB_NAME';" 2>/dev/null) \\
    || on_error "Cannot query foreign key info"
log "Database has \$TABLE_COUNT tables and \$FK_COUNT foreign keys"

# 2. Dump (FK/index safe)
log "Dumping database..."
mysqldump \\
    --host="\$DB_HOST" --user="\$DB_USER" --password="\$DB_PASS" \\
    --single-transaction --quick --lock-tables=false \\
    --routines --triggers --events \\
    --add-drop-table --add-drop-trigger --create-options \\
    --extended-insert --complete-insert --hex-blob \\
    --default-character-set=utf8mb4 \\
    --set-gtid-purged=OFF --no-tablespaces \\
    "\$DB_NAME" > "\$RAW_FILE" 2>>"\$LOG_FILE" \\
    || on_error "mysqldump failed — see \$LOG_FILE"

# 3. Verify dump
[[ -s "\$RAW_FILE" ]] || on_error "Dump file is empty"
TAIL_CONTENT=\$(tail -c 200 "\$RAW_FILE")
if ! echo "\$TAIL_CONTENT" | grep -qE "(Dump completed|FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS|UNLOCK TABLES|^;)"; then
    on_error "Dump appears truncated — end-of-file marker not found"
fi
DUMPED_TABLES=\$(grep -c "^CREATE TABLE" "\$RAW_FILE" || true)
log "Dump contains \$DUMPED_TABLES CREATE TABLE statements (expected \$TABLE_COUNT)"
if (( DUMPED_TABLES < TABLE_COUNT )); then
    on_error "Table count mismatch — dumped \$DUMPED_TABLES, expected \$TABLE_COUNT"
fi
log "Raw SQL dump verified: \$(du -h "\$RAW_FILE" | cut -f1)"

# 4. Compress
log "Compressing with xz -9 -e -T 0..."
xz -9 -e -T 0 -f "\$RAW_FILE" || on_error "xz compression failed"
xz -t "\$BACKUP_FILE" || on_error "Compressed file failed integrity test"
FINAL_SIZE_BYTES=\$(stat -c%s "\$BACKUP_FILE")
FINAL_SIZE=\$(du -h "\$BACKUP_FILE" | cut -f1)
log "Compressed: \$BACKUP_FILE (\$FINAL_SIZE)"

# 5. Email
EMAIL_BODY="<h3>✅ Database Backup Successful</h3>
<table cellpadding='6' style='border-collapse:collapse'>
<tr><td><b>Domain:</b></td><td>\$DOMAIN</td></tr>
<tr><td><b>Database:</b></td><td>\$DB_NAME</td></tr>
<tr><td><b>Host:</b></td><td>\$HOSTNAME_TXT</td></tr>
<tr><td><b>Tables:</b></td><td>\$TABLE_COUNT</td></tr>
<tr><td><b>Foreign Keys:</b></td><td>\$FK_COUNT</td></tr>
<tr><td><b>Compressed Size:</b></td><td>\$FINAL_SIZE</td></tr>
<tr><td><b>Timestamp:</b></td><td>\$TIMESTAMP</td></tr>
</table>
<p><b>Restore command:</b><br><code>xz -d &lt; \${DB_NAME}_\${TIMESTAMP}.sql.xz | mysql -u USER -p \$DB_NAME</code></p>"

if (( FINAL_SIZE_BYTES <= MAX_EMAIL_BYTES )); then
    log "File fits — attaching and sending..."
    ATTACHMENT_B64=\$(base64 -w 0 "\$BACKUP_FILE")
    ATTACHMENT_NAME=\$(basename "\$BACKUP_FILE")
    SUBJECT="✅ DB Backup — \$DOMAIN — \$TIMESTAMP" HTMLBODY="\$EMAIL_BODY" \\
    ATTACHMENT_B64="\$ATTACHMENT_B64" ATTACHMENT_NAME="\$ATTACHMENT_NAME" \\
    send_email "✅ DB Backup — \$DOMAIN — \$TIMESTAMP" "\$EMAIL_BODY" "\$ATTACHMENT_B64" "\$ATTACHMENT_NAME" \\
        || on_error "ZeptoMail send (with attachment) failed"
else
    log "File too large (\$FINAL_SIZE > 14MB) — sending notice only."
    NOTE="\$EMAIL_BODY<hr><p><b>⚠️ File too large to attach (\$FINAL_SIZE).</b></p><p><b>Server path:</b> <code>\$BACKUP_FILE</code></p><p>Pull via SCP/SFTP or set up cloud storage upload.</p>"
    SUBJECT="⚠️ DB Backup created (too large) — \$DOMAIN — \$TIMESTAMP" HTMLBODY="\$NOTE" \\
    send_email "⚠️ DB Backup created (too large) — \$DOMAIN — \$TIMESTAMP" "\$NOTE" \\
        || on_error "ZeptoMail send (notice) failed"
fi

# 6. Retention — keep the most-recent 7 backups for this panel
log "Pruning old backups (keep last 7)..."
ls -1t "\$BACKUP_DIR"/\${DB_NAME}_*.sql.xz 2>/dev/null | tail -n +8 | xargs -r rm -f

log "=== Backup completed successfully ==="
exit 0
BASH;
}

/**
 * SSH into the panel's BE host, ship the script, run it synchronously,
 * collect stdout for diagnostics.
 */
function vormox_run_remote_backup(array $p, string $script, int $timeout_sec): array
{
    if (!function_exists('ssh2_connect')) {
        return ['ok' => false, 'summary' => 'PHP ssh2 extension missing on web server'];
    }
    $ip   = $p['be_server_ip'];
    $port = (int) ($p['be_ssh_port'] ?: 22);

    // Fast TCP precheck so an unreachable host fails in seconds
    $errno = 0; $errstr = '';
    $probe = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 5);
    if (!$probe) {
        return ['ok' => false, 'summary' => "host unreachable: {$ip}:{$port} ({$errstr})"];
    }
    fclose($probe);

    $conn = @ssh2_connect($ip, $port);
    if (!$conn) {
        return ['ok' => false, 'summary' => 'SSH handshake failed'];
    }
    if (!@ssh2_auth_password($conn, $p['be_ssh_user'], $p['be_ssh_pass'])) {
        return ['ok' => false, 'summary' => 'SSH auth failed'];
    }

    $script_path = "/tmp/vormox-dbbackup-{$p['id']}.sh";
    $b64         = base64_encode($script);

    // Run synchronously and capture stdout. The script writes detailed logs
    // to its own LOG_FILE on the backend; we only surface a short summary.
    $cmd  = "printf '%s' '{$b64}' | base64 -d > {$script_path} && "
          . "chmod +x {$script_path} && "
          . "timeout " . $timeout_sec . " bash {$script_path} 2>&1; "
          . "EC=\$?; rm -f {$script_path}; "
          . "echo \"---EXIT=\$EC\"";

    $stream = @ssh2_exec($conn, $cmd);
    if (!$stream) {
        return ['ok' => false, 'summary' => 'SSH exec failed'];
    }
    stream_set_blocking($stream, true);
    stream_set_timeout($stream, $timeout_sec + 30);
    $out = (string) @stream_get_contents(@ssh2_fetch_stream($stream, SSH2_STREAM_STDIO));
    @fclose($stream);

    $exit = 0;
    if (preg_match('/---EXIT=(\d+)\s*$/', $out, $m)) {
        $exit = (int) $m[1];
        $out  = preg_replace('/---EXIT=\d+\s*$/', '', $out);
    }
    $out_tail = trim((string) substr($out, -500));

    if ($exit === 0) {
        return ['ok' => true, 'summary' => 'tables dumped + email queued.'];
    }
    if ($exit === 124) {
        return ['ok' => false, 'summary' => "timed out after {$timeout_sec}s"];
    }
    return ['ok' => false, 'summary' => "exit {$exit} — " . ($out_tail ?: 'no output')];
}
