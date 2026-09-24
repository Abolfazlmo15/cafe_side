<?php
// src/analytics/reports/TopItemsReport.php
// =============================================================
// Top-selling items by quantity. Includes items that were later
// deleted from the menu — the name falls back to "Unknown #id".

require_once __DIR__ . '/ReportBase.php';

class TopItemsReport extends ReportBase
{
    public function getKey(): string { return 'top_items'; }
    public function getTitle(): string { return 'Top Items'; }

    private const LIMIT = 10;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);
        $orders = $this->loadOrdersInRange($pdo, $dateStart, $dateEnd);

        $agg = [];
        foreach ($orders as $order) {
            $items = $this->decodeItems($order['items']);
            foreach ($items as $id => $qty) {
                if (!isset($agg[$id])) {
                    $agg[$id] = ['qty' => 0, 'revenue' => 0, 'orders' => 0];
                }
                $price = $menu[$id]['price'] ?? 0;
                $agg[$id]['qty'] += $qty;
                $agg[$id]['revenue'] += $price * $qty;
                $agg[$id]['orders']++;
            }
        }

        // Sort by quantity descending.
        uasort($agg, function ($a, $b) { return $b['qty'] - $a['qty']; });

        $items = [];
        $i = 0;
        foreach ($agg as $id => $row) {
            if ($i++ >= self::LIMIT) break;
            $items[] = [
                'id'        => $id,
                'name'      => $menu[$id]['name'] ?? "Unknown (#$id)",
                'qty'       => $row['qty'],
                'revenue'   => $row['revenue'],
                'in_orders' => $row['orders'],
            ];
        }

        $totalQty = 0;
        foreach ($agg as $row) $totalQty += $row['qty'];

        return [
            'items'     => $items,
            'total_qty' => $totalQty,
        ];
    }
}
