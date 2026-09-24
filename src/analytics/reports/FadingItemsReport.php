<?php
// src/analytics/reports/FadingItemsReport.php
// =============================================================
// Items whose sales dropped significantly in the second half of
// the range compared to the first half.
//
// Returns a 'reason' field when the report has no data, so the UI
// can explain the constraint instead of showing a dead empty state.

require_once __DIR__ . '/ReportBase.php';

class FadingItemsReport extends ReportBase
{
    public function getKey(): string { return 'fading_items'; }
    public function getTitle(): string { return 'Fading Items'; }

    private const DROP_THRESHOLD = 0.40;   // 40% drop
    private const MIN_BEFORE_QTY = 2;      // at least this many sales before
    private const LIMIT          = 10;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);

        // Split the range in half.
        $startTs = strtotime($dateStart);
        $endTs   = strtotime($dateEnd);
        $midTs   = (int) ($startTs + (($endTs - $startTs) / 2));
        $midDate = date('Y-m-d', $midTs);

        $beforeOrders = $this->loadOrdersInRange($pdo, $dateStart, $midDate);
        $afterStart   = date('Y-m-d', $midTs + 86400);
        $afterOrders  = ($afterStart <= $dateEnd)
            ? $this->loadOrdersInRange($pdo, $afterStart, $dateEnd)
            : [];

        // Determine why the report is empty (if it is).
        $reason = null;
        if (empty($beforeOrders) && empty($afterOrders)) {
            $reason = 'no_orders_in_range';
        } elseif (empty($beforeOrders)) {
            $reason = 'no_before_data';
        } elseif (empty($afterOrders)) {
            $reason = 'no_after_data';
        }

        $rows = [];

        if ($reason === null) {
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

            foreach ($tally as $id => $t) {
                if ($t['before'] < self::MIN_BEFORE_QTY) continue;
                $change = ($t['before'] > 0)
                    ? (($t['after'] - $t['before']) / $t['before'])
                    : 0;
                if ($change > -self::DROP_THRESHOLD) continue;
                $rows[] = [
                    'id'         => (int) $id,
                    'name'       => $menu[$id]['name'] ?? "Unknown (#$id)",
                    'before'     => (int) $t['before'],
                    'after'      => (int) $t['after'],
                    'change_pct' => (int) round($change * 100),
                ];
            }

            usort($rows, function ($a, $b) { return $a['change_pct'] - $b['change_pct']; });
            $rows = array_slice($rows, 0, self::LIMIT);

            if (empty($rows)) {
                $reason = 'no_significant_change';
            }
        }

        return [
            'items'         => $rows,
            'period_split'  => $midDate,
            'threshold_pct' => (int) (self::DROP_THRESHOLD * 100),
            'min_before'    => self::MIN_BEFORE_QTY,
            'reason'        => $reason,
        ];
    }
}
