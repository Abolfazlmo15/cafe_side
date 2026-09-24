<?php
// src/analytics/AnalyticsEngine.php
// =============================================================
// Orchestrator. Registers every report, runs them, caches results.
//
// Phase 3.2: 8 reports total.

require_once __DIR__ . '/AnalyticsCache.php';
require_once __DIR__ . '/reports/RevenueTrendReport.php';
require_once __DIR__ . '/reports/TopItemsReport.php';
require_once __DIR__ . '/reports/LeastItemsReport.php';
require_once __DIR__ . '/reports/HourlyHeatmapReport.php';
require_once __DIR__ . '/reports/ItemCombosReport.php';
require_once __DIR__ . '/reports/FadingItemsReport.php';
require_once __DIR__ . '/reports/RisingItemsReport.php';
require_once __DIR__ . '/reports/PriceTierShiftReport.php';

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
            new ItemCombosReport(),
            new FadingItemsReport(),
            new RisingItemsReport(),
            new PriceTierShiftReport(),
        ];
    }

    public function getReports(): array
    {
        return $this->reports;
    }

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

    public function clearCache(): int
    {
        return $this->cache->clearAll();
    }
}
