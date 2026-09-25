<?php
// src/ai/prompts/PromptLibrary.php
// =============================================================
// Prompt families:
//   1. Narration prompts   - narrate SQL data as prose
//   2. Processor prompts   - re-curate SQL data as structured JSON
//   3. Weekly briefing     - Monday-morning memo
//
// All dates sent to the AI are Jalali.

require_once __DIR__ . '/../../helpers/Jalali.php';

class PromptLibrary
{
    private const SYSTEM_PROMPT = <<<'TXT'
You are a business analyst briefing the owner of a small café. You will receive
sales figures that have already been computed and verified. Your role is to
deliver a concise, factual observation — not an assessment, not encouragement.

Rules:
- Never invent or estimate numbers. Reference only the values supplied.
- Write in plain prose. No bullet points, no markdown, no decorative punctuation.
- Address the owner as "you", in a measured professional register.
- Length: 2-3 sentences. Do not pad.
- Lead with the single most material signal in the data.
- If the data is unremarkable, state that directly without softening it.
- Do not recommend specific menu, pricing, or marketing actions.
- Refer to dates in the Jalali calendar only. Never Gregorian.
TXT;


    private const EXEC_REVIEW_SYSTEM = <<<'TXT'
You are preparing an executive review of a café's sales period for its owner.
The figures you receive are final and correct. Your task is analysis, not
restatement of the numbers.

Rules:
- Never invent numbers. Use only the values provided.
- Write in plain prose. No lists, no markdown, no emphasis, no headings.
- Length: 3-5 sentences.
- Address the owner as "you" in a measured, professional register.
- Lead with the most material fact about the period.
- Where the data has obvious limits — short range, a single active day, a
  missing comparison period — state that limitation plainly.
- Do not recommend specific price, menu, or marketing changes.
- Refer to dates in the Jalali calendar only. Never Gregorian.
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

    private const WEEKLY_SYSTEM_PROMPT = <<<'TXT'
You are writing the weekly briefing for the owner of a small café. You have this
week's figures and, where available, the briefings you have written in prior
weeks. The tone should be that of an analyst's memo — measured, direct, and free
of encouragement.

Rules:
- Never invent numbers. Cite only the values supplied.
- Write in plain prose. No bullet points, no markdown, no headlines.
- Length: 2-3 sentences. Do not pad.
- Lead with the week-over-week change, not the raw totals.
- If a prior briefing flagged a specific item and this week's figures either
  confirm or contradict it, note this in a single clause.
- Address the owner as "you". Do not use congratulatory language.
- Do not recommend specific price, menu, or marketing changes.
- Refer to dates in the Jalali calendar only. Never Gregorian.
- Open with the observation itself. Do not preface it.
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

    public static function buildWeeklySummaryMessages(
        array $weekData,
        array $previousSummaries,
        string $dateStart,
        string $dateEnd
    ): array {
        $user = sprintf(
            "Weekly briefing.\nRange: %s to %s.\n\nThis week:\n%s",
            Jalali::formatHuman($dateStart),
            Jalali::formatHuman($dateEnd),
            json_encode($weekData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!empty($previousSummaries)) {
            $prior = [];
            foreach ($previousSummaries as $row) {
                $when = $row['generated_at'] ?? '';
                $text = $row['summary'] ?? '';
                if ($text === '') continue;
                $prior[] = '- (' . $when . ') ' . $text;
            }
            if (!empty($prior)) {
                $user .= "\n\nPrior briefings (newest first):\n" . implode("\n", $prior);
                $user .= "\n\nIf a prior briefing flagged something specific and this week's numbers confirm or contradict it, say so in one clause.";
            }
        }

        return [
            ['role' => 'system', 'content' => self::WEEKLY_SYSTEM_PROMPT],
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
            'top_items' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Top Items. Range %s to %s.\n" .
                    "Task: select up to 10 items most noteworthy from an owner's perspective. " .
                    "Not necessarily top by quantity — consider revenue, revenue per unit, and items that stand out. " .
                    "Return the same JSON schema: {\"items\":[...], \"total_qty\": N}.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
            'least_items' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Least-Selling Items. Range %s to %s.\n" .
                    "Task: select up to 10 items that warrant attention — underperformers or under-promoted items. " .
                    "Prioritize usefulness over pure lowest-qty ranking. Return the same JSON schema.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
            'item_combos' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Frequently Bought Together. Range %s to %s.\n" .
                    "Task: select up to 10 pairs suggesting the strongest promotional opportunities. " .
                    "Deprioritize pairs that are trivially obvious. Return the same JSON schema.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
            'fading_items' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Fading Items. Range %s to %s.\n" .
                    "Task: pick items fading most notably. You decide the significance threshold. " .
                    "Include up to 10 items. You may update threshold_pct. If nothing is genuinely fading, " .
                    "return an empty items array. Return the same JSON schema.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
            'rising_items' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Rising Items. Range %s to %s.\n" .
                    "Task: pick items with the strongest growth momentum. You decide the significance threshold. " .
                    "Include up to 10 items. You may update threshold_pct. If nothing is genuinely rising, " .
                    "return an empty items array. Return the same JSON schema.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
            'price_tier_shift' => function (array $data, string $ds, string $de): string {
                return sprintf(
                    "Report: Price Tier Shift. Range %s to %s.\n" .
                    "Task: re-curate the price tiers. You may rebalance (keep three tiers named Cheap, Medium, Expensive) " .
                    "and adjust cut-points. Keep the same JSON schema. Recompute nothing. Only use input numbers.\n\nSQL data:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            },
        ];

        return $cache;
    }

    // ---------------------------------------------------------
    // Narration templates (unchanged from your existing file)
    // ---------------------------------------------------------

    private static function templates(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [

            'revenue_trend' => function (array $data, string $ds, string $de): string {
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
                    "Report: Revenue Trend\nRange: %s to %s\n\nTotal revenue: %s T\nTotal orders: %d\n" .
                    "Avg order value: %s T\nDays with orders: %d\n\nDaily:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    number_format((int) ($s['total_revenue'] ?? 0)),
                    (int) ($s['total_orders'] ?? 0),
                    number_format((int) ($s['avg_order_value'] ?? 0)),
                    (int) ($s['days_with_orders'] ?? 0),
                    implode("\n", $lines)
                );
            },

            'top_items' => function (array $data, string $ds, string $de): string {
                $items = $data['items'] ?? [];
                $tq = (int) ($data['total_qty'] ?? 0);
                $lines = [];
                foreach (array_slice($items, 0, 10) as $i => $it) {
                    $lines[] = sprintf('  %d. %s - %d sold, %s T revenue', $i + 1, $it['name'] ?? '?',
                        (int) ($it['qty'] ?? 0), number_format((int) ($it['revenue'] ?? 0)));
                }
                if (empty($lines)) $lines[] = '  (none)';
                return sprintf("Report: Top Items\nRange: %s to %s\n\nTotal items sold: %d\n\nTop items:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), $tq, implode("\n", $lines));
            },

            'least_items' => function (array $data, string $ds, string $de): string {
                $items = $data['items'] ?? [];
                $ta = (int) ($data['total_available'] ?? 0);
                $lines = [];
                foreach (array_slice($items, 0, 10) as $i => $it) {
                    $lines[] = sprintf('  %d. %s - %d sold, %s T revenue', $i + 1, $it['name'] ?? '?',
                        (int) ($it['qty'] ?? 0), number_format((int) ($it['revenue'] ?? 0)));
                }
                if (empty($lines)) $lines[] = '  (none)';
                return sprintf("Report: Least-Selling Items\nRange: %s to %s\n\nAvailable items: %d\n\nBottom 10:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), $ta, implode("\n", $lines));
            },

            'hourly_heatmap' => function (array $data, string $ds, string $de): string {
                $grid = $data['grid'] ?? [];
                $max = (int) ($data['max'] ?? 0);
                $dn = $data['day_names'] ?? ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                $lines = [];
                foreach ($grid as $di => $hours) {
                    if (!is_array($hours)) continue;
                    $hc = [];
                    foreach ($hours as $hi => $c) $hc[$hi] = (int) $c;
                    arsort($hc);
                    $top = array_slice($hc, 0, 3, true);
                    $busy = [];
                    foreach ($top as $hi => $c) { if ($c > 0) $busy[] = sprintf('%02d:00 (%d)', $hi, $c); }
                    $lines[] = ($dn[$di] ?? '?') . ': ' . (empty($busy) ? 'no orders' : 'peak ' . implode(', ', $busy));
                }
                return sprintf("Report: Orders by Hour\nRange: %s to %s\n\nBusiest hour: %d orders\n\nPer day:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), $max, implode("\n", $lines));
            },

            'item_combos' => function (array $data, string $ds, string $de): string {
                $combos = $data['combos'] ?? [];
                $to = (int) ($data['total_orders'] ?? 0);
                $lines = [];
                foreach ($combos as $i => $c) {
                    $lines[] = sprintf('  %d. %s + %s - %d orders', $i + 1, $c['a_name'] ?? '?', $c['b_name'] ?? '?', (int) ($c['count'] ?? 0));
                }
                if (empty($lines)) $lines[] = '  (none)';
                return sprintf("Report: Frequently Bought Together\nRange: %s to %s\n\nOrders: %d\n\nTop pairs:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), $to, implode("\n", $lines));
            },

            'fading_items' => function (array $data, string $ds, string $de): string {
                $items = $data['items'] ?? [];
                $split = $data['period_split'] ?? $ds;
                $th = (int) ($data['threshold_pct'] ?? 40);
                $reason = $data['reason'] ?? null;
                if ($reason !== null) {
                    return sprintf("Report: Fading Items\nRange: %s to %s (split at %s)\n\nStatus: no data. Reason: %s.",
                        Jalali::formatHuman($ds), Jalali::formatHuman($de), Jalali::formatHuman($split), $reason);
                }
                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf('  %d. %s - %d before, %d after (%d%%)', $i + 1, $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0), (int) ($it['after'] ?? 0), (int) ($it['change_pct'] ?? 0));
                }
                return sprintf("Report: Fading Items\nRange: %s to %s (split at %s)\n\nThreshold: %d%% drop.\n\nFading:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), Jalali::formatHuman($split), $th, implode("\n", $lines));
            },

            'rising_items' => function (array $data, string $ds, string $de): string {
                $items = $data['items'] ?? [];
                $split = $data['period_split'] ?? $ds;
                $th = (int) ($data['threshold_pct'] ?? 40);
                $reason = $data['reason'] ?? null;
                if ($reason !== null) {
                    return sprintf("Report: Rising Items\nRange: %s to %s (split at %s)\n\nStatus: no data. Reason: %s.",
                        Jalali::formatHuman($ds), Jalali::formatHuman($de), Jalali::formatHuman($split), $reason);
                }
                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = sprintf('  %d. %s - %d before, %d after (+%d%%)', $i + 1, $it['name'] ?? '?',
                        (int) ($it['before'] ?? 0), (int) ($it['after'] ?? 0), (int) ($it['change_pct'] ?? 0));
                }
                return sprintf("Report: Rising Items\nRange: %s to %s (split at %s)\n\nThreshold: %d%% growth.\n\nRising:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), Jalali::formatHuman($split), $th, implode("\n", $lines));
            },

            'price_tier_shift' => function (array $data, string $ds, string $de): string {
                $tiers = $data['tiers'] ?? [];
                $tb = (int) ($data['total_before'] ?? 0);
                $ta = (int) ($data['total_after'] ?? 0);
                $split = $data['period_split'] ?? $ds;
                $reason = $data['reason'] ?? null;
                $lines = [];
                foreach ($tiers as $t) {
                    $ch = $t['change_pct'];
                    $chStr = ($ch === null) ? 'n/a' : sprintf('%+d%%', (int) $ch);
                    $lines[] = sprintf('  %s (%s): %d before (share %d%%), %d after (share %d%%), change %s',
                        $t['name'] ?? '?', $t['range'] ?? '?',
                        (int) ($t['qty_before'] ?? 0), (int) ($t['share_before'] ?? 0),
                        (int) ($t['qty_after'] ?? 0), (int) ($t['share_after'] ?? 0), $chStr);
                }
                $reasonStr = '';
                if ($reason === 'no_before_data') $reasonStr = "\n\nIMPORTANT: First half has zero orders.";
                elseif ($reason === 'no_after_data') $reasonStr = "\n\nIMPORTANT: Second half has zero orders.";
                elseif ($reason === 'no_orders_in_range') $reasonStr = "\n\nIMPORTANT: Range has zero orders.";
                return sprintf("Report: Price Tier Shift\nRange: %s to %s (split at %s)\n\nTotal before: %d, after: %d\n\nTiers:\n%s%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de), Jalali::formatHuman($split), $tb, $ta, implode("\n", $lines), $reasonStr);
            },

            'executive_summary' => function (array $data, string $ds, string $de): string {
                $summary = $data['summary'] ?? [];
                $days = $data['days'] ?? [];
                $top = $data['top_items'] ?? [];
                $tq = (int) ($data['total_items_sold'] ?? 0);
                $dayList = [];
                foreach ($days as $d) {
                    $dayList[] = sprintf('  %s: %d orders, %s T',
                        Jalali::formatHuman($d['date'] ?? ''), (int) ($d['orders'] ?? 0), number_format((int) ($d['revenue'] ?? 0)));
                }
                $topList = [];
                foreach (array_slice($top, 0, 3) as $it) {
                    $topList[] = sprintf('  %s (%d sold)', $it['name'] ?? '?', (int) ($it['qty'] ?? 0));
                }
                return sprintf(
                    "Executive review.\nRange: %s to %s\n\nTotal revenue: %s T\nTotal orders: %d\n" .
                    "Avg order value: %s T\nDays with orders: %d\nTotal items sold: %d\n\nDaily:\n%s\n\nTop 3:\n%s",
                    Jalali::formatHuman($ds), Jalali::formatHuman($de),
                    number_format((int) ($summary['total_revenue'] ?? 0)),
                    (int) ($summary['total_orders'] ?? 0),
                    number_format((int) ($summary['avg_order_value'] ?? 0)),
                    (int) ($summary['days_with_orders'] ?? 0),
                    $tq,
                    empty($dayList) ? '  (none)' : implode("\n", $dayList),
                    empty($topList) ? '  (none)' : implode("\n", $topList)
                );
            },
            'anomalies' => function (array $data, string $dateStart, string $dateEnd): string {
                    $items  = $data['anomalies'] ?? [];
                    $win    = (int) ($data['baseline_window'] ?? 7);
                    $th     = (int) ($data['threshold_pct']   ?? 150);
                    $reason = $data['reason'] ?? null;

                    if (empty($items)) {
                        return sprintf(
                            "Report: Anomalies\nRange: %s to %s\n\n" .
                            "Status: no anomalies. No day deviated by more than %d%% " .
                            "from its %d-day rolling baseline.",
                            Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                            $th, $win
                        );
                    }

                    $lines = [];
                    foreach (array_slice($items, 0, 10) as $i => $a) {
                        $lines[] = sprintf(
                            '  %d. %s — %s %s by %d%% (value %s, baseline %s, orders %d vs baseline %d)',
                            $i + 1,
                            Jalali::formatHuman($a['date'] ?? ''),
                            $a['metric'] ?? '?',
                            ($a['direction'] ?? '') === 'spike' ? 'spiked' : 'dropped',
                            abs((int) ($a['deviation_pct'] ?? 0)),
                            number_format((int) ($a['value'] ?? 0)),
                            number_format((int) ($a['baseline'] ?? 0)),
                            (int) ($a['orders'] ?? 0),
                            (int) ($a['baseline_orders'] ?? 0)
                        );
                    }

                    return sprintf(
                        "Report: Anomalies\nRange: %s to %s\n\n" .
                        "Baseline: %d-day rolling average. Threshold: %d%% deviation.\n\n" .
                        "Flagged days:\n%s",
                        Jalali::formatHuman($dateStart), Jalali::formatHuman($dateEnd),
                        $win, $th, implode("\n", $lines)
                    );
            },
            
        ];

        return $cache;
    }
}