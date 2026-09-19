<?php
// src/database.php – PDO connection (env-aware)
// =============================================

require_once __DIR__ . '/config.php';

/**
 * Returns a singleton PDO connection.
 */
function getDbConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host     = DB_HOST;
    $port     = DB_PORT;
    $dbname   = DB_NAME;
    $user     = DB_USER;
    $password = DB_PASS;

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB Connection failed: ' . $e->getMessage());
        throw new PDOException('Database unavailable. Please try again later.');
    }
}