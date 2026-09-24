<?php
// src/analytics/reports/ReportBase.php
// =============================================================
// Abstract base class for every analytics report.
//
// A report is a single class with one method: run(). It receives
// a date range and returns a plain PHP array. The engine caches
// the result. The Vue app renders it.
//
// Shared helpers live here so each report stays small.

abstract class ReportBase
{
    /**
     * Short identifier — used as the analytics_cache key.
     * Must be unique across all reports.
     */
    abstract public function getKey(): string;

    /**
     * Human-readable title shown on the analytics page.
     */
    abstract public function getTitle(): string;

    /**
     * Run the report and return its data structure.
     * $dateStart and $dateEnd are 'YYYY-MM-DD' strings (inclusive).
     */
    abstract public function run(PDO $pdo, string $dateStart, string $dateEnd): array;

    /**
     * Load the menu once — id => [name, price]. Reports use this to
     * resolve item names and prices from the JSON payload stored in
     * orders.items.
     */
    protected function loadMenuMap(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, name, price FROM menu_items");
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[(int) $row['id']] = [
                'name'  => (string) $row['name'],
                'price' => (int) $row['price'],
            ];
        }
        return $map;
    }

    /**
     * Load all orders in a date range. Returns rows with id, items
     * (JSON string), and created_at.
     */
    protected function loadOrdersInRange(PDO $pdo, string $dateStart, string $dateEnd): array
    {
        $stmt = $pdo->prepare("
            SELECT id, items, created_at
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$dateStart, $dateEnd]);
        return $stmt->fetchAll();
    }

    /**
     * Decode an order's items JSON. Always returns an array of
     * (int)$itemId => (int)$qty.
     */
    protected function decodeItems(?string $json): array
    {
        if (empty($json)) return [];
        $items = json_decode($json, true);
        if (!is_array($items)) return [];

        $clean = [];
        foreach ($items as $id => $qty) {
            $idInt = (int) $id;
            $qtyInt = (int) $qty;
            if ($idInt <= 0 || $qtyInt <= 0) continue;
            $clean[$idInt] = $qtyInt;
        }
        return $clean;
    }
}
