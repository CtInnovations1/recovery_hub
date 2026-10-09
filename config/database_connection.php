<?php
declare(strict_types=1);
$databaseSettings = is_file(__DIR__.'/database.local.php') ? require __DIR__.'/database.local.php' : [];
$DB_HOST = $databaseSettings['host'] ?? '127.0.0.1';
$DB_NAME = $databaseSettings['name'] ?? '';
$DB_USER = $databaseSettings['user'] ?? '';
$DB_PASS = $databaseSettings['password'] ?? '';
try {
    // Local development can override the hosting configuration without changing it.
    $DB_HOST = getenv('RECOVERY_DB_HOST') ?: $DB_HOST;
    $DB_NAME = getenv('RECOVERY_DB_NAME') ?: $DB_NAME;
    $DB_USER = getenv('RECOVERY_DB_USER') ?: $DB_USER;
    $DB_PASS = getenv('RECOVERY_DB_PASSWORD') !== false ? getenv('RECOVERY_DB_PASSWORD') : $DB_PASS;
    $DB_PORT = (int) (getenv('RECOVERY_DB_PORT') ?: ($databaseSettings['port'] ?? 3306));
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Recovery Hub DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}
