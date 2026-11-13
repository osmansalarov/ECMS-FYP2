<?php
declare(strict_types=1);
/**
 * ECMS database connection.
 * Credentials come from the .env file in the project root (never hardcode secrets).
 * Defaults match a fresh MAMPP install (root, empty password) for local demo only.
 */
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$envFile = dirname(__DIR__) . '/.env';
$env = is_file($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];

try {
    $conn = new mysqli(
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_USER'] ?? 'root',
        $env['DB_PASS'] ?? '',
        $env['DB_NAME'] ?? 'ecms_prototype',
        isset($env['DB_PORT']) ? (int)$env['DB_PORT'] : 3306
    );
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('ECMS DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Service temporarily unavailable. Please check that MySQL is running and the database is imported.');
}
