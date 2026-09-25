<?php
// public/_ai_diag.php
// =============================================================
// Registry-aware diagnostic.
//
// Usage:
//   _ai_diag.php           -> normal run (may show stale blacklist)
//   _ai_diag.php?reset=1   -> clears the blacklist first, then runs
//
// During development, always use ?reset=1 when you want to see
// which providers actually work right now.

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../../bootstrap.php';

$doReset = isset($_GET['reset']) && $_GET['reset'] === '1';

echo "=== Cafe Side - AI Diagnostic (Phase 2) ===\n";
if ($doReset) {
    echo "Mode: RESET - blacklist will be cleared before this run\n";
}
echo "\n";

// -------------------------------------------------------------
// [1] PHP environment
// -------------------------------------------------------------
echo "[1] PHP environment\n";
echo "  PHP version:      " . PHP_VERSION . "\n";
echo "  cURL available:   " . (function_exists('curl_init') ? 'YES' : 'NO (stream wrapper fallback)') . "\n";
echo "  OpenSSL loaded:   " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo "\n";

// -------------------------------------------------------------
// [2] Bootstrap
// -------------------------------------------------------------
echo "[2] Bootstrap\n";
try {
    $pdo = getDbConnection();
    echo "  OK Database connected\n";
} catch (Throwable $e) {
    echo "  FAIL DB error: " . $e->getMessage() . "\n";
    exit;
}

// Optional reset - happens BEFORE the registry is constructed,
// so section [3] reflects the true current state.
if ($doReset) {
    try {
        $stmt = $pdo->query("DELETE FROM ai_blacklist");
        $cleared = $stmt->rowCount();
        echo "  OK Blacklist cleared ({$cleared} rows removed)\n";
    } catch (Throwable $e) {
        echo "  FAIL Reset failed: " . $e->getMessage() . "\n";
    }
}

$registry = null;
try {
    $registry = new ProviderRegistry($pdo);
    echo "  OK ProviderRegistry instantiated\n";
} catch (Throwable $e) {
    echo "  FAIL Registry error: " . $e->getMessage() . "\n";
    exit;
}
echo "\n";

// -------------------------------------------------------------
// [3] Provider status
// -------------------------------------------------------------
echo "[3] Provider status\n";
$status = $registry->getStatusReport();
foreach ($status as $name => $info) {
    $marker = !$info['configured'] ? '[--]' :
              ($info['blacklisted'] ? '[XX]' :
              ($info['in_priority_order'] ? '[OK]' : '[..]'));
    printf("  %s %-14s configured=%s blacklisted=%s in_order=%s\n",
        $marker,
        $name,
        $info['configured']        ? 'YES' : 'no',
        $info['blacklisted']       ? 'YES' : 'no',
        $info['in_priority_order'] ? 'YES' : 'no'
    );
}
echo "\n  Priority order: " . implode(' > ', $registry->getOrder()) . "\n";
echo "\n";

// -------------------------------------------------------------
// [4] Registry chat
// -------------------------------------------------------------
echo "[4] Registry chat test\n";
if (empty($registry->getOrder())) {
    echo "  [--] Skipped - no providers in chain\n";
} else {
    $result = $registry->chat(
        [['role' => 'user', 'content' => 'Reply with the single word: hello']],
        ['max_tokens' => 20, 'temperature' => 0.1]
    );

    if (!empty($result['ok'])) {
        echo "  [OK] Chat succeeded\n";
        echo "    Provider:  {$result['provider']}\n";
        echo "    Model:     {$result['model']}\n";
        echo "    Response:  " . trim($result['text']) . "\n";
        echo "    Tokens:    in {$result['tokens_in']}, out {$result['tokens_out']}\n";
        echo "    Latency:   {$result['latency_ms']}ms\n";
    } else {
        echo "  [XX] Chat failed: {$result['error']}\n";
    }
}
echo "\n";

// -------------------------------------------------------------
// [5] Live health check
// -------------------------------------------------------------
echo "[5] Live health check\n";
$checker = new AIHealthChecker($pdo);
$providers = [];
foreach ($registry->getAllProviders() as $p) {
    if ($p->isConfigured()) $providers[] = $p;
}
if (empty($providers)) {
    echo "  [--] Skipped - no configured providers\n";
} else {
    $results = $checker->checkAll($providers);
    foreach ($results as $r) {
        $marker = $r['status'] === 'healthy' ? '[OK]' : '[XX]';
        printf("  %s %-14s %5dms  %s\n",
            $marker, $r['provider'], $r['latency_ms'],
            $r['error'] !== '' ? substr($r['error'], 0, 100) : ''
        );
    }
}
echo "\n";

// -------------------------------------------------------------
// [6] Model cache
// -------------------------------------------------------------
echo "[6] Model cache\n";
$cache = new AIModelCache($pdo);
$summary = $cache->getProviderSummary();
if (empty($summary)) {
    echo "  (empty - run the model refresh worker to populate)\n";
    echo "  Local: php utils/ai_model_refresh.php --token=YOUR_TOKEN\n";
    echo "  Or via URL: /utils/ai_model_refresh.php?token=YOUR_TOKEN\n";
} else {
    foreach ($summary as $row) {
        printf("  %-14s %4d total  %4d free  last seen %s\n",
            $row['provider'],
            (int) $row['total'],
            (int) $row['free'],
            $row['last_seen']
        );
    }
}
echo "\n";

// -------------------------------------------------------------
// [7] Database tables
// -------------------------------------------------------------
echo "[7] Database tables\n";
$expected = [
    'analytics_cache', 'ai_models', 'ai_health_log', 'ai_blacklist',
    'ai_call_log', 'report_memory', 'ai_chat_history',
];
foreach ($expected as $t) {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
    echo "  " . ($stmt->rowCount() > 0 ? '[OK]' : '[XX]') . " {$t}\n";
}
echo "\n";

echo "=== End of diagnostic ===\n";
echo "Delete this file once Phase 2 is confirmed on the server.\n";