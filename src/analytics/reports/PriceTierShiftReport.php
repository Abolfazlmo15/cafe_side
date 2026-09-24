<?php
// src/analytics/reports/PriceTierShiftReport.php
// =============================================================
// Buckets the current menu into three price tiers (cheap, medium,
// expensive) using tertiles of the item prices. Then, for the
// first half vs second half of the range, counts how many units
// were sold from each tier.
//
// Answers: "are customers drifting toward cheaper items?"

require_once __DIR__ . '/ReportBase.php';

class PriceTierShiftReport extends ReportBase
{
    public function getKey(): string { return 'price_tier_shift'; }
    public function getTitle(): string { return 'Price Tier Shift'; }

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);
        if (empty($menu)) {
            return ['tiers' => [], 'total_before' => 0, 'total_after' => 0];
        }

        // Determine tertile cutoffs from all prices.
        $prices = array_column($menu, 'price');
        sort($prices);
        $n = count($prices);
        $p33 = $prices[(int) floor($n * 0.33)];
        $p66 = $prices[(int) floor($n * 0.66)];

        // Assign each item to a tier.
        $tierOf = [];
        foreach ($menu as $id => $row) {
            $p = (int) $row['price'];
            if ($p <= $p33)      $tierOf[$id] = 'cheap';
            elseif ($p <= $p66)  $tierOf[$id] = 'medium';
            else                 $tierOf[$id] = 'expensive';
        }

        // Split range in half.
        $startTs = strtotime($dateStart);
        $endTs   = strtotime($dateEnd);
        $midTs   = (int) ($startTs + (($endTs - $startTs) / 2));
        $midDate = date('Y-m-d', $midTs);
        $afterStart = date('Y-m-d', $midTs + 86400);

        $beforeOrders = $this->loadOrdersInRange($pdo, $dateStart, $midDate);
        $afterOrders  = ($afterStart <= $dateEnd)
            ? $this->loadOrdersInRange($pdo, $afterStart, $dateEnd)
            : [];

        $tiers = [
            'cheap'     => ['qty_before' => 0, 'qty_after' => 0, 'rev_before' => 0, 'rev_after' => 0],
            'medium'    => ['qty_before' => 0, 'qty_after' => 0, 'rev_before' => 0, 'rev_after' => 0],
            'expensive' => ['qty_before' => 0, 'qty_after' => 0, 'rev_before' => 0, 'rev_after' => 0],
        ];

        foreach ($beforeOrders as $o) {
            foreach ($this->decodeItems($o['items']) as $id => $qty) {
                $tier = $tierOf[$id] ?? null;
                if (!$tier) continue;
                $tiers[$tier]['qty_before'] += $qty;
                $tiers[$tier]['rev_before'] += $qty * (int) ($menu[$id]['price'] ?? 0);
            }
        }
        foreach ($afterOrders as $o) {
            foreach ($this->decodeItems($o['items']) as $id => $qty) {
                $tier = $tierOf[$id] ?? null;
                if (!$tier) continue;
                $tiers[$tier]['qty_after'] += $qty;
                $tiers[$tier]['rev_after'] += $qty * (int) ($menu[$id]['price'] ?? 0);
            }
        }

        $out = [];
        $labels = ['cheap' => 'Cheap', 'medium' => 'Medium', 'expensive' => 'Expensive'];
        $ranges = [
            'cheap'     => 'up to ' . number_format($p33) . ' T',
            'medium'    => number_format($p33 + 1) . ' - ' . number_format($p66) . ' T',
            'expensive' => number_format($p66 + 1) . ' T and up',
        ];

        foreach ($tiers as $key => $t) {
            $changePct = ($t['qty_before'] > 0)
                ? (int) round((($t['qty_after'] - $t['qty_before']) / $t['qty_before']) * 100)
                : null;

            $out[] = [
                'key'         => $key,
                'name'        => $labels[$key],
                'range'       => $ranges[$key],
                'qty_before'  => $t['qty_before'],
                'qty_after'   => $t['qty_after'],
                'rev_before'  => $t['rev_before'],
                'rev_after'   => $t['rev_after'],
                'change_pct'  => $changePct,
            ];
        }

        $totalBefore = $tiers['cheap']['qty_before'] + $tiers['medium']['qty_before'] + $tiers['expensive']['qty_before'];
        $totalAfter  = $tiers['cheap']['qty_after'] + $tiers['medium']['qty_after'] + $tiers['expensive']['qty_after'];

        return [
            'tiers'         => $out,
            'total_before'  => $totalBefore,
            'total_after'   => $totalAfter,
            'period_split'  => $midDate,
        ];
    }
}
