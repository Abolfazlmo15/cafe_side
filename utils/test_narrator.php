<?php
// utils/test_narrator.php
// =============================================================
// Full end-to-end pipeline test:
//   question → classifier → handler → narrator → prose
// Prints the final answer for each question.

require_once __DIR__ . '/../bootstrap.php';

$pdo        = getDbConnection();
$classifier = new IntentClassifier($pdo);
$handler    = new IntentHandler($pdo);
$narrator   = new ResponseNarrator($pdo);

$questions = [
    'What sold best this month?',
    'How much did I make last week?',
    'When am I busiest?',
    'Compare this week to last week',
    'Give me an overview',
    'Anything unusual recently?',
    'What items are not selling?',
    'What is usually bought together?',
    'What is the weather today?',
];

$pass = 0;
$fail = 0;

foreach ($questions as $q) {
    echo "\n═══════════════════════════════════════════════════════\n";
    echo "Q: {$q}\n";
    echo "───────────────────────────────────────────────────────\n";

    $t0  = microtime(true);
    $cls = $classifier->classify($q);
    if (!$cls['ok']) {
        echo "✗ classify failed: {$cls['error']}\n";
        $fail++;
        continue;
    }

    $res = $handler->handle($cls['data']);
    if (!$res['ok']) {
        echo "✗ handler failed: {$res['error']}\n";
        $fail++;
        continue;
    }

    $nar = $narrator->narrate($q, $res);
    $ms  = (int) ((microtime(true) - $t0) * 1000);

    if (!$nar['ok']) {
        echo "✗ narrator failed: {$nar['error']}\n";
        $fail++;
        continue;
    }

    echo "  intent:  {$res['intent']}\n";
    echo "  range:   " . ($res['date_start'] ?? '—') . " → " . ($res['date_end'] ?? '—') . "\n";
    if (!empty($nar['provider'])) {
        echo "  via:     {$nar['provider']} ({$nar['model']})\n";
    }
    echo "  total:   {$ms}ms\n\n";
    echo "  ANSWER:\n";
    echo "  " . str_replace("\n", "\n  ", $nar['text']) . "\n";

    $pass++;
}

echo "\n═══════════════════════════════════════════════════════\n";
echo "=== {$pass} passed, {$fail} failed ===\n";