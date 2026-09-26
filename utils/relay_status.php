<?php
// utils/relay_status.php
// =============================================================
// Reports the state of the Cloudflare Worker relay: whether
// the env vars are set, whether PHP sees them, whether the
// worker is reachable, and what the AI chain looks like.
//
// Run:
//   php utils/relay_status.php
//
// No arguments. Safe to run any number of times.

require_once __DIR__ . '/../bootstrap.php';

echo "=== Cafe Side · Relay Status ===\n";
echo "Time: " . date('c') . "\n\n";

// ── 1. Env vars ─────────────────────────────────────────────
echo "[1] Environment variables\n";

$url    = env('AI_RELAY_URL', '');
$secret = env('AI_RELAY_SECRET', '');

echo "  AI_RELAY_URL:    " . ($url    !== '' ? $url    : '(NOT SET)') . "\n";
echo "  AI_RELAY_SECRET: " . ($secret !== '' ? '(set, ' . strlen($secret) . ' chars)' : '(NOT SET)') . "\n";

// Detect the common typo AI_SECRET vs AI_RELAY_SECRET
$typo = env('AI_SECRET', '');
if ($typo !== '' && $secret === '') {
    echo "\n  ⚠  Detected a value in AI_SECRET but not in AI_RELAY_SECRET.\n";
    echo "     Rename that line in .env — the code reads AI_RELAY_SECRET.\n";
}

echo "\n";

// ── 2. Constants ────────────────────────────────────────────
echo "[2] Constants (defined by src/config.php)\n";

echo "  AI_RELAY_URL:    " . (defined('AI_RELAY_URL')    ? (AI_RELAY_URL    !== '' ? AI_RELAY_URL    : '(empty)') : '(not defined)') . "\n";
echo "  AI_RELAY_SECRET: " . (defined('AI_RELAY_SECRET') ? (AI_RELAY_SECRET !== '' ? '(set)' : '(empty)')             : '(not defined)') . "\n";

echo "\n";

// ── 3. relayAvailable() ─────────────────────────────────────
echo "[3] HttpClient::relayAvailable()\n";

if (!class_exists('HttpClient')) {
    echo "  ✗ HttpClient class not loaded — check config/classmap.php\n";
    exit(1);
}

$available = HttpClient::relayAvailable();
echo "  " . ($available ? "✓ true" : "✗ false") . "\n";

if (!$available) {
    echo "\n  The relay won't be used. Likely cause:\n";
    echo "    - AI_RELAY_URL or AI_RELAY_SECRET is empty in .env, or\n";
    echo "    - the constants in config.php weren't added, or\n";
    echo "    - the .env variable is named wrong (see section 1 above)\n";
    exit(1);
}

echo "\n";

// ── 4. Worker reachability ──────────────────────────────────
echo "[4] Worker reachability (GET /_ping)\n";

$pingUrl = rtrim($url, '/') . '/_ping';
echo "  URL: {$pingUrl}\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $pingUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);

if ($body === false || $code !== 200) {
    echo "  ✗ Failed: HTTP {$code}" . ($err ? " — {$err}" : '') . "\n";
    echo "\n  The worker isn't reachable from this machine.\n";
    echo "  Possible causes:\n";
    echo "    - workers.dev is blocked on your ISP (add a custom domain)\n";
    echo "    - you're offline / the URL is wrong\n";
    exit(1);
}

$decoded = json_decode($body, true);
if (!is_array($decoded) || empty($decoded['ok'])) {
    echo "  ✗ Unexpected response: " . substr($body, 0, 200) . "\n";
    exit(1);
}

echo "  ✓ Reachable. Providers configured on the worker:\n";
foreach ($decoded['providers'] as $p) {
    echo "      · {$p}\n";
}
$workerProviders = $decoded['providers'];

echo "\n";

// ── 5. AI chain ─────────────────────────────────────────────
echo "[5] AI chain\n";

$pdo = getDbConnection();
$registry = new ProviderRegistry($pdo);

$order = $registry->getOrder();
echo "  Active chain: " . (empty($order) ? '(EMPTY)' : implode(', ', $order)) . "\n";
echo "  Provider count: " . count($order) . "\n";

$status = $registry->getStatusReport();
echo "\n  Per-provider status:\n";
foreach ($status as $name => $info) {
    $mark = !$info['configured'] ? '[--]'
          : (!$info['in_priority_order'] ? '[XX]' : '[OK]');
    $extra = '';
    if ($info['blacklisted']) {
        $extra = '  blacklisted: ' . ($info['blacklist_reason'] ?? '?');
    }
    printf("    %s %-14s %s\n", $mark, $name, $extra);
}

echo "\n";

// ── 6. Cross-check: providers on worker vs chain ────────────
echo "[6] Cross-check\n";

$chainSet = array_flip($order);
$missing  = [];
foreach ($workerProviders as $p) {
    if (!isset($chainSet[$p])) $missing[] = $p;
}

if (empty($missing)) {
    echo "  ✓ Every worker-configured provider is also in the AI chain.\n";
} else {
    echo "  ⚠  These providers are configured on the worker but not in the\n";
    echo "     local AI chain (likely blacklisted or not set in .env):\n";
    foreach ($missing as $p) {
        echo "      · {$p}\n";
    }
}

echo "\n";

// ── 7. Live chat test ───────────────────────────────────────
echo "[7] Live chat test\n";

$result = $registry->chat([
    ['role' => 'user', 'content' => 'Reply with the single word: ok'],
], ['max_tokens' => 20, 'temperature' => 0.1]);

if (!empty($result['ok'])) {
    echo "  ✓ Chat works\n";
    echo "    Provider:  {$result['provider']}\n";
    echo "    Model:     {$result['model']}\n";
    echo "    Response:  " . trim((string) $result['text']) . "\n";
    if (!empty($result['via_relay'])) {
        echo "    ✓ Delivered via Cloudflare relay\n";
    }
} else {
    echo "  ✗ Chat failed: " . ($result['error'] ?? '?') . "\n";
}

echo "\n";
echo "=== Done ===\n";