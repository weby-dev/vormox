<?php
$host = '10.0.0.10';
$db   = 'vormox_db';
$user = 'vormox_db';
$pass = '9bU^6Jt-J%!swT,d[m%';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}
?>
