<?php
$host = '127.0.0.1';
$port = '3306';
$dbname = 'enrollmentsystemfinal';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Drop and recreate database
    $pdo->exec("DROP DATABASE IF EXISTS `$dbname`");
    $pdo->exec("CREATE DATABASE `$dbname` DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    echo "Database recreated.\n";
    
    $pdo->exec("USE `$dbname`");
    
    // Read and execute SQL file
    $sql = file_get_contents(__DIR__ . '/enrollmentsystem.sql');
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = explode(';', $sql);
    
    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (Exception $e) {
            echo "Error: " . substr($stmt, 0, 50) . " - " . $e->getMessage() . "\n";
        }
    }
    echo "Executed $count statements.\n";
    
    // Verify
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . implode(', ', $tables) . "\n";
    
} catch (Exception $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
}
