<?php
// utils/purge_cache.php
// Wipes every cached model and rebuilds the catalog from the
// live APIs. Run after changing NON_CHAT_PATTERNS.

require_once __DIR__ . '/../bootstrap.php';

$pdo   = getDbConnection();
$cache = new AIModelCache($pdo);

echo "=== Cache purge & rebuild ===\n\n";

// 1. Purge
$purged = $pdo->exec("DELETE FROM ai_models");
echo "  Purged {$purged} cached model rows\n\n";

// 2. Rebuild from each provider
$registry = new ProviderRegistry($pdo);
$total = 0;

foreach ($registry->getAllProviders() as $name => $provider) {
    if (!$provider->isConfigured()) {
        echo "  [--] {$name}: not configured\n";
        continue;
    }

    $t0 = microtime(true);
    $models = $provider->listModels();
    $ms = (int) ((microtime(true) - $t0) * 1000);

    if (empty($models)) {
        echo "  [XX] {$name}: no models returned ({$ms}ms)\n";
        continue;
    }

    $written = $cache->storeModels($name, $models);

    // Count how many are chat-capable after the blocklist
    $chat = 0;
    foreach ($models as $m) {
        if (AIModelCache::isChatModel($m['id'] ?? '')) $chat++;
    }

    echo "  [OK] {$name}: {$written} cached, {$chat} chat-capable ({$ms}ms)\n";
    $total += $written;
}

echo "\n  Total written: {$total}\n\n";

// 3. Show the new default pick for each provider
echo "=== New default picks ===\n\n";
foreach ($registry->getAllProviders() as $name => $provider) {
    if (!$provider->isConfigured()) continue;
    $pick = $cache->pickDefaultModel($name);
    echo "  {$name}: " . ($pick ?? '(none)') . "\n";
}

echo "\n=== Done ===\n";