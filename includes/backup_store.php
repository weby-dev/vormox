<?php
// includes/backup_store.php
//
// Shared backup naming + formatting + S3-retention helpers used by
// cron/db_backup.php, admin/backups.php, backups.php and backup_download.php.
// Keeping the S3 key layout in one place guarantees the cron, the dashboards
// and the download flow all agree on where an object lives.

require_once __DIR__ . '/s3_client.php';

if (!function_exists('backup_subscription_folder')) {

    /** Top-level S3 folder for a subscription = sanitised panel domain. */
    function backup_subscription_folder(string $domain): string {
        $f = preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
        return trim($f, '_') ?: 'panel';
    }

    /** Day folder from a "YYYY-MM-DD_HH-MM-SS" timestamp. */
    function backup_date_folder(string $ts): string {
        return substr($ts, 0, 10);
    }

    /** Full S3 object key: <sub>/<YYYY-MM-DD>/<db>_<ts>.sql.xz */
    function backup_object_key(string $domain, string $dbName, string $ts): string {
        $sub  = backup_subscription_folder($domain);
        $day  = backup_date_folder($ts);
        $db   = preg_replace('/[^A-Za-z0-9._-]/', '_', $dbName);
        return "{$sub}/{$day}/{$db}_{$ts}.sql.xz";
    }

    /** Suggested download filename for a key (last path segment). */
    function backup_download_name(string $s3Key): string {
        $base = basename($s3Key);
        return $base !== '' ? $base : 'backup.sql.xz';
    }

    /** Human-readable byte size. */
    function backup_human_size(?int $bytes): string {
        if ($bytes === null) return '—';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $b = (float) $bytes; $i = 0;
        while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
        return round($b, ($b < 10 && $i > 0) ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * Retention: keep the newest $keep .sql.xz objects under <sub>/ in S3,
     * delete the rest (and prune their DB rows). Keys embed the timestamp, so
     * lexicographic desc == newest first. Returns ['pruned'=>int].
     */
    function backup_prune_subscription(PDO $pdo, string $subscriptionFolder, int $keep = 7): array {
        if (!s3_configured()) return ['pruned' => 0];

        $objects = [];
        $token = null;
        do {
            $r = s3_list($subscriptionFolder . '/', 1000, $token);
            if (!$r['ok']) break;
            foreach ($r['objects'] as $o) {
                if (substr($o['key'], -7) === '.sql.xz') $objects[] = $o;
            }
            $token = $r['next'];
        } while ($token);

        usort($objects, fn($a, $b) => strcmp($b['key'], $a['key'])); // newest first
        $pruned = 0;
        foreach (array_slice($objects, $keep) as $old) {
            $d = s3_delete($old['key']);
            if ($d['ok']) {
                try { $pdo->prepare("DELETE FROM backups WHERE s3_key = ?")->execute([$old['key']]); } catch (PDOException $e) {}
                $pruned++;
            }
        }
        return ['pruned' => $pruned];
    }
}
