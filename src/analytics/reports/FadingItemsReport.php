<?php
// src/analytics/reports/FadingItemsReport.php
// =============================================================
// Items whose sales dropped significantly in the second half of
// the range compared to the first half.
//
// The range is split in half. Each item is scored on:
//   before  = qty sold in first half
//   after   = qty sold in second half
//   change  = (after - before) / before
//
// Only items with at least MIN_BEFORE sales in the first half are
// considered (so a single sale dropping to zero doesn't trigger).
// Drop threshold: 40%.

require_once __DIR__ . '/ReportBase.php';

class FadingItemsReport extends ReportBase
{
    public function getKey(): string { return 'fading_items'; }
    public function getTitle(): string { return 'Fading Items'; }

    private const DROP_THRESHOLD  = 0.40;   // 40% drop
    private const MIN_BEFORE_QTY  = 3;      // must have at least this many sales before
    private const LIMIT           = 10;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);

        // Split the range in half.
        $startTs = strtotime($dateStart);
        $endTs   = strtotime($dateEnd);
        $midTs   = (int) ($startTs + (($endTs - $startTs) / 2));
        $midDate = date('Y-m-d', $midTs);

        // First half: [dateStart .. midDate]
        // Second half: (midDate .. dateEnd]
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
            $change = ($t['before'] > 0)
                ? (($t['after'] - $t['before']) / $t['before'])
                : 0;
            if ($change > -self::DROP_THRESHOLD) continue;   // not a big enough drop
            $rows[] = [
                'id'         => (int) $id,
                'name'       => $menu[$id]['name'] ?? "Unknown (#$id)",
                'before'     => (int) $t['before'],
                'after'      => (int) $t['after'],
                'change_pct' => (int) round($change * 100),
            ];
        }

        // Biggest drop first.
        usort($rows, function ($a, $b) { return $a['change_pct'] - $b['change_pct']; });
        $rows = array_slice($rows, 0, self::LIMIT);

        return [
            'items'         => $rows,
            'period_split'  => $midDate,
            'threshold_pct' => (int) (self::DROP_THRESHOLD * 100),
            'min_before'    => self::MIN_BEFORE_QTY,
        ];
    }
}