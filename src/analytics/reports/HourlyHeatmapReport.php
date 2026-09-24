<?php
// src/analytics/reports/HourlyHeatmapReport.php
// =============================================================
// Order density by day-of-week and hour-of-day.
// 7 rows x 24 columns. Each cell = order count.
// Renders as a grid on the analytics page.

require_once __DIR__ . '/ReportBase.php';

class HourlyHeatmapReport extends ReportBase
{
    public function getKey(): string { return 'hourly_heatmap'; }
    public function getTitle(): string { return 'Orders by Hour'; }

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $stmt = $pdo->prepare("
            SELECT HOUR(created_at) AS hr, DAYOFWEEK(created_at) AS dow, COUNT(*) AS c
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY hr, dow
        ");
        $stmt->execute([$dateStart, $dateEnd]);
        $rows = $stmt->fetchAll();

        // DAYOFWEEK in MySQL: 1=Sunday ... 7=Saturday.
        // We want 0=Sunday ... 6=Saturday for cleaner indexing.
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $grid[$d] = array_fill(0, 24, 0);
        }

        $max = 0;
        foreach ($rows as $r) {
            $dow = ((int) $r['dow']) - 1;   // shift to 0-based
            $hr  = (int) $r['hr'];
            $c   = (int) $r['c'];
            if ($dow < 0 || $dow > 6 || $hr < 0 || $hr > 23) continue;
            $grid[$dow][$hr] = $c;
            if ($c > $max) $max = $c;
        }

        return [
            'grid'       => $grid,
            'max'        => $max,
            'day_names'  => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
            'hour_names' => array_map(function ($h) {
                return str_pad((string) $h, 2, '0', STR_PAD_LEFT);
            }, range(0, 23)),
        ];
    }
}
