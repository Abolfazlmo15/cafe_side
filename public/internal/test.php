<?php
// public/internal/test.php
// =============================================================
// Automated test suite. Verifies that every subsystem is alive.
//
// CLI:  php public/internal/test.php
// HTTP: /public/internal/test.php?token=AI_CRON_TOKEN

require_once __DIR__ . '/../../bootstrap.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $exp = env('AI_CRON_TOKEN', '');
    $got = $_GET['token'] ?? '';
    if ($exp === '' || !hash_equals($exp, $got)) {
        http_response_code(403);
        echo "Forbidden\n"; exit;
    }
}

$pass = 0;
$fail = 0;
$tests = [];

function assertThat(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo Output::okText($label . ($detail !== '' ? '  ' . Output::dimText($detail) : '')) . PHP_EOL;
        $pass++;
    } else {
        echo Output::failText($label . ($detail !== '' ? '  ' . $detail : '')) . PHP_EOL;
        $fail++;
    }
}

// ── 1. Environment ─────────────────────────────────────────
Output::title('Environment');
assertThat('PHP >= 8.0', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);
assertThat('cURL extension', function_exists('curl_init'));
assertThat('OpenSSL extension', extension_loaded('openssl'));
assertThat('PDO MySQL driver', extension_loaded('pdo_mysql'));
assertThat('GD extension (image thumbnails)', extension_loaded('gd'));
assertThat('.env file readable', is_readable(__DIR__ . '/../../.env'));
assertThat('AI_CRON_TOKEN set', strlen(env('AI_CRON_TOKEN', '')) >= 10);

// ── 2. Database ───────────────────────────────────────────
Output::title('Database');
try {
    $pdo = getDbConnection();
    assertThat('Connection established', true, 'DB: ' . DB_NAME);
} catch (Throwable $e) {
    assertThat('Connection established', false, $e->getMessage());
    $pdo = null;
}

if ($pdo) {
    $tables = [
        'orders', 'menu_items', 'tables', 'settings', 'migrations',
        'analytics_cache', 'ai_models', 'ai_health_log',
        'ai_blacklist', 'ai_call_log', 'report_memory',
        'ai_chat_history', 'menu_items_deleted',
    ];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
        assertThat("table: {$t}", $stmt->rowCount() > 0);
    }

    // Version cursor should exist and be >= 1.
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
    $stmt->execute();
    $version = $stmt->fetchColumn();
    assertThat('version cursor present', $version !== false, 'value: ' . $version);
    assertThat('version cursor numeric', is_numeric($version) && (int)$version >= 1);
}

// ── 3. AI Providers ───────────────────────────────────────
Output::title('AI Providers');
if ($pdo) {
    require_once __DIR__ . '/../../src/ai/providers/ProviderRegistry.php';
    $registry = new ProviderRegistry($pdo);
    $configured = 0;
    foreach ($registry->getAllProviders() as $name => $p) {
        if ($p->isConfigured()) $configured++;
    }
    assertThat('at least 1 provider configured', $configured >= 1, $configured . ' configured');
    assertThat('at least 1 provider in chain', count($registry->getOrder()) >= 1, implode(',', $registry->getOrder()));

    // Model cache populated?
    $stmt = $pdo->query("SELECT COUNT(*) FROM ai_models");
    $modelCount = (int) $stmt->fetchColumn();
    assertThat('model catalog populated', $modelCount > 0, $modelCount . ' models cached');

    // Check for a live call — skip if all are rate-limited.
    $result = $registry->chat([[
        'role' => 'user',
        'content' => 'Reply with the single word: ok',
    ]], ['max_tokens' => 10, 'temperature' => 0.1]);

    if (!empty($result['ok'])) {
        assertThat('live chat works', true, 'via ' . $result['provider'] . ' (' . $result['model'] . ')');
    } else {
        assertThat('live chat works', false, substr($result['error'], 0, 120));
    }
}

// ── 4. Analytics ──────────────────────────────────────────
Output::title('Analytics');
if ($pdo) {
    require_once __DIR__ . '/../../src/analytics/AnalyticsEngine.php';
    $engine = new AnalyticsEngine($pdo);
    $reports = $engine->getReports();
    assertThat('8 reports registered', count($reports) === 8, count($reports) . ' found');

    // Try running one report — should return an array, not throw.
    $start = date('Y-m-d', strtotime('-30 days'));
    $end   = date('Y-m-d');
    $trend = $engine->runReport('revenue_trend', $start, $end);
    assertThat('revenue_trend returns data', is_array($trend), count($trend ?? []) . ' keys');

    // Cache should be populated from the run.
    $stmt = $pdo->query("SELECT COUNT(*) FROM analytics_cache");
    $cacheRows = (int) $stmt->fetchColumn();
    assertThat('analytics cache populated', $cacheRows > 0, $cacheRows . ' rows');
}

// ── 5. Files & Directories ──────────────────────────────
Output::title('Files');
$required = [
    '/../../src/cli/Worker.php',
    '/../../src/cli/Output.php',
    '/../../src/cli/Registry.php',
    '/../../config/workers.php',
    '/../../public/workers/run.php',
    '/../../public/workers/ai_health.php',
    '/../../public/workers/ai_models.php',
    '/../../public/workers/ai_recovery.php',
    '/../../public/workers/analytics.php',
];
foreach ($required as $rel) {
    $full = __DIR__ . $rel;
    assertThat('exists: ' . str_replace('/../../', '', $rel), is_readable($full));
}

// ── 6. Worker registry integrity ──────────────────────────
Output::title('Registry');
require_once __DIR__ . '/../../src/cli/Registry.php';
try {
    $reg = new Registry(
        __DIR__ . '/../../config/workers.php',
        __DIR__ . '/../..'
    );
    assertThat('registry loads', true, count($reg->all()) . ' workers');
    foreach ($reg->all() as $name => $path) {
        assertThat("worker '{$name}' readable", is_readable($path));
    }
} catch (Throwable $e) {
    assertThat('registry loads', false, $e->getMessage());
}

// ── Summary ──────────────────────────────────────────────
Output::line();
Output::title('Result');
Output::line();

$total = $pass + $fail;
if ($fail === 0) {
    Output::ok("All {$total} tests passed.");
    Output::line();
    exit(0);
}

Output::fail("{$fail} of {$total} tests failed.");
Output::line();
exit(1);