<?php
// utils/fix_mistral.php
// =============================================================
// One-shot cache repair for the Mistral provider.
//
// Problem: pickDefaultModel() was returning codestral-2508 (a code
// completion model) because it sorted alphabetically before every
// chat model. The blocklist added to AIModelCache now excludes it.
//
// What this script does:
//   1. Purges every cached mistral model
//   2. Rebuilds the catalog from the live Mistral API
//   3. Confirms the default pick is now a chat model
//   4. Runs the full test suite as a subprocess
//
// Usage:
//   php utils/fix_mistral.php
//
// Idempotent — safe to run multiple times.

require_once __DIR__ . '/../bootstrap.php';

// ── Optional HTTP mode with token gating ────────────────────
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $exp = env('AI_CRON_TOKEN', '');
    $got = $_GET['token'] ?? '';
    if ($exp === '' || !hash_equals($exp, $got)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

echo "=== Cafe Side · Mistral Cache Repair ===\n";
echo "Time: " . date('c') . "\n\n";

$pdo   = getDbConnection();
$cache = new AIModelCache($pdo);

// ── 1. Purge mistral ─────────────────────────────────────────
$purged = $cache->purgeProvider('mistral');
echo "  [1/4] Purged {$purged} cached mistral rows\n";

// ── 2. Rebuild the catalog ───────────────────────────────────
$provider = new MistralProvider(['api_key' => env('MISTRAL_API_KEY', '')]);

if (!$provider->isConfigured()) {
    echo "  ✗ MISTRAL_API_KEY not set in .env — aborting\n";
    exit(1);
}

echo "  [2/4] Rebuilding catalog from live API...\n";
$t0 = microtime(true);
$models = $provider->listModels();
$ms = (int) ((microtime(true) - $t0) * 1000);

if (empty($models)) {
    echo "  ✗ No models returned (auth? network? rate limit?)\n";
    exit(1);
}

$written = $cache->storeModels('mistral', $models);

$free = 0;
$chat = 0;
foreach ($models as $m) {
    if (!empty($m['is_free']))                       $free++;
    if (AIModelCache::isChatModel($m['id'] ?? ''))  $chat++;
}
echo "        Wrote {$written} models ({$free} free, {$chat} chat-capable) in {$ms}ms\n";

// ── 3. Verify the default pick ───────────────────────────────
$default = $cache->pickDefaultModel('mistral');
echo "  [3/4] Default pick: " . ($default ?? '(none)') . "\n";

if ($default === null) {
    echo "  ✗ No chat model available — check the blocklist\n";
    exit(1);
}
if (stripos($default, 'codestral') !== false) {
    echo "  ✗ STILL PICKING CODESTRAL — the blocklist didn't apply\n";
    exit(1);
}
echo "        ✓ Default pick is a chat model\n";

// Show what's now available
$freeModels = $cache->getFreeModels('mistral');
if (!empty($freeModels)) {
    echo "\n        Free chat models now available:\n";
    foreach (array_slice($freeModels, 0, 8) as $m) {
        echo "          · {$m['model_id']}\n";
    }
    if (count($freeModels) > 8) {
        echo "          · ... and " . (count($freeModels) - 8) . " more\n";
    }
}
echo "\n";

// ── 4. Run the test suite ────────────────────────────────────
echo "  [4/4] Running test suite...\n";
echo "        ─────────────────────────────────────────\n\n";

$testFile = realpath(__DIR__ . '/../public/internal/test.php');
if (!$testFile || !is_readable($testFile)) {
    echo "        (test.php not found — skipping)\n\n";
    echo "Done.\n";
    exit(0);
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile);
passthru($cmd, $exitCode);
echo "\n";

if ($exitCode !== 0) {
    echo "  ✗ Test suite reported failures (exit code {$exitCode})\n";
    exit($exitCode);
}

echo "  ✓ All tests passed\n\n";
echo "Done. Mistral cache is healthy.\n";
exit(0);