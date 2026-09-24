<?php
// src/ai/prompts/PromptLibrary.php
// =============================================================
// Two prompt families:
//   1. Narration prompts - narrate SQL data as prose
//   2. Processor prompts - re-curate SQL data as structured JSON
//
// All dates sent to the AI are Jalali.

require_once __DIR__ . '/../../helpers/Jalali.php';

class PromptLibrary
{
    private const SYSTEM_PROMPT = <<<'TXT'
You are an analyst reviewing a small Iranian café's sales data. The numbers you receive are already computed and correct. Dates are in the Jalali (Iranian) calendar, in the form "3 Mehr 1405".

Rules:
- Never invent or estimate numbers. Only reference numbers you were given.
- Write in plain prose. No bullet points. No markdown. No asterisks. No bold, no italic.
- Address the café owner directly as "you".
- Keep it to 2-3 sentences.
- Focus on what is notable. If everything looks normal, say so briefly.
- Do not suggest specific price changes or menu removals.
- Do not open with filler like "Based on the data" or "Looking at this". Just say what you see.
- Refer to dates in Jalali, never Gregorian.
TXT;

    private const EXEC_REVIEW_SYSTEM = <<<'TXT'
You are writing an executive review of an Iranian café's sales period for the owner. The numbers you receive are already computed and correct. Dates are in the Jalali (Iranian) calendar, in the form "3 Mehr 1405".

Rules:
- Never invent numbers. Only reference values you were given.
- Write in plain prose. No bullet points. No markdown. No asterisks.
- Length: 3-5 sentences.
- Address the café owner directly as "you".
- Lead with the single most notable fact about the period.
- If the data has obvious limitations (like only one day of sales), say so plainly.
- Do not suggest specific price changes or menu removals.
- Refer to dates in Jalali, never Gregorian.
TXT;

    private const PROCESSOR_SYSTEM = <<<'TXT'
You are curating a café analytics report. The SQL engine already computed the raw numbers. You receive that data as JSON.

Your job: return a JSON object with EXACTLY the same schema (same keys, same field types), but curated by your judgment.

Absolute rules:
- Never invent numbers. Every numeric value you output must come from the input.
- Never invent items. Only include items that exist in the input.
- Do not modify any date fields. Copy them verbatim from the input.
- Output MUST be raw JSON. No prose. No markdown. No code fences. Just the object.

You may:
- Re-order entries.
- Remove entries (cap the list at a reasonable size).
- Adjust thresholds (for fading/rising).
- Choose different grouping boundaries (for price tiers).

You may NOT:
- Add new fields to the JSON structure.
- Add or remove top-level keys.
- Change any numeric value.
TXT;

    public static function has(string $key): bool
    {
        return isset(self::templates()[$key]);
    }

    public static function buildMessages(string $key, array $data, string $dateStart, string $dateEnd): ?array
    {
        $templates = self::templates();
        if (!isset($templates[$key])) return null;

        $userContent = $templates[$key]($data, $dateStart, $dateEnd);
        $system = ($key === 'executive_summary') ? self::EXEC_REVIEW_SYSTEM : self::SYSTEM_PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    public static function buildProcessMessages(string $reportKey, array $sqlData, string $dateStart, string $dateEnd): ?array
    {
        $processors = self::processors();
        if (!isset($processors[$reportKey])) return null;

        $user = $processors[$reportKey]($sqlData, $dateStart, $dateEnd);
        return [
            ['role' => 'system', 'content' => self::PROCESSOR_SYSTEM],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ---------------------------------------------------------
    // Processor closures
    // ---------------------------------------------------------

    private static function processors(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [

            'top_items' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Top Items. Range %s to %s.\n" .
                    "Task: select up to 10 items that are most noteworthy from an owner's perspective. " .
                    "This is not necessarily the top by quantity. Consider revenue, revenue per unit, and items " .
                    "that stand out as unexpectedly strong. Return the same JSON schema: {\"items\":[...], \"total_qty\": N}.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },

            'least_items' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Least-Selling Items. Range %s to %s.\n" .
                    "Task: select up to 10 items that warrant the owner's attention. These could be underperformers " .
                    "OR items that seem under-promoted. Prioritize usefulness over pure lowest-qty ranking. " .
                    "Return the same JSON schema.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },

            'item_combos' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Frequently Bought Together. Range %s to %s.\n" .
                    "Task: select up to 10 pairs that suggest the strongest promotional opportunities. You may " .
                    "deprioritize pairs that are trivially obvious. Return the same JSON schema.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },

            'fading_items' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Fading Items. Range %s to %s.\n" .
                    "Task: pick the items that are fading most notably. You decide the significance threshold. " .
                    "Include up to 10 items. You may update the threshold_pct field. " .
                    "If no items seem genuinely fading, return an empty items array. " .
                    "Return the same JSON schema.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },

            'rising_items' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Rising Items. Range %s to %s.\n" .
                    "Task: pick the items with the strongest growth momentum. You decide the significance threshold. " .
                    "Include up to 10 items. You may update the threshold_pct field. " .
                    "If nothing is genuinely rising, return an empty items array. " .
                    "Return the same JSON schema.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },

            'price_tier_shift' => function (array $data, string $dateStart, string $dateEnd): string {
                return sprintf(
                    "Report: Price Tier Shift. Range %s to %s.\n" .
                    "Task: re-curate the price tiers. You may rebalance the menu's tiers (keep three tiers named " .
                    "Cheap, Medium, Expensive) and adjust their cut-points based on what the data suggests. " .
                    "You must keep the same JSON schema. Recompute nothing. Only use the input numbers.\n\n" .
                    "SQL data:\n%s",
                    Jalali::formatHuman($dateStart),
                    Jalali::formatHuman($dateEnd),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
        ];

        return $cache;
    }

    // ---------------------------------------------------------
    // Narration templates
    // ---------------------------------------------------------

    private static function templates(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [

            'revenue_trend' => function (array $data, string $dateStart, string $dateEnd): string {
                $s    = $data['summary'] ?? [];
                $days = $data['days'] ?? [];

                $lines = [];
                foreach ($days as $d) {
                    $lines[] = sprintf(
                        '  %s: %d orders, %s T, %d items, AOV %s T',
                        Jalali::formatHuman($d['date'] ?? ''),
                        (int) ($d['orders'] ?? 0),
                        number_format((int) ($d['revenue'] ?? 0)),
                        (int) ($d['items_sold'] ?? 0),
                        number_format((int) ($d['aov'] ?? 0))
                    );
                }
                if (empty($lines)) $lines[] = '  (none)';

                return sprintf(
                    "Report: Revenue Trend\nRange: %s to %s\n\n" .
                    "Total revenue: %s T\nTotal orders: %d\n" .
                    "Avg order value: %s T\nDays with orders: %d\n\nDaily:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    number_format((int) ($s['total_revenue'] ?? 0)),
                    (int) ($s['total_orders'] ?? 0),
                    number_format((int) ($s['avg_order_value'] ?? 0)),
                    (int) ($s['days_with_orders'] ?? 0),
                    implode("\n", $lines)
                );
            },

            'top_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items = $data['items'] ?? [];
                $tq    = (int) ($data['total_qty'] ?? 0);

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
                if (empty($lines)) $lines[] = '  (none)';

                return sprintf(
                    "Report: Top Items\nRange: %s to %s\n\n" .
                    "Total items sold: %d\n\nTop items:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    $tq, implode("\n", $lines)
                );
            },

            'least_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items = $data['items'] ?? [];
                $ta    = (int) ($data['total_available'] ?? 0);

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
                if (empty($lines)) $lines[] = '  (none)';

                return sprintf(
                    "Report: Least-Selling Items\nRange: %s to %s\n\n" .
                    "Available items: %d\n\nBottom 10:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    $ta, implode("\n", $lines)
                );
            },

            'hourly_heatmap' => function (array $data, string $dateStart, string $dateEnd): string {
                $grid = $data['grid'] ?? [];
                $max  = (int) ($data['max'] ?? 0);
                $dn   = $data['day_names'] ?? ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

                $lines = [];
                foreach ($grid as $di => $hours) {
                    if (!is_array($hours)) continue;
                    $hc = [];
                    foreach ($hours as $hi => $c) $hc[$hi] = (int) $c;
                    arsort($hc);
                    $top  = array_slice($hc, 0, 3, true);
                    $busy = [];
                    foreach ($top as $hi => $c) {
                        if ($c > 0) $busy[] = sprintf('%02d:00 (%d)', $hi, $c);
                    }
                    $lines[] = ($dn[$di] ?? '?') . ': ' . (empty($busy) ? 'no orders' : 'peak ' . implode(', ', $busy));
                }

                return sprintf(
                    "Report: Orders by Hour\nRange: %s to %s\n\n" .
                    "Busiest hour: %d orders\n\nPer day:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    $max, implode("\n", $lines)
                );
            },

            'item_combos' => function (array $data, string $dateStart, string $dateEnd): string {
                $combos = $data['combos'] ?? [];
                $to     = (int) ($data['total_orders'] ?? 0);

                $lines = [];
                foreach ($combos as $i => $c) {
                    $lines[] = sprintf(
                        '  %d. %s + %s - %d orders',
                        $i + 1,
                        $c['a_name'] ?? '?',
                        $c['b_name'] ?? '?',
                        (int) ($c['count'] ?? 0)
                    );
                }
                if (empty($lines)) $lines[] = '  (none)';

                return sprintf(
                    "Report: Frequently Bought Together\nRange: %s to %s\n\n" .
                    "Orders: %d\n\nTop pairs:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    $to, implode("\n", $lines)
                );
            },

            'fading_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items  = $data['items'] ?? [];
                $split  = $data['period_split'] ?? $dateStart;
                $th     = (int) ($data['threshold_pct'] ?? 40);
                $reason = $data['reason'] ?? null;

                if ($reason !== null) {
                    return sprintf(
                        "Report: Fading Items\nRange: %s to %s (split at %s)\n\n" .
                        "Status: no data. Reason: %s.",
                        Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                        Jalali::formatHuman($split), $reason
                    );
                }

                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d before, %d after (%d%%)',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0),
                        (int) ($it['after'] ?? 0),
                        (int) ($it['change_pct'] ?? 0)
                    );
                }

                return sprintf(
                    "Report: Fading Items\nRange: %s to %s (split at %s)\n\n" .
                    "Threshold: %d%% drop.\n\nFading:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    Jalali::formatHuman($split), $th, implode("\n", $lines)
                );
            },

            'rising_items' => function (array $data, string $dateStart, string $dateEnd): string {
                $items  = $data['items'] ?? [];
                $split  = $data['period_split'] ?? $dateStart;
                $th     = (int) ($data['threshold_pct'] ?? 40);
                $reason = $data['reason'] ?? null;

                if ($reason !== null) {
                    return sprintf(
                        "Report: Rising Items\nRange: %s to %s (split at %s)\n\n" .
                        "Status: no data. Reason: %s.",
                        Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                        Jalali::formatHuman($split), $reason
                    );
                }

                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf(
                        '  %d. %s - %d before, %d after (+%d%%)',
                        $i + 1,
                        $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0),
                        (int) ($it['after'] ?? 0),
                        (int) ($it['change_pct'] ?? 0)
                    );
                }

                return sprintf(
                    "Report: Rising Items\nRange: %s to %s (split at %s)\n\n" .
                    "Threshold: %d%% growth.\n\nRising:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    Jalali::formatHuman($split), $th, implode("\n", $lines)
                );
            },

            'price_tier_shift' => function (array $data, string $dateStart, string $dateEnd): string {
                $tiers  = $data['tiers'] ?? [];
                $tb     = (int) ($data['total_before'] ?? 0);
                $ta     = (int) ($data['total_after'] ?? 0);
                $split  = $data['period_split'] ?? $dateStart;
                $reason = $data['reason'] ?? null;

                $lines = [];
                foreach ($tiers as $t) {
                    $ch    = $t['change_pct'];
                    $chStr = ($ch === null) ? 'n/a' : sprintf('%+d%%', (int) $ch);
                    $lines[] = sprintf(
                        '  %s (%s): %d before (share %d%%), %d after (share %d%%), change %s',
                        $t['name'] ?? '?',
                        $t['range'] ?? '?',
                        (int) ($t['qty_before'] ?? 0),
                        (int) ($t['share_before'] ?? 0),
                        (int) ($t['qty_after'] ?? 0),
                        (int) ($t['share_after'] ?? 0),
                        $chStr
                    );
                }

                $reasonStr = '';
                if ($reason === 'no_before_data') {
                    $reasonStr = "\n\nIMPORTANT: First half has zero orders. Explain this clearly.";
                } elseif ($reason === 'no_after_data') {
                    $reasonStr = "\n\nIMPORTANT: Second half has zero orders.";
                } elseif ($reason === 'no_orders_in_range') {
                    $reasonStr = "\n\nIMPORTANT: Range has zero orders.";
                }

                return sprintf(
                    "Report: Price Tier Shift\nRange: %s to %s (split at %s)\n\n" .
                    "Total before: %d, after: %d\n\nTiers:\n%s%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    Jalali::formatHuman($split), $tb, $ta,
                    implode("\n", $lines), $reasonStr
                );
            },

            'executive_summary' => function (array $data, string $dateStart, string $dateEnd): string {
                $summary = $data['summary'] ?? [];
                $days    = $data['days'] ?? [];
                $top     = $data['top_items'] ?? [];
                $tq      = (int) ($data['total_items_sold'] ?? 0);

                $dayList = [];
                foreach ($days as $d) {
                    $dayList[] = sprintf(
                        '  %s: %d orders, %s T',
                        Jalali::formatHuman($d['date'] ?? ''),
                        (int) ($d['orders'] ?? 0),
                        number_format((int) ($d['revenue'] ?? 0))
                    );
                }

                $topList = [];
                foreach (array_slice($top, 0, 3) as $it) {
                    $topList[] = sprintf('  %s (%d sold)', $it['name'] ?? '?', (int) ($it['qty'] ?? 0));
                }

                return sprintf(
                    "Executive review.\nRange: %s to %s\n\n" .
                    "Total revenue: %s T\nTotal orders: %d\n" .
                    "Avg order value: %s T\nDays with orders: %d\n" .
                    "Total items sold: %d\n\nDaily:\n%s\n\nTop 3:\n%s",
                    Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                    number_format((int) ($summary['total_revenue'] ?? 0)),
                    (int) ($summary['total_orders'] ?? 0),
                    number_format((int) ($summary['avg_order_value'] ?? 0)),
                    (int) ($summary['days_with_orders'] ?? 0),
                    $tq,
                    empty($dayList) ? '  (none)' : implode("\n", $dayList),
                    empty($topList) ? '  (none)' : implode("\n", $topList)
                );
            },
        ];

        return $cache;
    }
}