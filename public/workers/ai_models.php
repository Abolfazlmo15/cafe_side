<?php
// public/workers/ai_models.php
// HTTP: /public/workers/ai_models.php?token=AI_CRON_TOKEN
// CLI:  php public/workers/ai_models.php

require_once __DIR__ . '/../../bootstrap.php';

class AiModelsWorker extends Worker
{
    public function getName(): string { return 'ai_models'; }

    public function getDescription(): string
    {
        return 'Refresh the model catalog for every AI provider.';
    }

    public function run(): int
    {
        $pdo      = getDbConnection();
        $registry = new ProviderRegistry($pdo);
        $cache    = new AIModelCache($pdo);

        $grandTotal = 0;

        foreach ($registry->getAllProviders() as $name => $provider) {
            if (!$provider->isConfigured()) {
                Output::warn(sprintf('%-14s not configured', $name));
                continue;
            }

            $t0 = microtime(true);
            $models = $provider->listModels();
            $ms = (int) ((microtime(true) - $t0) * 1000);

            if (empty($models)) {
                Output::fail(sprintf('%-14s no models returned (%dms)', $name, $ms));
                continue;
            }

            $written = $cache->storeModels($name, $models);
            $grandTotal += $written;

            $free = 0;
            foreach ($models as $m) { if (!empty($m['is_free'])) $free++; }

            Output::ok(sprintf('%-14s %3d cached (%3d free) %dms', $name, $written, $free, $ms));
        }

        $pruned = $cache->pruneStale();

        Output::line();
        Output::info("Total cached this run: {$grandTotal}");
        if ($pruned > 0) Output::info("Pruned {$pruned} stale rows");

        // Record the refresh time.
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) VALUES ('ai_last_refresh', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([date('c')]);

        return 0;
    }
}

exit((new AiModelsWorker())->execute());