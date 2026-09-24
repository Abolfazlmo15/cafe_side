<?php
// public/cron.php
// =============================================================
// External trigger for the internal cron dispatcher.
// Requires ?token= to match CRON_TOKEN in .env.
//
// Example:
//   https://cafe-side.gt.tc/public/cron.php?token=YOUR_TOKEN
//
// Returns plain text summary. Delete this file if you ever switch
// to CLI-only scheduling.

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../utils/cron.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = env('CRON_TOKEN', '');
$givenToken    = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

try {
    $pdo    = getDbConnection();
    $tasks  = require __DIR__ . '/../config/cron.php';
    $runner = new CronDispatcher($pdo, $tasks, $expectedToken);

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
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}