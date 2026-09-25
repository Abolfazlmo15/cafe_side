<?php
// public/cron.php
// =============================================================
// External trigger for the internal cron dispatcher.
//
// HTTP:  /public/cron.php?token=AI_CRON_TOKEN
// CLI:   php public/cron.php

require_once __DIR__ . '/../bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// ── HTTP mode: token check ────────────────────────────────────
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $expectedToken = env('AI_CRON_TOKEN', '');
    $givenToken    = $_GET['token'] ?? '';

    if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}
// ── CLI mode: no token needed ─────────────────────────────────

try {
    $pdo    = getDbConnection();
    $tasks  = require CONFIG_PATH . '/cron.php';
    $runner = new CronDispatcher($pdo, $tasks, env('AI_CRON_TOKEN', ''));

    $results = $runner->run();

    echo "=== Cafe Side · Cron Dispatcher ===\n";
    echo "Time: " . date('c') . "\n\n";

    $ran = 0; $skipped = 0; $failed = 0; $disabled = 0;
    foreach ($results as $r) {
        switch ($r['status']) {
            case 'ran':       $ran++;      break;
            case 'not_due':   $skipped++;  break;
            case 'failed':    $failed++;   break;
            case 'disabled':  $disabled++; break;
        }
        printf("  %-20s %-10s %s\n",
            $r['name'],
            $r['status'],
            $r['status'] === 'ran'
                ? sprintf('HTTP %d (%dms)', $r['http_code'], $r['duration_ms'])
                : ($r['error'] ?: '')
        );
    }

    echo "\nSummary: {$ran} ran, {$skipped} not due, {$failed} failed, {$disabled} disabled\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    echo "Error: " . $e->getMessage() . "\n";
}