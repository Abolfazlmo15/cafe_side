<?php
// public/_migrate.php
// =============================================================
// Web-based migration runner. Use this instead of phpMyAdmin
// when you don't have CLI access (InfinityFree).
//
// Usage:
//   /public/_migrate.php?token=YOUR_AI_CRON_TOKEN
//
// Reads every file in src/migrations/versions/, compares against
// the `migrations` table, runs anything pending. Safe to run
// repeatedly — already-applied migrations are skipped.
//
// Requires the same token as the AI cron workers. Set AI_CRON_TOKEN
// in .env on both local and server.

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = env('AI_CRON_TOKEN', '');
$givenToken    = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    echo "Set AI_CRON_TOKEN in .env, then append ?token=THAT_VALUE to this URL.\n";
    exit;
}

try {
    $pdo = getDbConnection();

    // Ensure the tracking table exists (this is what utils/migrate.php does).
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Everything already applied.
    $applied = $pdo->query("SELECT migration FROM migrations ORDER BY id")
                   ->fetchAll(PDO::FETCH_COLUMN);

    // Scan the versions directory.
    $versionsDir = __DIR__ . '/../src/migrations/versions/';
    if (!is_dir($versionsDir)) {
        http_response_code(500);
        echo "Error: migrations directory not found at {$versionsDir}\n";
        exit;
    }

    $files = glob($versionsDir . '*.php');
    sort($files);

    $pending = [];
    foreach ($files as $file) {
        $className = 'Migration_' . pathinfo($file, PATHINFO_FILENAME);
        if (!in_array($className, $applied)) {
            $pending[] = ['file' => $file, 'class' => $className];
        }
    }

    echo "=== Cafe Side - Migration Runner ===\n";
    echo "Time:  " . date('c') . "\n";
    echo "Env:   " . (defined('ACTIVE_ENV') ? ACTIVE_ENV : '?') . "\n";
    echo "DB:    " . (defined('DB_NAME') ? DB_NAME : '?') . "\n";
    echo "\n";

    if (empty($pending)) {
        echo "No pending migrations. Everything is up to date.\n";
        echo "\nDone.\n";
        exit;
    }

    echo "Found " . count($pending) . " pending migration(s):\n\n";

    $ok = 0; $failed = 0;
    foreach ($pending as $m) {
        echo "  >> {$m['class']} ... ";
        require_once $m['file'];
        if (!class_exists($m['class'])) {
            echo "FAIL (class not found in file)\n";
            $failed++;
            continue;
        }

        try {
            $migration = new $m['class']($pdo);
            $migration->up();

            $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
            $stmt->execute([$m['class']]);

            echo "OK\n";
            $ok++;
        } catch (Throwable $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            $failed++;
        }
    }

    echo "\nSummary: {$ok} applied, {$failed} failed\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Fatal: " . $e->getMessage() . "\n";
}
