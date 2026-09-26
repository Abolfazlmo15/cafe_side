<?php
// src/ai/chat/ChatPromptLibrary.php
// =============================================================
// Prompt builder for the chat-with-data feature.
//
// v3 — contrasting examples teach the model the boundary between
// on-topic and off-topic. A single example caused false "unknown"
// classifications for vague-but-legitimate questions.

require_once __DIR__ . '/../../helpers/Jalali.php';

class ChatPromptLibrary
{
    public const INTENTS = [
        'revenue_trend', 'top_items', 'least_items',
        'hourly_heatmap', 'item_combos', 'fading_items',
        'rising_items', 'price_tier_shift', 'anomalies',
        'summary', 'comparison', 'unknown',
    ];

    public const PRESETS = [
        'today', 'yesterday', 'this_week', 'last_week',
        'this_month', 'last_month', 'last_7_days',
        'last_30_days', 'last_90_days', 'all_time', 'custom',
    ];

    public static function buildClassifierMessages(string $question): array
    {
        $today = date('Y-m-d');
        $dow   = date('l');

        list($jy, $jm, $jd) = Jalali::gregorianToJalali(
            (int) date('Y'), (int) date('m'), (int) date('d')
        );
        $todayJalali = sprintf('%d/%02d/%02d', $jy, $jm, $jd);

        $system = <<<'TXT'
You classify café analytics questions into ONE intent.

INTENT KEYS — pick exactly one, verbatim:
  revenue_trend     money made, sales, revenue, income, earnings, how much
  top_items         best sellers, popular items, most sold, what sells
  least_items       worst sellers, not selling, slow-moving, underperforming
  hourly_heatmap    busy hours, peak times, when am I busiest
  item_combos       items bought together, pairs, combos
  fading_items      items losing popularity, declining, dropping
  rising_items      items gaining popularity, growing, climbing
  price_tier_shift  are customers buying cheaper / more expensive
  anomalies         unusual days, spikes, drops, strange patterns
  summary           general overview, how am I doing, snapshot
  comparison        compare two periods, versus, vs, difference
  unknown           NOT about café sales at all

DATE PRESETS — pick exactly one, verbatim:
  today yesterday this_week last_week
  this_month last_month last_7_days last_30_days last_90_days
  all_time custom

Today is {$today} ({$dow}), Jalali {$todayJalali}.

OUTPUT — one JSON object only. Start with { and end with }.
No prose. No markdown. No code fences. Just the object.

{
  "intent": "<one of the 12 intent keys>",
  "date_preset": "<one of the 11 presets>",
  "custom_start": "YYYY-MM-DD or null",
  "custom_end": "YYYY-MM-DD or null",
  "date_preset_2": "<preset or null — only for comparison>",
  "custom_start_2": "YYYY-MM-DD or null",
  "custom_end_2": "YYYY-MM-DD or null",
  "confidence": 0.0,
  "reasoning": "one short sentence"
}

EXAMPLES — study these to learn the boundary:

  Q: "What sold best this month?"
  A: intent=top_items, date_preset=this_month

  Q: "What items are not selling?"
  A: intent=least_items, date_preset=last_30_days
     (Vague, but still about the menu.)

  Q: "Give me an overview"
  A: intent=summary, date_preset=last_30_days
     (Vague, but still about sales.)

  Q: "Anything weird lately?"
  A: intent=anomalies, date_preset=last_30_days

  Q: "Compare this week to last week"
  A: intent=comparison, date_preset=this_week, date_preset_2=last_week

  Q: "What is the weather today?"
  A: intent=unknown, date_preset=today
     (Not about café sales — genuinely off-topic.)

  Q: "Who won the world cup?"
  A: intent=unknown, date_preset=last_30_days
     (Not about café sales.)

RULES:
- Vague questions about the café are still on-topic. Prefer summary over unknown.
- Only use "unknown" for questions completely unrelated to café operations.
- If unclear, default to date_preset = "last_30_days".
- Use "custom" preset ONLY when the user typed explicit dates.
- For "comparison", also set date_preset_2.
- Even when intent is "unknown", return the JSON object. Never reply with prose.
TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "QUESTION: " . $question . "\n\nReply with ONLY the JSON object. Start with { and end with }."],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Narrator prompt — unchanged from Step 3
    // ─────────────────────────────────────────────────────────

    public static function buildNarratorMessages(string $question, array $result): array
    {
        $intent = $result['intent'] ?? 'unknown';

        $start   = $result['date_start'] ?? null;
        $end     = $result['date_end']   ?? null;
        $startJ  = $start ? Jalali::formatHuman($start) : '';
        $endJ    = $end   ? Jalali::formatHuman($end)   : '';
        $rangeStr = $startJ && $endJ ? "{$startJ} to {$endJ}" : '(unknown range)';

        $secondRange = '';
        if (!empty($result['date_start_2']) && !empty($result['date_end_2'])) {
            $s2 = Jalali::formatHuman($result['date_start_2']);
            $e2 = Jalali::formatHuman($result['date_end_2']);
            $secondRange = "\nComparison range: {$s2} to {$e2}";
        }

        $dataBlock = self::formatDataBlock($result);

        $system = <<<'TXT'
You write short answers to a café owner's questions about their sales data.

RULES:
- Write 2-3 sentences. Never longer. Never shorter.
- Every number you cite MUST come from the DATA block. Never invent or estimate.
- Plain prose only. No bullet points. No markdown. No code fences.
- Address the owner as "you". Professional, direct register.
- Refer to dates in Jalali only (e.g. "3 Mehr 1405"). Never Gregorian.
- If the data is empty or unremarkable, say so directly.
- Do NOT recommend actions. Do NOT speculate about causes unless they are
  literally visible in the data.
- Do NOT start with "Based on the data" or similar preambles. Just answer.
TXT;

        $user = "QUESTION: {$question}\n\n"
              . "INTENT: {$intent}\n"
              . "Range: {$rangeStr}{$secondRange}\n\n"
              . "DATA:\n{$dataBlock}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    private static function formatDataBlock(array $result): string
    {
        $data   = $result['data'] ?? [];
        $intent = $result['intent'] ?? '';

        switch ($intent) {

            case 'top_items':
            case 'least_items':
                $lines = [];
                foreach (array_slice($data['items'] ?? [], 0, 8) as $i => $it) {
                    $lines[] = sprintf('  %d. %s — %d sold, %s T revenue',
                        $i + 1, $it['name'], $it['qty'], number_format($it['revenue']));
                }
                return sprintf('Total items sold: %d', $data['total_qty'] ?? 0)
                     . "\nRanked items:\n"
                     . (empty($lines) ? '  (none)' : implode("\n", $lines));

            case 'revenue_trend':
                $s = $data['summary'] ?? [];
                return sprintf(
                    "Total revenue: %s T\nTotal orders: %d\nAvg order value: %s T\nDays with orders: %d",
                    number_format((int) ($s['total_revenue']   ?? 0)),
                    (int) ($s['total_orders']    ?? 0),
                    number_format((int) ($s['avg_order_value'] ?? 0)),
                    (int) ($s['days_with_orders'] ?? 0)
                );

            case 'hourly_heatmap':
                $grid = $data['grid'] ?? [];
                $dn   = $data['day_names'] ?? [];
                $max  = (int) ($data['max'] ?? 0);
                $lines = [];
                foreach ($grid as $di => $hours) {
                    if (!is_array($hours)) continue;
                    $hc = [];
                    foreach ($hours as $hi => $c) $hc[$hi] = (int) $c;
                    arsort($hc);
                    $top = array_slice($hc, 0, 2, true);
                    $busy = [];
                    foreach ($top as $hi => $c) {
                        if ($c > 0) $busy[] = sprintf('%02d:00 (%d orders)', $hi, $c);
                    }
                    $lines[] = ($dn[$di] ?? '?') . ': ' . (empty($busy) ? 'no orders' : implode(', ', $busy));
                }
                return sprintf("Peak orders in any hour: %d\nBusiest windows by day:\n%s",
                    $max, implode("\n", $lines));

            case 'item_combos':
                $lines = [];
                foreach ($data['combos'] ?? [] as $i => $c) {
                    $lines[] = sprintf('  %d. %s + %s — %d orders',
                        $i + 1, $c['a_name'], $c['b_name'], $c['count']);
                }
                return sprintf("Total orders analyzed: %d\nPairs:\n%s",
                    $data['total_orders'] ?? 0,
                    empty($lines) ? '  (none)' : implode("\n", $lines));

            case 'anomalies':
                $lines = [];
                foreach ($data['anomalies'] ?? [] as $i => $a) {
                    $lines[] = sprintf('  %d. %s — %s %s by %d%% (value %s, baseline %s)',
                        $i + 1, Jalali::formatHuman($a['date']),
                        $a['metric'],
                        $a['direction'] === 'spike' ? 'spiked' : 'dropped',
                        abs((int) $a['deviation_pct']),
                        number_format((int) $a['value']),
                        number_format((int) $a['baseline']));
                }
                if (empty($lines)) {
                    return sprintf("No anomalies detected.\nDays analyzed: %d\nThreshold: %d%% deviation from a %d-day baseline.",
                        $data['days_analyzed'] ?? 0,
                        $data['threshold_pct'] ?? 150,
                        $data['baseline_window'] ?? 7);
                }
                return sprintf("Flagged days (%d):\n%s", count($lines), implode("\n", $lines));

            case 'fading_items':
            case 'rising_items':
                $lines = [];
                foreach ($data['items'] ?? [] as $i => $it) {
                    $lines[] = sprintf('  %d. %s — %d before, %d after (%d%%)',
                        $i + 1, $it['name'],
                        $it['before'], $it['after'], $it['change_pct']);
                }
                if (empty($lines)) {
                    return "No items qualified. Reason: " . ($data['reason'] ?? 'no significant change');
                }
                return "Items:\n" . implode("\n", $lines);

            case 'price_tier_shift':
                $lines = [];
                foreach ($data['tiers'] ?? [] as $t) {
                    $lines[] = sprintf('  %s (%s): share %d%% → %d%% (change %d pts)',
                        $t['name'], $t['range'],
                        $t['share_before'], $t['share_after'], $t['share_delta']);
                }
                if (empty($lines)) {
                    return "No tiers to compare. Reason: " . ($data['reason'] ?? 'unknown');
                }
                return sprintf("Units before: %d, after: %d\nTiers:\n%s",
                    $data['total_before'] ?? 0,
                    $data['total_after']  ?? 0,
                    implode("\n", $lines));

            case 'summary':
                $rev = $data['revenue']['summary'] ?? [];
                $top = $data['top_items']['items'] ?? [];
                $ano = $data['anomalies']['anomalies'] ?? [];
                $topLines = [];
                foreach (array_slice($top, 0, 3) as $it) {
                    $topLines[] = sprintf('  %s (%d sold)', $it['name'], $it['qty']);
                }
                return sprintf(
                    "Revenue: %s T\nOrders: %d\nAvg order value: %s T\nTop 3 items:\n%s\nAnomalies flagged: %d",
                    number_format((int) ($rev['total_revenue'] ?? 0)),
                    (int) ($rev['total_orders'] ?? 0),
                    number_format((int) ($rev['avg_order_value'] ?? 0)),
                    empty($topLines) ? '  (none)' : implode("\n", $topLines),
                    count($ano)
                );

            case 'comparison':
                $d = $data['diff']      ?? [];
                $a = $data['period_a'] ?? [];
                $b = $data['period_b'] ?? [];
                return sprintf(
                    "Period A (%s to %s): revenue %s T, orders %d\n" .
                    "Period B (%s to %s): revenue %s T, orders %d\n" .
                    "Change: revenue %s T (%s%%), orders %s (%s%%)",
                    $a['date_start'] ?? '', $a['date_end'] ?? '',
                    number_format((int) ($d['revenue_a'] ?? 0)),
                    (int) ($d['orders_a'] ?? 0),
                    $b['date_start'] ?? '', $b['date_end'] ?? '',
                    number_format((int) ($d['revenue_b'] ?? 0)),
                    (int) ($d['orders_b'] ?? 0),
                    number_format((int) ($d['revenue_change_abs'] ?? 0)),
                    $d['revenue_change_pct'] !== null ? sprintf('%+.1f', $d['revenue_change_pct']) : 'n/a',
                    number_format((int) ($d['orders_change_abs'] ?? 0)),
                    $d['orders_change_pct'] !== null ? sprintf('%+.1f', $d['orders_change_pct']) : 'n/a'
                );
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}