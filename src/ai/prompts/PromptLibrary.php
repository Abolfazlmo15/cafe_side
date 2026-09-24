<?php
// src/ai/prompts/PromptLibrary.php
// =============================================================
// Prompt templates for each report type.
//
// Every template returns an OpenAI-shaped messages array:
//   [
//     ['role' => 'system', 'content' => '...'],
//     ['role' => 'user',   'content' => '...'],
//   ]
//
// The system prompt is shared. The user prompt is built from the
// report's data.

class PromptLibrary
{
    private const SYSTEM_PROMPT = <<<'TXT'
You are an analyst reviewing a small café's sales data. The numbers you receive are already computed and correct.

Rules:
- Never invent or estimate numbers. Only reference numbers you were given.
- Write in plain prose. No bullet points. No markdown. No asterisks. No bold, no italic.
- Address the café owner directly as "you".
- Keep it to 2-3 sentences.
- Focus on what is notable. If everything looks normal, say so briefly.
- Do not suggest specific price changes or menu removals.
- Do not open with filler like "Based on the data" or "Looking at this". Just say what you see.
TXT;

    public static function has(string $key): bool
    {
        return isset(self::templates()[$key]);
    }

    public static function buildMessages(string $key, array $data, string $dateStart, string $dateEnd): ?array
    {
        $templates = self::templates();
        if (!isset($templates[$key])) {
            return null;
        }

        $userContent = $templates[$key]($data, $dateStart, $dateEnd);

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    private static function templates(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [

            'revenue_trend' => function (array $data, string $dateStart, string $dateEnd): string {
                $summary = $data['summary'] ?? [];
                $days    = $data['days']    ?? [];

                $dailyLines = [];
                foreach ($days as $d) {
                    $dailyLines[] = sprintf(
                        '  %s: %d orders, %s T revenue, %d items sold, AOV %s T',
                        $d['date'] ?? '?',
                        (int) ($d['orders'] ?? 0),
                        number_format((int) ($d['revenue'] ?? 0)),
                        (int) ($d['items_sold'] ?? 0),
                        number_format((int) ($d['aov'] ?? 0))
                    );
                }
                if (empty($dailyLines)) $dailyLines[] = '  (no daily rows in this range)';

                return sprintf(
                    "Report: Revenue Trend\nRange: %s to %s\n\n" .
                    "Total revenue: %s T\nTotal orders: %d\n" .
                    "Average order value: %s T\nDays with orders: %d\n\n" .
                    "Daily breakdown:\n%s",
                    $dateStart, $dateEnd,
                    number_format((int) ($summary['total_revenue'] ?? 0)),
                    (int) ($summary['total_orders'] ?? 0),
                    number_format((int) ($summary['avg_order_value'] ?? 0)),
                    (int) ($summary['days_with_orders'] ?? 0),
                    implode("\n", $dailyLines)
                );
            },

            'top_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items    = $data['items']     ?? [];
                $totalQty = (int) ($data['total_qty'] ?? 0);

                $lines = [];
                foreach (array_slice($items, 0, 10) as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d sold, %s T revenue, appeared in %d orders',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['qty'] ?? 0),
                        number_format((int) ($it['revenue'] ?? 0)),
                        (int) ($it['in_orders'] ?? 0)
                    );
                }
                if (empty($lines)) $lines[] = '  (no items sold in this range)';

                return sprintf(
                    "Report: Top Items\nRange: %s to %s\n\n" .
                    "Total items sold across all items: %d\n\n" .
                    "Top items by quantity:\n%s",
                    $dateStart, $dateEnd, $totalQty, implode("\n", $lines)
                );
            },

            'least_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items          = $data['items'] ?? [];
                $totalAvailable = (int) ($data['total_available'] ?? 0);

                $lines = [];
                foreach (array_slice($items, 0, 10) as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d sold, %s T revenue',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['qty'] ?? 0),
                        number_format((int) ($it['revenue'] ?? 0))
                    );
                }
                if (empty($lines)) $lines[] = '  (no available items)';

                return sprintf(
                    "Report: Least-Selling Items\nRange: %s to %s\n\n" .
                    "Total available items on the menu: %d\n\n" .
                    "Least-selling items (bottom 10):\n%s",
                    $dateStart, $dateEnd, $totalAvailable, implode("\n", $lines)
                );
            },

            'hourly_heatmap' => function (array $data, string $dateStart, string $dateEnd): string {
                $grid     = $data['grid']      ?? [];
                $max      = (int) ($data['max'] ?? 0);
                $dayNames = $data['day_names'] ?? ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

                $lines = [];
                foreach ($grid as $dayIdx => $hours) {
                    if (!is_array($hours)) continue;
                    $dayLabel = $dayNames[$dayIdx] ?? ('Day ' . $dayIdx);

                    $hourCounts = [];
                    foreach ($hours as $hrIdx => $count) {
                        $hourCounts[$hrIdx] = (int) $count;
                    }
                    arsort($hourCounts);
                    $top3 = array_slice($hourCounts, 0, 3, true);

                    $busyParts = [];
                    foreach ($top3 as $hrIdx => $count) {
                        if ($count > 0) $busyParts[] = sprintf('%02d:00 (%d)', $hrIdx, $count);
                    }

                    $lines[] = $dayLabel . ': ' . (empty($busyParts)
                        ? 'no orders'
                        : 'peak ' . implode(', ', $busyParts));
                }

                return sprintf(
                    "Report: Orders by Hour\nRange: %s to %s\n\n" .
                    "Busiest single hour: %d orders\n\n" .
                    "Per-day peak hours:\n%s",
                    $dateStart, $dateEnd, $max, implode("\n", $lines)
                );
            },

            'item_combos' => function (array $data, string $dateStart, string $dateEnd): string {
                $combos      = $data['combos'] ?? [];
                $totalOrders = (int) ($data['total_orders'] ?? 0);
                $minCount    = (int) ($data['min_count'] ?? 2);

                $lines = [];
                foreach ($combos as $i => $c) {
                    $lines[] = sprintf(
                        '  %d. %s + %s - appeared in %d orders',
                        $i + 1,
                        $c['a_name'] ?? '?',
                        $c['b_name'] ?? '?',
                        (int) ($c['count'] ?? 0)
                    );
                }
                if (empty($lines)) $lines[] = '  (no pairs met the minimum frequency)';

                return sprintf(
                    "Report: Frequently Bought Together\nRange: %s to %s\n\n" .
                    "Total orders in range: %d\n" .
                    "Showing pairs that co-occur in at least %d orders.\n\n" .
                    "Top pairs:\n%s",
                    $dateStart, $dateEnd, $totalOrders, $minCount, implode("\n", $lines)
                );
            },

            'fading_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items       = $data['items'] ?? [];
                $split       = $data['period_split'] ?? 'mid-range';
                $threshold   = (int) ($data['threshold_pct'] ?? 40);
                $minBefore   = (int) ($data['min_before'] ?? 3);

                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d sold before, %d sold after (%d%% change)',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0),
                        (int) ($it['after'] ?? 0),
                        (int) ($it['change_pct'] ?? 0)
                    );
                }
                if (empty($lines)) $lines[] = '  (no items dropped by that much)';

                return sprintf(
                    "Report: Fading Items\nRange: %s to %s (split at %s)\n\n" .
                    "An item is flagged if it had at least %d sales in the first half " .
                    "and then dropped by more than %d%% in the second half.\n\n" .
                    "Fading items:\n%s",
                    $dateStart, $dateEnd, $split, $minBefore, $threshold, implode("\n", $lines)
                );
            },

            'rising_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items       = $data['items'] ?? [];
                $split       = $data['period_split'] ?? 'mid-range';
                $threshold   = (int) ($data['threshold_pct'] ?? 40);
                $minBefore   = (int) ($data['min_before'] ?? 2);

                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d sold before, %d sold after (+%d%% change)',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0),
                        (int) ($it['after'] ?? 0),
                        (int) ($it['change_pct'] ?? 0)
                    );
                }
                if (empty($lines)) $lines[] = '  (no items grew by that much)';

                return sprintf(
                    "Report: Rising Items\nRange: %s to %s (split at %s)\n\n" .
                    "An item is flagged if it had at least %d sales in the first half " .
                    "and then grew by more than %d%% in the second half.\n\n" .
                    "Rising items:\n%s",
                    $dateStart, $dateEnd, $split, $minBefore, $threshold, implode("\n", $lines)
                );
            },

            'price_tier_shift' => function (array $data, string $dateStart, string $dateEnd): string {
                $tiers       = $data['tiers'] ?? [];
                $totalBefore = (int) ($data['total_before'] ?? 0);
                $totalAfter  = (int) ($data['total_after'] ?? 0);
                $split       = $data['period_split'] ?? 'mid-range';

                $lines = [];
                foreach ($tiers as $t) {
                    $change = $t['change_pct'];
                    $changeStr = ($change === null) ? 'n/a' : sprintf('%+d%%', (int) $change);
                    $lines[] = sprintf(
                        '  %s (%s): %d units before, %d units after, change %s',
                        $t['name'] ?? '?',
                        $t['range'] ?? '?',
                        (int) ($t['qty_before'] ?? 0),
                        (int) ($t['qty_after'] ?? 0),
                        $changeStr
                    );
                }
                if (empty($lines)) $lines[] = '  (no items in range)';

                return sprintf(
                    "Report: Price Tier Shift\nRange: %s to %s (split at %s)\n\n" .
                    "Total units sold before: %d, after: %d\n\n" .
                    "Per-tier breakdown:\n%s",
                    $dateStart, $dateEnd, $split, $totalBefore, $totalAfter, implode("\n", $lines)
                );
            },
        ];

        return $cache;
    }
}
