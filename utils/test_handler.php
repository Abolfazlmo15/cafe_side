<?php
// utils/test_handler.php
// =============================================================
// Runs the full Step 1 + Step 2 pipeline:
//   question → classifier → intent + dates → handler → data
// Prints a compact summary of the data shape for each question.

require_once __DIR__ . '/../bootstrap.php';

$classifier = new IntentClassifier(getDbConnection());
$handler    = new IntentHandler(getDbConnection());

$questions = [
    'How much did I make last week?',
    'What sold best this month?',
    'What items are not selling?',
    'When am I busiest?',
    'What is usually bought together?',
    'Compare this week to last week',
    'Give me an overview',
    'Anything unusual recently?',
    'What is the weather today?',
];

$pass = 0;
$fail = 0;

foreach ($questions as $q) {
    echo "\n> {$q}\n";

    // ── Step 1: classify ──
    $t0  = microtime(true);
    $cls = $classifier->classify($q);
    $clsMs = (int) ((microtime(true) - $t0) * 1000);

    if (!$cls['ok']) {
        echo "  FAIL (classify): {$cls['error']}\n";
        $fail++;
        continue;
    }

    $intent = $cls['data']['intent'];
    echo "  intent:  {$intent}  ({$clsMs}ms)\n";

    // ── Step 2: handle ──
    $t1  = microtime(true);
    $res = $handler->handle($cls['data']);
    $hMs = (int) ((microtime(true) - $t1) * 1000);

    if (!$res['ok']) {
        echo "  FAIL (handle): {$res['error']}\n";
        $fail++;
        continue;
    }

    echo "  handler: OK  ({$hMs}ms)\n";

    // ── Print a compact shape summary ──
    if ($intent === 'unknown') {
        echo "  message: " . substr($res['message'] ?? '', 0, 80) . "...\n";
        $pass++;
        continue;
    }

    if ($intent === 'comparison') {
        $d = $res['data']['diff'];
        echo "  period A: {$res['date_start']} → {$res['date_end']}\n";
        echo "  period B: {$res['date_start_2']} → {$res['date_end_2']}\n";
        echo "  revenue:  " . number_format($d['revenue_a']) . " vs " . number_format($d['revenue_b']) . " T";
        if ($d['revenue_change_pct'] !== null) {
            echo "  (" . ($d['revenue_change_pct'] >= 0 ? '+' : '') . $d['revenue_change_pct'] . "%)";
        }
        echo "\n";
        $pass++;
        continue;
    }

    if ($intent === 'summary') {
        $rev = $res['data']['revenue']['summary'] ?? [];
        echo "  range:    {$res['date_start']} → {$res['date_end']}\n";
        echo "  revenue:  " . number_format((int) ($rev['total_revenue'] ?? 0)) . " T\n";
        echo "  orders:   " . (int) ($rev['total_orders'] ?? 0) . "\n";
        $pass++;
        continue;
    }

    // Single-report intent — print the top-level keys and one stat
    echo "  range:    {$res['date_start']} → {$res['date_end']}\n";
    echo "  keys:     " . implode(', ', array_keys($res['data'] ?? [])) . "\n";

    // Try to show one meaningful number per intent
    $d = $res['data'];
    if ($intent === 'top_items' || $intent === 'least_items') {
        $n = count($d['items'] ?? []);
        echo "  items:    {$n}\n";
    } elseif ($intent === 'hourly_heatmap') {
        echo "  peak:     " . (int) ($d['max'] ?? 0) . " orders\n";
    } elseif ($intent === 'item_combos') {
        $n = count($d['combos'] ?? []);
        echo "  pairs:    {$n}\n";
    } elseif ($intent === 'anomalies') {
        $n = count($d['anomalies'] ?? []);
        echo "  flagged:  {$n}\n";
    } elseif ($intent === 'revenue_trend') {
        $rev = $d['summary'] ?? [];
        echo "  revenue:  " . number_format((int) ($rev['total_revenue'] ?? 0)) . " T\n";
    } elseif ($intent === 'fading_items' || $intent === 'rising_items') {
        $n = count($d['items'] ?? []);
        echo "  items:    {$n}\n";
        if (!empty($d['reason'])) echo "  reason:   {$d['reason']}\n";
    } elseif ($intent === 'price_tier_shift') {
        $n = count($d['tiers'] ?? []);
        echo "  tiers:    {$n}\n";
        if (!empty($d['reason'])) echo "  reason:   {$d['reason']}\n";
    }

    $pass++;
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";