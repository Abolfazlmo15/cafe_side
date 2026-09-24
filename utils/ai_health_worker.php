<?php
// utils/ai_health_worker.php
// =============================================================
// Cron worker. Triggered by an external scheduler via a URL with
// a secret token:
//   https://cafe-side.gt.tc/utils/ai_health_worker.php?token=SECRET
//
// Runs a health sweep across every configured provider and prints
// a plain-text summary. Exits with HTTP 200 on success, 403 on
// bad token, 500 on internal error.

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/ai/providers/ProviderRegistry.php';
require_once __DIR__ . '/../src/ai/managers/AIHealthChecker.php';

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
    $checker  = new AIHealthChecker($pdo);

    $providers = [];
    foreach ($registry->getAllProviders() as $p) {
        if ($p->isConfigured()) $providers[] = $p;
    }

    $results = $checker->checkAll($providers);

    echo "=== AI Health Sweep ===\n";
    echo "Time: " . date('c') . "\n\n";

    $healthy = 0; $unhealthy = 0; $unconfigured = 0;
    foreach ($results as $r) {
        $marker = $r['status'] === 'healthy' ? '✓' : ($r['status'] === 'unconfigured' ? '⊘' : '✗');
        printf("  %s %-14s %-12s %5dms  %s\n",
            $marker, $r['provider'], $r['status'], $r['latency_ms'],
            $r['error'] !== '' ? substr($r['error'], 0, 100) : ''
        );
        if ($r['status'] === 'healthy') $healthy++;
        elseif ($r['status'] === 'unconfigured') $unconfigured++;
        else $unhealthy++;
    }

    echo "\nSummary: {$healthy} healthy, {$unhealthy} unhealthy, {$unconfigured} unconfigured\n";

    $pruned = $checker->pruneOldLogs();
    if ($pruned > 0) echo "Pruned {$pruned} old health-log rows\n";

    echo "\nDone.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}