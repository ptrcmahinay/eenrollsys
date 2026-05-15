<?php
declare(strict_types=1);

/**
 * Local database configuration.
 * Update these values if your MySQL credentials are different.
 */
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'enrollmentsystemfinal';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $exception) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>Please check <code>config/db.php</code> and import <code>enrollmentsystem.sql</code>.</p>';
    echo '<pre>' . htmlspecialchars($exception->getMessage()) . '</pre>';
    exit;
}
