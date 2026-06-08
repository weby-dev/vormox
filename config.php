<?php
// config.php — DB bootstrap. Secrets pulled from .env (or OS env).

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/csrf.php';
vormox_load_env(__DIR__ . '/.env');

$host    = vormox_env('DB_HOST', '127.0.0.1');
$db      = vormox_env('DB_NAME', 'vormox_db');
$user    = vormox_env('DB_USER', 'vormox_db');
$pass    = vormox_env('DB_PASS', '');
$charset = vormox_env('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    // Bail out fast if the DB host is unreachable so PHP doesn't burn the
    // whole max_execution_time on a TCP connect timeout.
    PDO::ATTR_TIMEOUT => 5,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}
