<?php
// utils/rollback.php – Rollback the last migration
// ==================================================

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';

try {
    $pdo = getDbConnection();

    // Get the last applied migration
    $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if (!$row) {
        echo "❌ No migrations to roll back.\n";
        exit(0);
    }
    $migrationClass = $row['migration'];

    // Load the migration file
    $file = __DIR__ . '/../src/migrations/versions/' . $migrationClass . '.php';
    if (!file_exists($file)) {
        echo "❌ Migration file not found: $file\n";
        exit(1);
    }
    require_once $file;
    if (!class_exists($migrationClass)) {
        echo "❌ Class $migrationClass not found.\n";
        exit(1);
    }

    /** @var Migration $migration */
    $migration = new $migrationClass($pdo);
    echo "⏪ Rolling back $migrationClass... ";
    try {
        $pdo->beginTransaction();
        $migration->down();
        // Remove the record from migrations table
        $stmt = $pdo->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$migrationClass]);
        $pdo->commit();
        echo "✅ Rollback successful.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "❌ Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}