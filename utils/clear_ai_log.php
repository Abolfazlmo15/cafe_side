<?php
// utils/clear_ai_log.php
// Wipes the last N hours of AI call log rows so the rate limiter
// starts fresh. Safe — the log is only used for rate limiting.

require_once __DIR__ . '/../bootstrap.php';

$hours = isset($argv[1]) ? (int) $argv[1] : 24;
if ($hours < 1) $hours = 1;

$pdo = getDbConnection();
$stmt = $pdo->prepare("DELETE FROM ai_call_log WHERE called_at > DATE_SUB(NOW(), INTERVAL ? HOUR)");
$stmt->execute([$hours]);
$n = $stmt->rowCount();

echo "Cleared {$n} ai_call_log rows from the last {$hours}h\n";

$remaining = (int) $pdo->query("SELECT COUNT(*) FROM ai_call_log WHERE called_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND success = 1")->fetchColumn();
echo "Calls in the last minute (counts against the new per-minute cap): {$remaining}\n";
echo "Per-minute cap: 60\n";