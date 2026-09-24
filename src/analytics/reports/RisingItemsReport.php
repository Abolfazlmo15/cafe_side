<?php
// src/analytics/reports/RisingItemsReport.php
// =============================================================
// The inverse of FadingItemsReport. Items whose sales grew
// significantly in the second half of the range.

require_once __DIR__ . '/ReportBase.php';

class RisingItemsReport extends ReportBase
{
    public function getKey(): string { return 'rising_items'; }
    public function getTitle(): string { return 'Rising Items'; }

    private const GROWTH_THRESHOLD = 0.40;   // 40% growth
    private const MIN_BEFORE_QTY   = 2;      // must have existed before
    private const LIMIT            = 10;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);

        $startTs = strtotime($dateStart);
        $endTs   = strtotime($dateEnd);
        $midTs   = (int) ($startTs + (($endTs - $startTs) / 2));
        $midDate = date('Y-m-d', $midTs);

        $beforeOrders = $this->loadOrdersInRange($pdo, $dateStart, $midDate);
        $afterStart   = date('Y-m-d', $midTs + 86400);
        $afterOrders  = ($afterStart <= $dateEnd)
            ? $this->loadOrdersInRange($pdo, $afterStart, $dateEnd)
            : [];

        $tally = [];
        foreach ($beforeOrders as $o) {
            foreach ($this->decodeItems($o['items']) as $id => $qty) {
                if (!isset($tally[$id])) $tally[$id] = ['before' => 0, 'after' => 0];
                $tally[$id]['before'] += $qty;
            }
        }
        foreach ($afterOrders as $o) {
            foreach ($this->decodeItems($o['items']) as $id => $qty) {
                if (!isset($tally[$id])) $tally[$id] = ['before' => 0, 'after' => 0];
                $tally[$id]['after'] += $qty;
            }
        }

        $rows = [];
        foreach ($tally as $id => $t) {
            if ($t['before'] < self::MIN_BEFORE_QTY) continue;
            $change = (($t['after'] - $t['before']) / $t['before']);
            if ($change < self::GROWTH_THRESHOLD) continue;
            $rows[] = [
                'id'         => (int) $id,
                'name'       => $menu[$id]['name'] ?? "Unknown (#$id)",
                'before'     => (int) $t['before'],
                'after'      => (int) $t['after'],
                'change_pct' => (int) round($change * 100),
            ];
        }

        // Biggest growth first.
        usort($rows, function ($a, $b) { return $b['change_pct'] - $a['change_pct']; });
        $rows = array_slice($rows, 0, self::LIMIT);

        return [
            'items'         => $rows,
            'period_split'  => $midDate,
            'threshold_pct' => (int) (self::GROWTH_THRESHOLD * 100),
            'min_before'    => self::MIN_BEFORE_QTY,
        ];
    }
}
