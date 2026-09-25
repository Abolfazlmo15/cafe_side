<?php
// public/workers/analytics.php
// HTTP: /public/workers/analytics.php?task=weekly&token=AI_CRON_TOKEN
// CLI:  php public/workers/analytics.php weekly --force

require_once __DIR__ . '/../../bootstrap.php';

class AnalyticsWorker extends Worker
{
    public function getName(): string { return 'analytics'; }

    public function getDescription(): string
    {
        return 'Generate scheduled AI reports (weekly briefing, etc).';
    }

    public function run(): int
    {
        // Resolve task + force from CLI args or HTTP query.
        if ($this->isCli) {
            $task  = $this->getArg(1, 'weekly');
            $force = $this->hasFlag('--force');
        } else {
            $task  = $_GET['task']  ?? 'weekly';
            $force = !empty($_GET['force']);
        }

        Output::label('Task',  $task);
        Output::label('Force', $force ? 'yes' : 'no');
        Output::line();

        switch ($task) {
            case 'weekly': return $this->runWeekly($force);
            default:
                Output::fail("Unknown task: {$task}");
                Output::info('Available: weekly');
                return 1;
        }
    }

    private function runWeekly(bool $force): int
    {
        $pdo    = getDbConnection();
        $engine = new AnalyticsEngine($pdo);
        $bridge = new AIBridge($pdo);
        $memory = new ReportMemory($pdo);

        $end       = date('Y-m-d', strtotime('-1 day'));
        $start     = date('Y-m-d', strtotime('-7 days'));
        $prevEnd   = date('Y-m-d', strtotime('-8 days'));
        $prevStart = date('Y-m-d', strtotime('-14 days'));

        if (!$force && $memory->hasRecent('weekly_summary', 20)) {
            Output::warn('Skipped: a briefing was generated in the last 20 hours.');
            Output::info('Use --force to regenerate.');
            return 0;
        }

        $trend     = $engine->runReport('revenue_trend', $start, $end);
        $top       = $engine->runReport('top_items', $start, $end);
        $prevTrend = $engine->runReport('revenue_trend', $prevStart, $prevEnd);

        $weekData = [
            'total_revenue'    => $trend['summary']['total_revenue'] ?? 0,
            'total_orders'     => $trend['summary']['total_orders'] ?? 0,
            'avg_order_value'  => $trend['summary']['avg_order_value'] ?? 0,
            'days_with_orders' => $trend['summary']['days_with_orders'] ?? 0,
            'top_items'        => $top['items'] ?? [],
            'daily_breakdown'  => $trend['days'] ?? [],
            'prev_week'        => $prevTrend['summary'] ?? null,
        ];

        $prevSummaries = $memory->getRecent('weekly_summary', 5);

        Output::label('Range', Jalali::formatHuman($start) . ' → ' . Jalali::formatHuman($end));
        Output::line();

        $result = $bridge->generateWeeklySummary($weekData, $prevSummaries, $start, $end, $force);

        if (!empty($result['ok'])) {
            Output::ok('Briefing generated');
            Output::line();
            Output::line('  ' . wordwrap($result['text'], 76, PHP_EOL . '  '));
            return 0;
        }

        Output::fail('Briefing failed: ' . ($result['error'] ?? 'unknown'));
        return 1;
    }
}

exit((new AnalyticsWorker())->execute());