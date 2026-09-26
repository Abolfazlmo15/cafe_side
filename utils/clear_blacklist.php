<?php
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDbConnection();
$n = (new AIBlacklist($pdo))->clearAll();
echo "Cleared {$n} blacklist entries\n";