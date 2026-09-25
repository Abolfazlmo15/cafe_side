<?php
// src/analytics/reports/AnomalyReport.php
// =============================================================
// Detects days that deviate significantly from the rolling
// average of the preceding week. Returns a list of flagged days.
//
// Algorithm:
//   1. Aggregate orders + revenue per day
//   2. For each day, compute the average of the previous 7 days
//   3. If today's value deviates by more than THRESHOLD_PCT, flag it
//   4. Skip the first 7 days (no baseline)
//   5. Skip days with a baseline too small to be meaningful
//
// One anomaly per day maximum — revenue takes priority over orders.

require_once __DIR__ . '/ReportBase.php';

class AnomalyReport extends ReportBase
{
    public function getKey(): string { return 'anomalies'; }
    public function getTitle(): string { return 'Anomalies'; }

    // Rolling window: average of the previous N days
    private const WINDOW_DAYS   = 7;

    // Deviation threshold: |change| / baseline × 100 ≥ this value
    private const THRESHOLD_PCT = 150;

    // Minimum baseline revenue (T) to bother comparing
    private const MIN_BASELINE  = 500000;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu   = $this->loadMenuMap($pdo);
        $orders = $this->loadOrdersInRange($pdo, $dateStart, $dateEnd);

        // ── 1. Aggregate per day ──────────────────────────────
        $byDay = [];
        foreach ($orders as $order) {
            $date = date('Y-m-d', strtotime($order['created_at']));
            if (!isset($byDay[$date])) {
                $byDay[$date] = ['orders' => 0, 'revenue' => 0];
            }
            $byDay[$date]['orders']++;
            foreach ($this->decodeItems($order['items']) as $id => $qty) {
                $byDay[$date]['revenue'] += ($menu[$id]['price'] ?? 0) * $qty;
            }
        }

        // ── 2. Fill gaps so every day exists ──────────────────
        $start  = new DateTime($dateStart);
        $end    = new DateTime($dateEnd);
        $cursor = clone $start;
        while ($cursor <= $end) {
            $iso = $cursor->format('Y-m-d');
            if (!isset($byDay[$iso])) {
                $byDay[$iso] = ['orders' => 0, 'revenue' => 0];
            }
            $cursor->modify('+1 day');
        }
        ksort($byDay);
        $dates = array_keys($byDay);

        // ── 3. Sliding window scan ────────────────────────────
        $anomalies = [];
        $skipped   = 0;

        for ($i = 0; $i < count($dates); $i++) {
            $date = $dates[$i];

            // Not enough history yet
            if ($i < self::WINDOW_DAYS) {
                $skipped++;
                continue;
            }

            // Average of the previous WINDOW_DAYS days
            $windowRevenue = [];
            $windowOrders  = [];
            for ($j = $i - self::WINDOW_DAYS; $j < $i; $j++) {
                $windowRevenue[] = $byDay[$dates[$j]]['revenue'];
                $windowOrders[]  = $byDay[$dates[$j]]['orders'];
            }
            $avgRevenue = array_sum($windowRevenue) / count($windowRevenue);
            $avgOrders  = array_sum($windowOrders)  / count($windowOrders);

            // Too small a baseline to be meaningful
            if ($avgRevenue < self::MIN_BASELINE && $avgOrders < 2) {
                continue;
            }

            $todayRevenue = $byDay[$date]['revenue'];
            $todayOrders  = $byDay[$date]['orders'];

            // Revenue deviation takes priority
            if ($avgRevenue > 0) {
                $devPct = (($todayRevenue - $avgRevenue) / $avgRevenue) * 100;
                if (abs($devPct) >= self::THRESHOLD_PCT) {
                    $anomalies[] = [
                        'date'            => $date,
                        'metric'          => 'revenue',
                        'value'           => (int) $todayRevenue,
                        'baseline'        => (int) round($avgRevenue),
                        'deviation_pct'   => (int) round($devPct),
                        'direction'       => $devPct > 0 ? 'spike' : 'drop',
                        'orders'          => $todayOrders,
                        'baseline_orders' => (int) round($avgOrders),
                    ];
                    continue; // one anomaly per day max
                }
            }

            // Fall back to orders deviation
            if ($avgOrders > 0) {
                $devPct = (($todayOrders - $avgOrders) / $avgOrders) * 100;
                if (abs($devPct) >= self::THRESHOLD_PCT) {
                    $anomalies[] = [
                        'date'            => $date,
                        'metric'          => 'orders',
                        'value'           => $todayOrders,
                        'baseline'        => (int) round($avgOrders),
                        'deviation_pct'   => (int) round($devPct),
                        'direction'       => $devPct > 0 ? 'spike' : 'drop',
                        'orders'          => $todayOrders,
                        'baseline_orders' => (int) round($avgOrders),
                    ];
                }
            }
        }

        // Newest first
        usort($anomalies, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return [
            'anomalies'       => $anomalies,
            'baseline_window' => self::WINDOW_DAYS,
            'threshold_pct'   => self::THRESHOLD_PCT,
            'min_baseline'    => self::MIN_BASELINE,
            'days_analyzed'   => count($dates),
            'days_skipped'    => $skipped,
            'reason'          => empty($anomalies) ? 'no_anomalies_found' : null,
        ];
    }
}