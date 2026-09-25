<?php
// public/workers/ai_health.php
// HTTP: /public/workers/ai_health.php?token=AI_CRON_TOKEN
// CLI:  php public/workers/ai_health.php

require_once __DIR__ . '/../../bootstrap.php';

class AiHealthWorker extends Worker
{
    public function getName(): string
    {
        return 'ai_health';
    }

    public function getDescription(): string
    {
        return 'Ping every configured AI provider and log reachability.';
    }

    public function run(): int
    {
        $pdo      = getDbConnection();
        $registry = new ProviderRegistry($pdo);
        $checker  = new AIHealthChecker($pdo);

        $providers = [];
        foreach ($registry->getAllProviders() as $p) {
            if ($p->isConfigured()) $providers[] = $p;
        }

        $results = $checker->checkAll($providers);
        $healthy = 0; $unhealthy = 0;

        foreach ($results as $r) {
            if ($r['status'] === 'healthy') {
                Output::ok(sprintf('%-14s %5dms', $r['provider'], $r['latency_ms']));
                $healthy++;
            } elseif ($r['status'] === 'unconfigured') {
                Output::warn(sprintf('%-14s not configured', $r['provider']));
            } else {
                Output::fail(sprintf('%-14s %s', $r['provider'], substr($r['error'], 0, 80)));
                $unhealthy++;
            }
        }

        Output::line();
        Output::info("Summary: {$healthy} healthy, {$unhealthy} unhealthy");

        $pruned = $checker->pruneOldLogs();
        if ($pruned > 0) Output::info("Pruned {$pruned} old health-log rows");

        return 0;
    }
}

// ---- Boot ----
exit((new AiHealthWorker())->execute());