<?php
// src/analytics/reports/RevenueTrendReport.php
// =============================================================
// Daily revenue, order count, and average order value.
// Feeds a line chart on the analytics page.

require_once __DIR__ . '/ReportBase.php';

class RevenueTrendReport extends ReportBase
{
    public function getKey(): string { return 'revenue_trend'; }
    public function getTitle(): string { return 'Revenue Trend'; }

    public function run(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $menu = $this->loadMenuMap($pdo);
        $orders = $this->loadOrdersInRange($pdo, $dateStart, $dateEnd);

        $byDate = [];
        foreach ($orders as $order) {
            $date = date('Y-m-d', strtotime($order['created_at']));
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['orders' => 0, 'revenue' => 0, 'items_sold' => 0];
            }

            $items = $this->decodeItems($order['items']);
            $orderRevenue = 0;
            foreach ($items as $id => $qty) {
                $price = $menu[$id]['price'] ?? 0;
                $orderRevenue += $price * $qty;
                $byDate[$date]['items_sold'] += $qty;
            }

            $byDate[$date]['orders']++;
            $byDate[$date]['revenue'] += $orderRevenue;
        }

        ksort($byDate);

        $days = [];
        foreach ($byDate as $date => $d) {
            $days[] = [
                'date'       => $date,
                'orders'     => $d['orders'],
                'revenue'    => $d['revenue'],
                'items_sold' => $d['items_sold'],
                'aov'        => $d['orders'] > 0 ? (int) round($d['revenue'] / $d['orders']) : 0,
            ];
        }

        $totalRevenue = 0;
        $totalOrders = 0;
        foreach ($days as $d) {
            $totalRevenue += $d['revenue'];
            $totalOrders += $d['orders'];
        }

        return [
            'days'    => $days,
            'summary' => [
                'total_revenue'   => $totalRevenue,
                'total_orders'    => $totalOrders,
                'avg_order_value' => $totalOrders > 0 ? (int) round($totalRevenue / $totalOrders) : 0,
                'days_with_orders' => count($days),
            ],
        ];
    }
}
