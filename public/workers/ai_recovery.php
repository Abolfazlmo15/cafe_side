<?php
// public/workers/ai_recovery.php
// HTTP: /public/workers/ai_recovery.php?token=AI_CRON_TOKEN
// CLI:  php public/workers/ai_recovery.php

require_once __DIR__ . '/../../bootstrap.php';

class AiRecoveryWorker extends Worker
{
    public function getName(): string { return 'ai_recovery'; }

    public function getDescription(): string
    {
        return 'Ping blacklisted providers and un-ban them if they respond.';
    }

    public function run(): int
    {
        $pdo       = getDbConnection();
        $registry  = new ProviderRegistry($pdo);
        $blacklist = $registry->getBlacklist();

        $recovered = 0;
        $stillDown = 0;

        foreach ($registry->getAllProviders() as $name => $provider) {
            if (!$provider->isConfigured()) continue;
            if (!$blacklist->isBlacklisted($name)) continue;

            $entry  = $blacklist->getCurrent($name);
            $reason = $entry['reason'] ?? '';

            if (strpos($reason, 'auth') === 0) {
                Output::warn(sprintf('%-14s auth failure, skipping', $name));
                $stillDown++;
                continue;
            }

            $t0 = microtime(true);
            $models = $provider->listModels();
            $ms = (int) ((microtime(true) - $t0) * 1000);

            if (!empty($models)) {
                $blacklist->clearProvider($name);
                Output::ok(sprintf('%-14s recovered (%dms)', $name, $ms));
                $recovered++;
            } else {
                Output::fail(sprintf('%-14s still unreachable (%dms)', $name, $ms));
                $stillDown++;
            }
        }

        Output::line();
        Output::info("Recovered: {$recovered}, Still down: {$stillDown}");

        return 0;
    }
}

exit((new AiRecoveryWorker())->execute());