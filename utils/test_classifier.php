<?php
// utils/test_classifier.php
// =============================================================
// Feeds a set of questions through IntentClassifier and prints
// what intent + date preset the LLM chose for each.

require_once __DIR__ . '/../bootstrap.php';

$questions = [
    'How much did I make last week?',
    'What sold best this month?',
    'What items are not selling?',
    'When am I busiest?',
    'What is usually bought together?',
    'Compare this week to last week',
    'How was today compared to yesterday?',
    'Show me last month revenue',
    'Give me an overview',
    'Anything unusual recently?',
    'What is the weather today?',
    'Who won the world cup?',
    'این هفته چقدر فروش داشتم؟',
];

$classifier = new IntentClassifier(getDbConnection());

$pass = 0;
$fail = 0;

foreach ($questions as $q) {
    echo "\n> {$q}\n";

    $t0 = microtime(true);
    $result = $classifier->classify($q);
    $ms = (int) ((microtime(true) - $t0) * 1000);

    if (!$result['ok']) {
        echo "  FAIL: {$result['error']}\n";
        if (!empty($result['raw'])) {
            echo "  RAW:  " . substr($result['raw'], 0, 200) . "\n";
        }
        $fail++;
        continue;
    }

    $d = $result['data'];

    $rawNote = ($d['intent_raw'] !== $d['intent'])
        ? " (raw: {$d['intent_raw']})"
        : '';

    echo "  intent:     {$d['intent']}{$rawNote}\n";
    echo "  preset:     {$d['date_preset']}\n";
    echo "  range:      {$d['date_start']} → {$d['date_end']}\n";

    if (!empty($d['date_start_2'])) {
        echo "  preset 2:   {$d['date_preset_2']}\n";
        echo "  range 2:    {$d['date_start_2']} → {$d['date_end_2']}\n";
    }

    echo "  confidence: {$d['confidence']}\n";
    echo "  provider:   {$result['provider']} ({$ms}ms)\n";

    $pass++;
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";