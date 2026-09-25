<?php
// public/_ai_status.php
// =============================================================
// Human-readable status of the AI provider chain.
//
// Usage: /public/_ai_status.php?token=YOUR_AI_CRON_TOKEN
//
// Add &reset=1 to clear the blacklist at the same time.

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../../bootstrap.php';


$expected = env('AI_CRON_TOKEN', '');
$given    = $_GET['token'] ?? '';
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "Forbidden\n"; exit;
}

$pdo = getDbConnection();
$registry = new ProviderRegistry($pdo);

if (!empty($_GET['reset'])) {
    $n = $registry->getBlacklist()->clearAll();
    echo "Blacklist cleared ({$n} rows).\n\n";
    // Rebuild the registry so the chain reflects the cleared state.
    $registry = new ProviderRegistry($pdo);
}

echo "=== Cafe Side · AI Provider Status ===\n";
echo "Time: " . date('c') . "\n\n";

$status = $registry->getStatusReport();
foreach ($status as $name => $info) {
    $mark = !$info['configured'] ? '[--]'
          : ($info['blacklisted'] ? '[XX]' : '[OK]');
    printf("%s %-14s configured=%-3s blacklisted=%-3s in_order=%-3s\n",
        $mark, $name,
        $info['configured'] ? 'YES' : 'no',
        $info['blacklisted'] ? 'YES' : 'no',
        $info['in_priority_order'] ? 'YES' : 'no'
    );
    if ($info['blacklist_reason']) {
        echo "     reason:  " . $info['blacklist_reason'] . "\n";
        echo "     expires: " . $info['blacklist_expires'] . "\n";
    }
}

echo "\nActive chain: " . (empty($registry->getOrder()) ? '(EMPTY — panic mode active)' : implode(' > ', $registry->getOrder())) . "\n";
echo "\nAdd &reset=1 to clear the blacklist.\n";