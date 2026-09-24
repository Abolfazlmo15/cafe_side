<?php
// src/analytics/reports/LeastItemsReport.php
// =============================================================
// Items that sell the least. Only looks at items currently marked
// available=1 in the menu. Purpose: find candidates for removal
// or promotion. Deleted items are ignored.

require_once __DIR__ . '/ReportBase.php';

class LeastItemsReport extends ReportBase
{
    public function getKey(): string { return 'least_items'; }
    public function getTitle(): string { return 'Least-Selling Items'; }

    private const LIMIT = 10;

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        // Only consider menu items that are currently available.
        $stmt = $pdo->query("SELECT id, name, price FROM menu_items WHERE available = 1");
        $available = [];
        while ($row = $stmt->fetch()) {
            $available[(int) $row['id']] = [
                'name'  => (string) $row['name'],
                'price' => (int) $row['price'],
                'qty'   => 0,
                'revenue' => 0,
            ];
        }

        if (empty($available)) {
            return ['items' => [], 'total_available' => 0];
        }

        $orders = $this->loadOrdersInRange($pdo, $dateStart, $dateEnd);
        foreach ($orders as $order) {
            $items = $this->decodeItems($order['items']);
            foreach ($items as $id => $qty) {
                if (!isset($available[$id])) continue;
                $available[$id]['qty'] += $qty;
                $available[$id]['revenue'] += $available[$id]['price'] * $qty;
            }
        }

        // Sort ascending by quantity.
        uasort($available, function ($a, $b) { return $a['qty'] - $b['qty']; });

        $items = [];
        $i = 0;
        foreach ($available as $id => $row) {
            if ($i++ >= self::LIMIT) break;
            $items[] = [
                'id'      => $id,
                'name'    => $row['name'],
                'qty'     => $row['qty'],
                'revenue' => $row['revenue'],
            ];
        }

        return [
            'items'           => $items,
            'total_available' => count($available),
        ];
    }
}
