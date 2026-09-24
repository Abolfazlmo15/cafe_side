<?php
// utils/ai_model_refresh.php
// =============================================================
// Cron worker. Triggered via URL with a secret token:
//   https://cafe-side.gt.tc/utils/ai_model_refresh.php?token=SECRET
//
// For each configured provider, calls listModels() and upserts the
// result into ai_models. Runs every 6 hours via cron-job.org.

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/ai/providers/ProviderRegistry.php';
require_once __DIR__ . '/../src/ai/managers/AIModelCache.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = env('AI_CRON_TOKEN', '');
$givenToken    = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

try {
    $pdo = getDbConnection();
    $registry = new ProviderRegistry($pdo);
    $cache    = new AIModelCache($pdo);

    echo "=== AI Model Refresh ===\n";
    echo "Time: " . date('c') . "\n\n";

    $grandTotal = 0;

    foreach ($registry->getAllProviders() as $name => $provider) {
        if (!$provider->isConfigured()) {
            echo "  ⊘ {$name} — not configured, skipping\n";
            continue;
        }

        $t0 = microtime(true);
        $models = $provider->listModels();
        $ms = (int) ((microtime(true) - $t0) * 1000);

        if (empty($models)) {
            echo "  ✗ {$name} — no models returned ({$ms}ms)\n";
            continue;
        }

        $written = $cache->storeModels($name, $models);
        $grandTotal += $written;

        $free = 0;
        foreach ($models as $m) { if (!empty($m['is_free'])) $free++; }
        echo "  ✓ {$name} — {$written} models cached ({$free} free, {$ms}ms)\n";
    }

    $pruned = $cache->pruneStale();
    if ($pruned > 0) echo "\nPruned {$pruned} stale model rows\n";

    // Update the settings timestamp.
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES ('ai_last_refresh', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([date('c')]);

    echo "\nTotal models cached this run: {$grandTotal}\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}