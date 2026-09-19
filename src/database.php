<?php
// src/database.php – Reusable PDO connection
// Include this file in any script that needs database access.

require_once __DIR__ . '/config.php';

/**
 * Returns a PDO instance connected to the database.
 * Uses singleton pattern – connection is created only once.
 *
 * @return PDO
 * @throws PDOException on connection failure
 */
function getDbConnection() {
    $host     = DB_HOST;
    $dbname   = DB_NAME;
    $user     = DB_USER;
    $password = DB_PASS;

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,  // Use native prepared statements
    ];

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Log the error (in production, log to a file instead of echoing)
        error_log('DB Connection failed: ' . $e->getMessage());
        throw new PDOException('Database unavailable. Please try again later.');
    }
}