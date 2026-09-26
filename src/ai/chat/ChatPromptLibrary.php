<?php
// src/ai/chat/ChatPromptLibrary.php
// =============================================================
// Prompt builder for the chat-with-data feature.
//
// v2 — the LLM no longer produces dates. It picks a preset
// keyword; IntentClassifier maps the preset to real ISO dates.
// This kills the "model hallucinates 2023 dates" failure mode.

require_once __DIR__ . '/../../helpers/Jalali.php';

class ChatPromptLibrary
{
    /** The twelve intents the classifier can pick from. */
    public const INTENTS = [
        'revenue_trend',
        'top_items',
        'least_items',
        'hourly_heatmap',
        'item_combos',
        'fading_items',
        'rising_items',
        'price_tier_shift',
        'anomalies',
        'summary',
        'comparison',
        'unknown',
    ];

    /** The eleven date presets the classifier can pick from. */
    public const PRESETS = [
        'today',
        'yesterday',
        'this_week',
        'last_week',
        'this_month',
        'last_month',
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'all_time',
        'custom',
    ];

    public static function buildClassifierMessages(string $question): array
    {
        $today   = date('Y-m-d');
        $dow     = date('l');

        list($jy, $jm, $jd) = Jalali::gregorianToJalali(
            (int) date('Y'), (int) date('m'), (int) date('d')
        );
        $todayJalali = sprintf('%d/%02d/%02d', $jy, $jm, $jd);

        $system = <<<'TXT'
You classify café analytics questions into ONE intent.

INTENT KEYS — use exactly one of these, verbatim:
  revenue_trend     money made, sales, revenue, income, earnings
  top_items         best sellers, popular items
  least_items       worst sellers, slow-moving items
  hourly_heatmap    busy hours, peak times
  item_combos       items bought together
  fading_items      items losing popularity
  rising_items      items gaining popularity
  price_tier_shift  are customers buying cheaper / more expensive
  anomalies         unusual days, spikes, drops
  summary           general overview of a period
  comparison        compare two periods
  unknown           NOT about café sales at all

DATE PRESETS — use exactly one of these, verbatim:
  today yesterday this_week last_week
  this_month last_month last_7_days last_30_days last_90_days
  all_time custom

Today is {$today} ({$dow}), Jalali {$todayJalali}.

OUTPUT — one JSON object. No prose. No markdown. No code fences.
Start your response with { and end it with }.

{
  "intent": "",
  "date_preset": "",
  "custom_start": "YYYY-MM-DD or null",
  "custom_end": "YYYY-MM-DD or null",
  "date_preset_2": "",
  "custom_start_2": "YYYY-MM-DD or null",
  "custom_end_2": "YYYY-MM-DD or null",
  "confidence": 0.0,
  "reasoning": "one short sentence"
}

RULES:
- If unclear, default to date_preset = "last_30_days".
- Use "custom" preset ONLY when the user typed explicit dates.
- For "comparison", also set date_preset_2.
- If the question is not about café sales: intent = "unknown".
TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "QUESTION: " . $question . "\n\nReply with ONLY the JSON object. Start with { and end with }."],
        ];
    }
}