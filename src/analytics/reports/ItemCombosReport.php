<?php
// src/analytics/reports/ItemCombosReport.php
// =============================================================
// Market basket analysis. For each order, find every unordered
// pair of items in it. Count how often each pair shows up across
// all orders. Return the top pairs.
//
// This answers: "which items are usually bought together?"

require_once __DIR__ . '/ReportBase.php';

class ItemCombosReport extends ReportBase
{
    public function getKey(): string { return 'item_combos'; }
    public function getTitle(): string { return 'Frequently Bought Together'; }

    private const LIMIT = 10;
    private const MIN_COUNT = 2;   // only surface pairs that appear at least twice

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu   = $this->loadMenuMap($pdo);
        $orders = $this->loadOrdersInRange($pdo, $dateStart, $dateEnd);

        $pairs = [];
        foreach ($orders as $order) {
            $items = $this->decodeItems($order['items']);
            $ids = array_keys($items);
            $n = count($ids);
            if ($n < 2) continue;

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = min($ids[$i], $ids[$j]);
                    $b = max($ids[$i], $ids[$j]);
                    $key = $a . '|' . $b;
                    if (!isset($pairs[$key])) {
                        $pairs[$key] = ['a' => $a, 'b' => $b, 'count' => 0];
                    }
                    $pairs[$key]['count']++;
                }
            }
        }

        // Sort by frequency, keep only pairs above the min threshold.
        uasort($pairs, function ($x, $y) { return $y['count'] - $x['count']; });

        $combos = [];
        foreach ($pairs as $row) {
            if ($row['count'] < self::MIN_COUNT) continue;
            if (count($combos) >= self::LIMIT) break;
            $combos[] = [
                'a_id'    => $row['a'],
                'a_name'  => $menu[$row['a']]['name'] ?? "Unknown (#" . $row['a'] . ")",
                'b_id'    => $row['b'],
                'b_name'  => $menu[$row['b']]['name'] ?? "Unknown (#" . $row['b'] . ")",
                'count'   => $row['count'],
            ];
        }

        return [
            'combos'      => $combos,
            'min_count'   => self::MIN_COUNT,
            'total_orders' => count($orders),
        ];
    }
}