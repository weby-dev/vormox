<?php
// includes/env.php
// Minimal .env loader. No external dependencies.
//
// Usage:
//   require_once __DIR__ . '/includes/env.php';
//   vormox_load_env(__DIR__ . '/.env');
//   $key = vormox_env('ZEPTOMAIL_AUTH', 'fallback');

if (!function_exists('vormox_load_env')) {

    /**
     * Parse a .env file and populate $_ENV / getenv() with its values.
     * Existing env vars are NOT overwritten (so OS-level env wins for prod).
     * Lines starting with # are comments; surrounding "..." or '...' on values
     * are stripped.
     */
    function vormox_load_env($path) {
        if (!is_file($path) || !is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;

            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));

            // Strip surrounding quotes if present
            if (strlen($val) >= 2) {
                $first = $val[0]; $last = $val[strlen($val) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                }
            }

            if ($key === '') continue;
            if (array_key_exists($key, $_ENV) || getenv($key) !== false) continue;

            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("{$key}={$val}");
        }
    }

    /**
     * Read an env var with a fallback. Checks $_ENV, $_SERVER, and getenv()
     * (the loader populates all three, but production processes may set OS
     * env directly).
     */
    function vormox_env($key, $default = null) {
        if (array_key_exists($key, $_ENV))    return $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
        $v = getenv($key);
        return $v !== false ? $v : $default;
    }
}
