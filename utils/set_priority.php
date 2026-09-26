<?php
// utils/set_priority.php
// Sets the AI provider priority list. All six providers are kept
// in the chain. The order reflects current viability:
//
//   1. openrouter  — works directly, free tier, rarely rate-limited
//   2. mistral     — works directly, free tier
//   3. groq        — needs Cloudflare relay from this network (403 direct)
//   4. huggingface — needs Cloudflare relay (DNS blocked locally)
//   5. deepseek    — needs account funding ($2 lasts a year)
//   6. together    — needs paid plan for chat models
//
// Edit the array below and re-run this script to change the order.

require_once __DIR__ . '/../bootstrap.php';

$priority = [
    'openrouter',
    'mistral',
    'groq',
    'huggingface',
    'deepseek',
    'together',
];

$value = implode(',', $priority);

$pdo = getDbConnection();
$stmt = $pdo->prepare("
    INSERT INTO settings (setting_key, setting_value) VALUES ('ai_provider_priority', ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");
$stmt->execute([$value]);

echo "Priority set to: {$value}\n";