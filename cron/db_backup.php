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
require_once __DIR__ . '/../includes/s3_client.php';
require_once __DIR__ . '/../includes/backup_store.php';

cron_log("== db_backup start ==");
if (!s3_configured()) {
    cron_log("WARNING: S3 not configured (S3_* env) — backups will email only, not upload to S3.");
}

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

    // Deterministic timestamp + S3 key so PHP knows exactly where the dump lands.
    $ts     = date('Y-m-d_H-i-s');
    $s3_key = backup_object_key($p['domain'], $p['db_name'], $ts);
    $sub    = backup_subscription_folder($p['domain']);

    // Track this backup in the DB (pending → uploaded|failed).
    $backup_id = null;
    try {
        $ins = $pdo->prepare("
            INSERT INTO backups (panel_id, user_id, subscription, db_name, s3_key, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $ins->execute([$p['id'], $p['user_id'], $sub, $p['db_name'], $s3_key]);
        $backup_id = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        cron_log("  · {$tag} could not record backup row: " . $e->getMessage());
    }

    // Presigned PUT URL — the backend host uploads straight to S3 with this, so
    // the S3 secret never leaves the web server. Expiry covers dump + upload.
    $put_url = s3_configured() ? s3_presign_put($s3_key, $per_script_timeout + 600) : '';

    $script  = vormox_render_backup_script($p, $recipients, $zepto_key, $from_email, $from_name, $ts, $put_url);
    $outcome = vormox_run_remote_backup($p, $script, $per_script_timeout);

    // S3 HEAD is the authoritative check that the object actually landed.
    $head = s3_configured() ? s3_head($s3_key) : ['exists' => false, 'size' => 0];

    if ($head['exists']) {
        if ($backup_id) {
            $pdo->prepare("UPDATE backups SET status='uploaded', size_bytes=?, uploaded_at=NOW() WHERE id=?")
                ->execute([$head['size'], $backup_id]);
        }
        cron_log("  ✓ {$tag} in S3 ({$s3_key}, " . backup_human_size($head['size']) . "). " . $outcome['summary']);
        $ok++;
        // Retention: keep newest 7 per subscription in S3 (+ prune their DB rows).
        try {
            $pr = backup_prune_subscription($pdo, $sub, 7);
            if (!empty($pr['pruned'])) cron_log("    · pruned {$pr['pruned']} old S3 backup(s) for {$sub}");
        } catch (Throwable $e) {
            cron_log("    · prune error for {$sub}: " . $e->getMessage());
        }
    } else {
        if ($backup_id) {
            $err = $outcome['ok'] ? 'S3 object not found after run' : $outcome['summary'];
            $pdo->prepare("UPDATE backups SET status='failed', error=? WHERE id=?")
                ->execute([substr($err, 0, 1000), $backup_id]);
        }
        cron_log("  ✗ {$tag} backup FAILED (S3): " . ($outcome['ok'] ? 'object missing after run' : $outcome['summary']));
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
function vormox_render_backup_script(array $p, array $recipients, string $zepto_key, string $from_email, string $from_name, string $ts, string $s3_put_url): string
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
    $TS_FIXED   = $sh($ts);
    $S3_PUT_URL = $sh($s3_put_url);

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
S3_PUT_URL={$S3_PUT_URL}

BACKUP_DIR={$BACKUP_DIR}
LOG_FILE={$LOG_FILE}
MAX_EMAIL_BYTES=\$((14 * 1024 * 1024))

TIMESTAMP={$TS_FIXED}
RAW_FILE="\${BACKUP_DIR}/\${DB_NAME}_\${TIMESTAMP}.sql"
BACKUP_FILE="\${RAW_FILE}.xz"
HOSTNAME_TXT=\$(hostname)

mkdir -p "\$BACKUP_DIR" "\$(dirname "\$LOG_FILE")"

log() { echo "[\$(date '+%Y-%m-%d %H:%M:%S')] \$1" | tee -a "\$LOG_FILE"; }

# Ensure required tools — auto-install the missing ones (Debian/Ubuntu) instead
# of bailing out. First run on a fresh box installs the mysql client etc.
ensure_tools() {
    local missing=()
    local bin
    for bin in mysql mysqldump xz curl base64; do
        command -v "\$bin" >/dev/null 2>&1 || missing+=("\$bin")
    done
    [[ \${#missing[@]} -eq 0 ]] && return 0

    log "Missing tools: \${missing[*]} — attempting install via apt-get..."
    export DEBIAN_FRONTEND=noninteractive
    local APT_OPTS='-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold'
    apt-get update -y >>"\$LOG_FILE" 2>&1 || true

    local pkgs=()
    for bin in "\${missing[@]}"; do
        case "\$bin" in
            mysql|mysqldump) pkgs+=("default-mysql-client");;
            xz)              pkgs+=("xz-utils");;
            curl)            pkgs+=("curl");;
            base64)          pkgs+=("coreutils");;
        esac
    done
    local uniq
    uniq=\$(printf '%s\\n' "\${pkgs[@]}" | sort -u | tr '\\n' ' ')
    log "Installing: \$uniq"
    apt-get install -y \$APT_OPTS \$uniq >>"\$LOG_FILE" 2>&1 || true

    local still=()
    for bin in "\${missing[@]}"; do
        command -v "\$bin" >/dev/null 2>&1 || still+=("\$bin")
    done
    # NOTE: on_error/send_email aren't defined yet at this point in the script,
    # so fail with a plain log+exit (matching the original tool-check behaviour).
    if [[ \${#still[@]} -gt 0 ]]; then
        log "ERROR: required tools still missing after install: \${still[*]}"
        exit 1
    fi
    log "Tools installed OK."
}
ensure_tools

send_email() {
    local subject="\$1"
    local htmlbody="\$2"
    local attachment_file="\${3:-}"   # PATH to the file (NOT base64) — python reads it
    local attachment_name="\${4:-}"

    local payload_file
    payload_file=\$(mktemp /tmp/vormox_zepto.XXXXXX.json)

    # Build the JSON with python3, which reads the (possibly large) attachment
    # straight from disk and base64-encodes it itself. Only small values pass
    # through the environment, so we never hit E2BIG ("Argument list too long")
    # no matter how big the dump is.
    if command -v python3 >/dev/null 2>&1; then
        FROM_EMAIL="\$FROM_EMAIL" FROM_NAME="\$FROM_NAME" \\
        SUBJECT="\$subject" HTMLBODY="\$htmlbody" \\
        ATTACHMENT_NAME="\$attachment_name" ATTACHMENT_FILE="\$attachment_file" \\
        TO_RECIPIENTS_B64="\$TO_RECIPIENTS_B64" \\
        python3 - "\$payload_file" <<'PYEOF'
import json, os, sys, base64
payload_file = sys.argv[1]
to_list = json.loads(base64.b64decode(os.environ["TO_RECIPIENTS_B64"]))
out = {
    "from": {"address": os.environ["FROM_EMAIL"], "name": os.environ["FROM_NAME"]},
    "to":   to_list,
    "subject":  os.environ["SUBJECT"],
    "htmlbody": os.environ["HTMLBODY"],
}
att_file = os.environ.get("ATTACHMENT_FILE", "")
if att_file and os.path.isfile(att_file):
    with open(att_file, "rb") as f:
        out["attachments"] = [{
            "content":   base64.b64encode(f.read()).decode(),
            "mime_type": "application/x-xz",
            "name":      os.environ.get("ATTACHMENT_NAME", "backup.sql.xz"),
        }]
with open(payload_file, "w") as f:
    json.dump(out, f)
PYEOF
    else
        # No python3 → send the notification WITHOUT the attachment (small + safe).
        # The S3 copy is the source of truth anyway.
        local s_esc h_esc
        s_esc=\$(printf '%s' "\$subject"  | sed 's/\\\\/\\\\\\\\/g; s/"/\\\\"/g')
        h_esc=\$(printf '%s' "\$htmlbody" | sed 's/\\\\/\\\\\\\\/g; s/"/\\\\"/g' | tr '\n' ' ')
        {
            printf '{"from":{"address":"%s","name":"%s"},' "\$FROM_EMAIL" "\$FROM_NAME"
            printf '"to":%s,' "\$(echo "\$TO_RECIPIENTS_B64" | base64 -d)"
            printf '"subject":"%s","htmlbody":"%s"}' "\$s_esc" "\$h_esc"
        } > "\$payload_file"
    fi

    local http
    http=\$(curl -s -o /dev/null -w '%{http_code}' \\
        -X POST "https://api.zeptomail.in/v1.1/email" \\
        -H "Accept: application/json" \\
        -H "Content-Type: application/json" \\
        -H "Authorization: \${ZEPTO_API_KEY}" \\
        --data-binary "@\${payload_file}")

    rm -f "\$payload_file"

    if [[ "\$http" =~ ^2 ]]; then
        log "ZeptoMail OK (HTTP \$http)"
        return 0
    else
        log "ZeptoMail FAILED (HTTP \$http)"
        return 1
    fi
}

on_error() {
    local err="\$1"
    log "ERROR: \$err"
    send_email "❌ Backup FAILED — \$DB_NAME (\$DOMAIN)" \\
        "<h3>Backup Failed</h3><p><b>Database:</b> \$DB_NAME</p><p><b>Domain:</b> \$DOMAIN</p><p><b>Host:</b> \$HOSTNAME_TXT</p><p><b>Time:</b> \$TIMESTAMP</p><p><b>Error:</b> \$err</p>" || true
    rm -f "\$RAW_FILE" "\$BACKUP_FILE"
    exit 1
}
trap 'on_error "Script terminated unexpectedly at line \$LINENO"' ERR

log "=== Starting backup of \$DB_NAME (\$DOMAIN) ==="

# 1. Pre-flight — resolve a reachable DB host. Some panels' databases only
#    accept connections from the backend host itself, so if the configured host
#    is unreachable (or misconfigured) we transparently retry on localhost.
DB_HOSTS_TRY=("\$DB_HOST")
if [[ "\$DB_HOST" != "127.0.0.1" && "\$DB_HOST" != "localhost" ]]; then
    DB_HOSTS_TRY+=("127.0.0.1")
fi
RESOLVED_HOST=""
FIRST_CONNECT=""
for H in "\${DB_HOSTS_TRY[@]}"; do
    EXISTS=\$(mysql --host="\$H" --user="\$DB_USER" --password="\$DB_PASS" \\
          -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='\$DB_NAME';" 2>/dev/null) || continue
    [[ -z "\$FIRST_CONNECT" ]] && FIRST_CONNECT="\$H"
    if [[ "\${EXISTS:-0}" -gt 0 ]]; then RESOLVED_HOST="\$H"; break; fi
done
if [[ -z "\$RESOLVED_HOST" ]]; then
    [[ -n "\$FIRST_CONNECT" ]] && on_error "Database '\$DB_NAME' not found on any reachable host (connected to: \$FIRST_CONNECT)"
    on_error "Cannot connect to MySQL for '\$DB_NAME' (tried: \${DB_HOSTS_TRY[*]})"
fi
if [[ "\$RESOLVED_HOST" != "\$DB_HOST" ]]; then
    log "Configured DB host \$DB_HOST unreachable/missing — using \$RESOLVED_HOST instead"
    DB_HOST="\$RESOLVED_HOST"
fi

TABLE_COUNT=\$(mysql --host="\$DB_HOST" --user="\$DB_USER" --password="\$DB_PASS" \\
    -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='\$DB_NAME';" 2>/dev/null) \\
    || on_error "Cannot query table list on \$DB_HOST"
FK_COUNT=\$(mysql --host="\$DB_HOST" --user="\$DB_USER" --password="\$DB_PASS" \\
    -N -B -e "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='\$DB_NAME';" 2>/dev/null) \\
    || on_error "Cannot query foreign key info"
log "Database has \$TABLE_COUNT tables and \$FK_COUNT foreign keys (host: \$DB_HOST)"

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

# 4b. Upload to S3 (presigned PUT). Non-fatal: the email copy still goes out,
# and the web server confirms the object via HEAD after this script returns.
if [[ -n "\$S3_PUT_URL" ]]; then
    log "Uploading to S3..."
    S3_CODE=\$(curl -sS -o /dev/null -w '%{http_code}' -X PUT -T "\$BACKUP_FILE" \\
        -H "Content-Type: application/x-xz" "\$S3_PUT_URL" 2>>"\$LOG_FILE" || echo "000")
    if [[ "\$S3_CODE" =~ ^2 ]]; then
        log "S3 upload OK (HTTP \$S3_CODE)"
    else
        log "WARNING: S3 upload failed (HTTP \$S3_CODE) — email copy still sent"
    fi
else
    log "S3 not configured — skipping upload (email only)"
fi

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
    ATTACHMENT_NAME=\$(basename "\$BACKUP_FILE")
    # Pass the FILE PATH (not base64) — python reads + encodes it from disk.
    send_email "✅ DB Backup — \$DOMAIN — \$TIMESTAMP" "\$EMAIL_BODY" "\$BACKUP_FILE" "\$ATTACHMENT_NAME" \\
        || on_error "ZeptoMail send (with attachment) failed"
else
    log "File too large (\$FINAL_SIZE > 14MB) — sending notice only (S3 copy retained)."
    NOTE="\$EMAIL_BODY<hr><p><b>⚠️ File too large to attach (\$FINAL_SIZE).</b></p><p>The full backup is stored in S3 — download it from the Vormox dashboard.</p>"
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
