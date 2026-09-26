<?php
// src/ai/chat/IntentClassifier.php
// =============================================================
// v2 — hardening:
//   - Synonym map normalizes the LLM's creative intent names.
//   - Date presets are computed in PHP; the LLM never emits dates.
//   - max_tokens bumped to 1000 to survive thinking models.
//   - Tolerant JSON extraction survives prose around the object.

require_once __DIR__ . '/../providers/ProviderRegistry.php';
require_once __DIR__ . '/../managers/AIRateLimiter.php';
require_once __DIR__ . '/ChatPromptLibrary.php';

class IntentClassifier
{
    /**
     * Map of LLM-invented intent names to canonical intents.
     * Small models love to invent names. We redirect them.
     */
    private const INTENT_SYNONYMS = [
        // → top_items
        'best_seller'       => 'top_items',
        'best_sellers'      => 'top_items',
        'bestseller'        => 'top_items',
        'bestsellers'       => 'top_items',
        'top_seller'        => 'top_items',
        'top_sellers'       => 'top_items',
        'popular_items'     => 'top_items',
        'best_selling_items' => 'top_items',
        'top_products'      => 'top_items',

        // → least_items
        'worst_seller'      => 'least_items',
        'worst_sellers'     => 'least_items',
        'least_seller'      => 'least_items',
        'least_sellers'     => 'least_items',
        'slow_moving'       => 'least_items',
        'slow_items'        => 'least_items',
        'underperforming'   => 'least_items',

        // → hourly_heatmap
        'peak_hours'        => 'hourly_heatmap',
        'busy_hours'        => 'hourly_heatmap',
        'peak_times'        => 'hourly_heatmap',
        'busy_times'        => 'hourly_heatmap',
        'hourly'            => 'hourly_heatmap',
        'heatmap'           => 'hourly_heatmap',
        'hourly_breakdown'  => 'hourly_heatmap',

        // → item_combos
        'association'       => 'item_combos',
        'associations'      => 'item_combos',
        'co_occurrence'     => 'item_combos',
        'cooccurrence'      => 'item_combos',
        'pairs'             => 'item_combos',
        'bought_together'   => 'item_combos',
        'combo_items'       => 'item_combos',

        // → revenue_trend
        'revenue'           => 'revenue_trend',
        'sales'             => 'revenue_trend',
        'sales_total'       => 'revenue_trend',
        'total_sales'       => 'revenue_trend',
        'income'            => 'revenue_trend',
        'earnings'          => 'revenue_trend',
        'total_revenue'     => 'revenue_trend',
        'money_made'        => 'revenue_trend',

        // → summary
        'overview'          => 'summary',
        'general'           => 'summary',
        'general_summary'   => 'summary',
        'snapshot'          => 'summary',
        'how_am_i_doing'    => 'summary',

        // → anomalies
        'anomaly'           => 'anomalies',
        'anomaly_detection' => 'anomalies',
        'unusual'           => 'anomalies',
        'unusual_days'      => 'anomalies',
        'weird'             => 'anomalies',
        'spikes'            => 'anomalies',
        'drops'             => 'anomalies',

        // → comparison
        'compare'           => 'comparison',
        'vs'                => 'comparison',
        'versus'            => 'comparison',
        'difference'        => 'comparison',

        // → fading_items
        'fading'            => 'fading_items',
        'declining_items'   => 'fading_items',
        'declining'         => 'fading_items',

        // → rising_items
        'rising'            => 'rising_items',
        'growing_items'     => 'rising_items',
        'growing'           => 'rising_items',

        // → price_tier_shift
        'price_shift'       => 'price_tier_shift',
        'tier_shift'        => 'price_tier_shift',
        'spending_shift'    => 'price_tier_shift',

        // → unknown
        'none'              => 'unknown',
        'n/a'               => 'unknown',
        'not_applicable'    => 'unknown',
        'other'             => 'unknown',
    ];

    private ProviderRegistry $registry;
    private AIRateLimiter $limiter;

    public function __construct(PDO $pdo)
    {
        $this->registry = new ProviderRegistry($pdo);
        $this->limiter  = new AIRateLimiter($pdo);
    }

    public function classify(string $question): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'error' => 'Empty question'];
        }

        if (!$this->limiter->allow()) {
            return ['ok' => false, 'error' => 'Hourly AI rate limit reached. Try again in a few minutes.'];
        }

        $messages = ChatPromptLibrary::buildClassifierMessages($question);

        $result = $this->registry->chat($messages, [
            'max_tokens'  => 1000,
            'temperature' => 0.1,
        ]);

        $this->limiter->log('chat_classify', $result);

        if (empty($result['ok'])) {
            return [
                'ok'    => false,
                'error' => $result['error'] ?? 'All AI providers failed',
            ];
        }

        $text = trim((string) $result['text']);

        // Strip code fences if any
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        // Extract the first balanced {...} block, ignoring prose around it.
        $json = self::extractFirstJsonObject($text);

        if ($json === null) {
            // Model ignored the JSON instruction. Treat as unknown rather
            // than failing — the user still gets a graceful response.
            return [
                'ok'       => true,
                'data'     => [
                    'intent'        => 'unknown',
                    'intent_raw'    => 'unknown',
                    'date_preset'   => 'last_30_days',
                    'date_start'    => date('Y-m-d', strtotime('-29 days')),
                    'date_end'      => date('Y-m-d'),
                    'date_start_2'  => null,
                    'date_end_2'    => null,
                    'confidence'    => 0.3,
                    'reasoning'     => 'Model returned non-JSON; defaulted to unknown.',
                ],
                'provider' => $result['provider'] ?? null,
                'model'    => $result['model']    ?? null,
            ];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['intent'])) {
            return [
                'ok'    => false,
                'error' => 'Classifier JSON missing intent field',
                'raw'   => substr($json, 0, 300),
            ];
        }

        // Normalize the intent via the synonym map
        $rawIntent   = strtolower(trim((string) $data['intent']));
        $canonical   = self::INTENT_SYNONYMS[$rawIntent] ?? $rawIntent;

        if (!in_array($canonical, ChatPromptLibrary::INTENTS, true)) {
            return [
                'ok'    => false,
                'error' => 'Unknown intent: ' . $data['intent'],
                'raw'   => substr($json, 0, 300),
            ];
        }
        $data['intent_raw'] = $data['intent'];
        $data['intent']     = $canonical;

        // Normalize the date preset
        $preset = strtolower(trim((string) ($data['date_preset'] ?? 'last_30_days')));
        if (!in_array($preset, ChatPromptLibrary::PRESETS, true)) {
            $preset = 'last_30_days';
        }
        $data['date_preset'] = $preset;

        // Compute the actual ISO dates from the preset
        [$start, $end] = self::computeDateRange(
            $preset,
            $data['custom_start'] ?? null,
            $data['custom_end']   ?? null
        );
        $data['date_start'] = $start;
        $data['date_end']   = $end;

        // Compute the second range for comparison intent
        $preset2 = $data['date_preset_2'] ?? null;
        if ($canonical === 'comparison' && $preset2 !== null) {
            if (!in_array($preset2, ChatPromptLibrary::PRESETS, true)) {
                $preset2 = 'last_week';
            }
            [$start2, $end2] = self::computeDateRange(
                $preset2,
                $data['custom_start_2'] ?? null,
                $data['custom_end_2']   ?? null
            );
            $data['date_preset_2']  = $preset2;
            $data['date_start_2']   = $start2;
            $data['date_end_2']     = $end2;
        } else {
            $data['date_preset_2']  = null;
            $data['date_start_2']   = null;
            $data['date_end_2']     = null;
        }

        $data['confidence'] = (float) ($data['confidence'] ?? 0.5);
        $data['reasoning']  = (string) ($data['reasoning'] ?? '');

        return [
            'ok'       => true,
            'data'     => $data,
            'provider' => $result['provider'] ?? null,
            'model'    => $result['model']    ?? null,
        ];
    }

    /**
     * Find the first balanced {...} block in a string. Handles
     * nested braces, quotes with escapes, and prose around the JSON.
     */
    private static function extractFirstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) return null;

        $depth    = 0;
        $inString = false;
        $escape   = false;
        $len      = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];

            if ($inString) {
                if ($escape)      { $escape = false; continue; }
                if ($c === '\\')  { $escape = true;  continue; }
                if ($c === '"')   { $inString = false; }
                continue;
            }

            if ($c === '"') { $inString = true; continue; }
            if ($c === '{') { $depth++; continue; }
            if ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Map a preset keyword to real ISO dates, computed from today.
     * Returns [start, end]. `all_time` returns [null, today].
     */
    private static function computeDateRange(
        string $preset,
        ?string $customStart,
        ?string $customEnd
    ): array {
        $today = new DateTime('today');

        $iso = function (DateTime $d): string { return $d->format('Y-m-d'); };

        switch ($preset) {
            case 'today':
                return [$iso($today), $iso($today)];

            case 'yesterday':
                $y = (clone $today)->modify('-1 day');
                return [$iso($y), $iso($y)];

            case 'this_week':
            case 'last_7_days':
                $s = (clone $today)->modify('-6 days');
                return [$iso($s), $iso($today)];

            case 'last_week':
                $s = (clone $today)->modify('-13 days');
                $e = (clone $today)->modify('-7 days');
                return [$iso($s), $iso($e)];

            case 'this_month':
                $s = (clone $today)->modify('first day of this month');
                return [$iso($s), $iso($today)];

            case 'last_month':
                $s = (clone $today)->modify('first day of last month');
                $e = (clone $today)->modify('last day of last month');
                return [$iso($s), $iso($e)];

            case 'last_90_days':
                $s = (clone $today)->modify('-89 days');
                return [$iso($s), $iso($today)];

            case 'all_time':
                return [null, $iso($today)];

            case 'custom':
                if ($customStart && $customEnd
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
                    return [$customStart, $customEnd];
                }
                // fall through to default
            case 'last_30_days':
            default:
                $s = (clone $today)->modify('-29 days');
                return [$iso($s), $iso($today)];
        }
    }
}