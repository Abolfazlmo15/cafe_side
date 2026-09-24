<?php
// src/analytics/AnalyticsEngine.php
// =============================================================
// Orchestrator. Registers every report, runs them, caches results.
//
// Usage from analytics.php:
//   $engine = new AnalyticsEngine($pdo);
//   $all = $engine->runAll('2026-08-01', '2026-09-01');
//   // => ['revenue_trend' => ['title' => ..., 'data' => [...]], ...]

require_once __DIR__ . '/AnalyticsCache.php';
require_once __DIR__ . '/reports/RevenueTrendReport.php';
require_once __DIR__ . '/reports/TopItemsReport.php';
require_once __DIR__ . '/reports/LeastItemsReport.php';
require_once __DIR__ . '/reports/HourlyHeatmapReport.php';

class AnalyticsEngine
{
    private PDO $pdo;
    private AnalyticsCache $cache;
    private array $reports = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->cache = new AnalyticsCache($pdo);

        $this->reports = [
            new RevenueTrendReport(),
            new TopItemsReport(),
            new LeastItemsReport(),
            new HourlyHeatmapReport(),
        ];
    }

    /**
     * Every registered report — used by the analytics page to build
     * the layout dynamically.
     */
    public function getReports(): array
    {
        return $this->reports;
    }

    /**
     * Run a single report by key. Uses cache unless $useCache is
     * false. Returns null if no report matches $key.
     */
    public function runReport(string $key, string $dateStart, string $dateEnd, bool $useCache = true): ?array
    {
        if ($useCache) {
            $cached = $this->cache->get($key, $dateStart, $dateEnd);
            if ($cached !== null) {
                return $cached;
            }
        }

        foreach ($this->reports as $r) {
            if ($r->getKey() === $key) {
                $data = $r->run($this->pdo, $dateStart, $dateEnd);
                $this->cache->put($key, $dateStart, $dateEnd, $data);
                return $data;
            }
        }
        return null;
    }

    /**
     * Run every report for the given range.
     * Returns ['key' => ['title' => ..., 'data' => [...]]].
     */
    public function runAll(string $dateStart, string $dateEnd, bool $useCache = true): array
    {
        $out = [];
        foreach ($this->reports as $r) {
            $key = $r->getKey();
            $out[$key] = [
                'title' => $r->getTitle(),
                'data'  => $this->runReport($key, $dateStart, $dateEnd, $useCache),
            ];
        }
        return $out;
    }

    /**
     * Invalidate the entire cache. Called when the admin clicks
     * "Refresh" on the analytics page.
     */
    public function clearCache(): int
    {
        return $this->cache->clearAll();
    }
}
