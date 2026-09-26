<?php
// utils/migrate.php – CLI migration runner (with --force)
// =========================================================
// NOTE: bootstrap.php lives one level up, not two. The path was
// corrected in Phase 7 pre-flight. Was: /../../bootstrap.php

require_once __DIR__ . '/../bootstrap.php';

$force = in_array('--force', $argv);

try {
    $pdo = getDbConnection();

    // Ensure the migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Get applied migrations
    $applied = $pdo->query("SELECT migration FROM migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    // Scan versions directory
    $versionsDir = __DIR__ . '/../src/migrations/versions/';
    $files = glob($versionsDir . '*.php');
    sort($files);

    $pending = [];
    foreach ($files as $file) {
        $className = 'Migration_' . pathinfo($file, PATHINFO_FILENAME);
        if (!in_array($className, $applied)) {
            $pending[] = ['file' => $file, 'class' => $className];
        }
    }

    if (empty($pending)) {
        echo "✅ No pending migrations.\n";
        exit(0);
    }

    echo "📦 Found " . count($pending) . " pending migration(s):\n";
    foreach ($pending as $m) {
        echo "   - " . $m['class'] . "\n";
    }

    if (!$force) {
        echo "\nApply these migrations? (y/N): ";
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'y') {
            echo "❌ Aborted.\n";
            exit(0);
        }
    } else {
        echo "⏩ Skipping confirmation (--force).\n";
    }

    foreach ($pending as $m) {
        require_once $m['file'];
        if (!class_exists($m['class'])) {
            echo "❌ Class " . $m['class'] . " not found in file " . $m['file'] . "\n";
            exit(1);
        }
        /** @var Migration $migration */
        $migration = new $m['class']($pdo);
        echo "▶️  Applying " . $m['class'] . "... ";
        try {
            $migration->up();
            $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
            $stmt->execute([$m['class']]);
            echo "✅ Done.\n";
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    echo "🎉 All migrations applied successfully!\n";
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}