<?php
// src/ai/chat/IntentHandler.php
// =============================================================
// Step 2 of Phase 8. Takes a classifier output and returns a
// structured result by calling the existing reports in
// AnalyticsEngine. No AI. No new SQL. Just dispatch.
//
// Special cases:
//   summary    — bundles revenue + top_items + anomalies
//   comparison — runs the same report on two periods + diff
//   unknown    — returns a canned "can't help" hint

require_once __DIR__ . '/../../analytics/AnalyticsEngine.php';

class IntentHandler
{
    private AnalyticsEngine $engine;

    public function __construct(PDO $pdo)
    {
        $this->engine = new AnalyticsEngine($pdo);
    }

    /**
     * @param array $classification  Output of IntentClassifier::classify()['data']
     * @return array{ok: bool, intent?: string, data?: mixed,
     *               title?: string, error?: string, message?: string}
     */
    public function handle(array $classification): array
    {
        $intent = $classification['intent'] ?? 'unknown';
        $start  = $classification['date_start'] ?? null;
        $end    = $classification['date_end']   ?? null;

        // ── Unknown: return a helpful hint instead of failing ──
        if ($intent === 'unknown') {
            return [
                'ok'      => true,
                'intent'  => 'unknown',
                'data'    => null,
                'message' => 'I can only answer questions about your café sales data. Try asking about revenue, top items, busy hours, or comparisons between two periods.',
            ];
        }

        // ── Every other intent needs a valid date range ──
        if (empty($start) || empty($end)) {
            return [
                'ok'     => false,
                'intent' => $intent,
                'error'  => 'Missing date range',
            ];
        }

        // ── Composite intents first ──
        if ($intent === 'summary') {
            return $this->handleSummary($classification);
        }
        if ($intent === 'comparison') {
            return $this->handleComparison($classification);
        }

        // ── Single-report intents ──
        $reportKey = $this->reportKeyFor($intent);
        if ($reportKey === null) {
            return [
                'ok'     => false,
                'intent' => $intent,
                'error'  => 'No handler registered for intent: ' . $intent,
            ];
        }

        $data = $this->engine->runReport($reportKey, $start, $end);
        if ($data === null) {
            return [
                'ok'     => false,
                'intent' => $intent,
                'error'  => 'Report returned no data: ' . $reportKey,
            ];
        }

        return [
            'ok'          => true,
            'intent'      => $intent,
            'report_key'  => $reportKey,
            'title'       => $this->titleFor($intent),
            'date_start'  => $start,
            'date_end'    => $end,
            'date_preset' => $classification['date_preset'] ?? null,
            'data'        => $data,
        ];
    }

    // ---------------------------------------------------------
    // Intent → report key
    // ---------------------------------------------------------

    private function reportKeyFor(string $intent): ?string
    {
        $map = [
            'revenue_trend'    => 'revenue_trend',
            'top_items'        => 'top_items',
            'least_items'      => 'least_items',
            'hourly_heatmap'   => 'hourly_heatmap',
            'item_combos'      => 'item_combos',
            'fading_items'     => 'fading_items',
            'rising_items'     => 'rising_items',
            'price_tier_shift' => 'price_tier_shift',
            'anomalies'        => 'anomalies',
        ];
        return $map[$intent] ?? null;
    }

    private function titleFor(string $intent): string
    {
        $map = [
            'revenue_trend'    => 'Revenue Trend',
            'top_items'        => 'Top Items',
            'least_items'      => 'Least-Selling Items',
            'hourly_heatmap'   => 'Orders by Hour',
            'item_combos'      => 'Frequently Bought Together',
            'fading_items'     => 'Fading Items',
            'rising_items'     => 'Rising Items',
            'price_tier_shift' => 'Price Tier Shift',
            'anomalies'        => 'Anomalies',
            'summary'          => 'Summary',
            'comparison'       => 'Comparison',
        ];
        return $map[$intent] ?? $intent;
    }

    // ---------------------------------------------------------
    // Composite: summary
    // ---------------------------------------------------------

    private function handleSummary(array $c): array
    {
        $start = $c['date_start'];
        $end   = $c['date_end'];

        $revenue   = $this->engine->runReport('revenue_trend', $start, $end);
        $top       = $this->engine->runReport('top_items',     $start, $end);
        $anomalies = $this->engine->runReport('anomalies',     $start, $end);

        return [
            'ok'          => true,
            'intent'      => 'summary',
            'title'       => 'Summary',
            'date_start'  => $start,
            'date_end'    => $end,
            'date_preset' => $c['date_preset'] ?? null,
            'data'        => [
                'revenue'   => $revenue,
                'top_items' => $top,
                'anomalies' => $anomalies,
            ],
        ];
    }

    // ---------------------------------------------------------
    // Composite: comparison
    // ---------------------------------------------------------

    private function handleComparison(array $c): array
    {
        $startA = $c['date_start'];
        $endA   = $c['date_end'];
        $startB = $c['date_start_2'] ?? null;
        $endB   = $c['date_end_2']   ?? null;

        if (empty($startB) || empty($endB)) {
            return [
                'ok'     => false,
                'intent' => 'comparison',
                'error'  => 'Comparison needs two date ranges',
            ];
        }

        // Run revenue + top_items for both periods.
        // These are the two most useful reports for comparing café performance.
        $revA = $this->engine->runReport('revenue_trend', $startA, $endA);
        $topA = $this->engine->runReport('top_items',     $startA, $endA);
        $revB = $this->engine->runReport('revenue_trend', $startB, $endB);
        $topB = $this->engine->runReport('top_items',     $startB, $endB);

        // Compute the diff. Numbers stay in the shape the narrator expects.
        $sumA = $revA['summary'] ?? [];
        $sumB = $revB['summary'] ?? [];

        $revTotalA = (int) ($sumA['total_revenue'] ?? 0);
        $revTotalB = (int) ($sumB['total_revenue'] ?? 0);
        $ordTotalA = (int) ($sumA['total_orders']  ?? 0);
        $ordTotalB = (int) ($sumB['total_orders']  ?? 0);
        $aovA      = (int) ($sumA['avg_order_value'] ?? 0);
        $aovB      = (int) ($sumB['avg_order_value'] ?? 0);

        $revPct = $revTotalB > 0
            ? round((($revTotalA - $revTotalB) / $revTotalB) * 100, 1)
            : null;
        $ordPct = $ordTotalB > 0
            ? round((($ordTotalA - $ordTotalB) / $ordTotalB) * 100, 1)
            : null;

        return [
            'ok'           => true,
            'intent'       => 'comparison',
            'title'        => 'Comparison',
            'date_start'   => $startA,
            'date_end'     => $endA,
            'date_start_2' => $startB,
            'date_end_2'   => $endB,
            'date_preset'  => $c['date_preset']   ?? null,
            'date_preset_2'=> $c['date_preset_2'] ?? null,
            'data'         => [
                'period_a' => [
                    'date_start' => $startA,
                    'date_end'   => $endA,
                    'revenue'    => $revA,
                    'top_items'  => $topA,
                ],
                'period_b' => [
                    'date_start' => $startB,
                    'date_end'   => $endB,
                    'revenue'    => $revB,
                    'top_items'  => $topB,
                ],
                'diff' => [
                    'revenue_a'          => $revTotalA,
                    'revenue_b'          => $revTotalB,
                    'revenue_change_abs' => $revTotalA - $revTotalB,
                    'revenue_change_pct' => $revPct,
                    'orders_a'           => $ordTotalA,
                    'orders_b'           => $ordTotalB,
                    'orders_change_abs'  => $ordTotalA - $ordTotalB,
                    'orders_change_pct'  => $ordPct,
                    'aov_a'              => $aovA,
                    'aov_b'              => $aovB,
                ],
            ],
        ];
    }
}