<?php
// public/admin/analytics.php
// ===================================================================
// Mode-aware analytics dashboard + weekly briefing + chat with data.
//
// Phase 8 · Step 4 — the UI's backend half:
//   • ?api=chat endpoint runs classifier → handler → narrator
//   • history preloaded from ai_chat_history into __ANALYTICS_DATA__
//
// Defences against the "server did not respond" JSON parse failure:
//   1. set_time_limit(180)   - gives the AI chain 3 minutes
//   2. display_errors=0      - warnings never leak into the response
//   3. shutdown handler      - fatal errors return valid JSON too
//   4. clampDateRange()      - every range bounded to real order history
//   5. analyticsReportIsEmpty() - empty reports skip the AI entirely

register_shutdown_function(function () {
    if (!isset($_GET['api'])) return;
    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes)) return;
    while (ob_get_level() > 0) { ob_end_clean(); }
    error_log('analytics.php FATAL api=' . ($_GET['api'] ?? '?') . ': ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'A server error occurred.', 'detail' => $err['message']]);
});

if (isset($_GET['api'])) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_time_limit(180);
}

require_once __DIR__ . '/../../bootstrap.php';
require_once SRC_PATH . '/auth.php';

$pdo    = getDbConnection();
$engine = new AnalyticsEngine($pdo);
$memory = new ReportMemory($pdo);

$AI_CURABLE = ['top_items', 'least_items', 'item_combos', 'fading_items', 'rising_items', 'price_tier_shift'];

// ================================================================
// Range bounds — the only dates the dashboard is allowed to query
// ================================================================
$todayIso = date('Y-m-d');
try {
    $rangeStmt = $pdo->query("SELECT MIN(DATE(created_at)) AS min_d, MAX(DATE(created_at)) AS max_d FROM orders");
    $rangeRow = $rangeStmt->fetch();
    $minOrderDate = $rangeRow['min_d'] ?? $todayIso;
    $maxOrderDate = $rangeRow['max_d'] ?? $todayIso;
} catch (Throwable $e) {
    $minOrderDate = date('Y-m-d', strtotime('-30 days'));
    $maxOrderDate = $todayIso;
}
// Never allow a query into the future, and never before the first order
$maxAllowedDate = min($maxOrderDate, $todayIso);
if ($minOrderDate > $maxAllowedDate) $minOrderDate = $maxAllowedDate;

function clampDateRange(string $start, string $end, string $minAllowed, string $maxAllowed): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $minAllowed;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $maxAllowed;
    if ($start < $minAllowed) $start = $minAllowed;
    if ($end   > $maxAllowed) $end   = $maxAllowed;
    if ($start > $end)         $start = $end;
    return [$start, $end];
}

// ================================================================
// Empty-report detection — decides whether the AI needs to run at all
// ================================================================
function analyticsReportIsEmpty(string $key, $data): bool {
    if (!is_array($data)) return true;
    switch ($key) {
        case 'revenue_trend':
            return empty($data['days']) || ((int)($data['summary']['total_orders'] ?? 0)) === 0;
        case 'top_items':
        case 'least_items':
            return empty($data['items']);
        case 'hourly_heatmap':
            return ((int)($data['max'] ?? 0)) === 0;
        case 'item_combos':
            return empty($data['combos']);
        case 'fading_items':
        case 'rising_items':
            return !empty($data['reason']) || empty($data['items']);
        case 'price_tier_shift':
            return !empty($data['reason'])
                || (((int)($data['total_before'] ?? 0)) === 0 && ((int)($data['total_after'] ?? 0)) === 0);
    }
    return empty($data);
}

/**
 * Human-readable reason when a report has nothing to analyse.
 * These messages replace an AI narration call entirely.
 */
function analyticsEmptyNarration(string $key, array $data): string {
    $reason = $data['reason'] ?? null;
    if ($reason === 'no_before_data')      return 'No activity in the first half of this range to compare against.';
    if ($reason === 'no_after_data')       return 'No activity in the second half of this range to compare against.';
    if ($reason === 'no_orders_in_range')  return 'No orders were recorded in the selected range.';
    if ($reason === 'no_significant_change')return 'No items crossed the significance threshold in this period.';
    if ($reason === 'no_menu')             return 'The menu is empty, so tier analysis is not possible.';

    switch ($key) {
        case 'revenue_trend':  return 'No orders were recorded in the selected range.';
        case 'top_items':      return 'No items were sold in the selected range.';
        case 'least_items':    return 'No items to rank in the selected range.';
        case 'hourly_heatmap': return 'No orders were recorded, so no hourly pattern exists.';
        case 'item_combos':    return 'No pairs met the minimum frequency threshold.';
    }
    return 'No data available for this report in the selected range.';
}

if (isset($_GET['api'])) {
    $api = $_GET['api'];

    try {

        // ---- GET: reports in a given mode --------------------------
        if ($api === 'reports') {
            $mode = ($_GET['mode'] ?? 'sql') === 'ai' ? 'ai' : 'sql';
            list($dateStart, $dateEnd) = clampDateRange(
                $_GET['start'] ?? $minOrderDate,
                $_GET['end']   ?? $maxAllowedDate,
                $minOrderDate, $maxAllowedDate
            );

            $sqlReports = $engine->runAll($dateStart, $dateEnd);

            if ($mode === 'sql') {
                apiRespondJson(['mode' => 'sql', 'start' => $dateStart, 'end' => $dateEnd, 'reports' => $sqlReports]);
            }

            $aiReports = [];
            foreach ($sqlReports as $key => $entry) {
                $aiData = null;
                if (in_array($key, $AI_CURABLE, true)) {
                    $aiData = $engine->getCachedAiReport($key, $dateStart, $dateEnd);
                }
                $aiReports[$key] = [
                    'title'      => $entry['title'] ?? ucwords(str_replace('_', ' ', $key)),
                    'data'       => $aiData ?: $entry['data'],
                    'ai_curated' => $aiData !== null,
                ];
            }
            apiRespondJson(['mode' => 'ai', 'start' => $dateStart, 'end' => $dateEnd, 'reports' => $aiReports]);
        }

        // ---- POST: recompute the requested mode ---------------------
        if ($api === 'recompute') {
            $mode = ($_GET['mode'] ?? 'sql') === 'ai' ? 'ai' : 'sql';
            list($dateStart, $dateEnd) = clampDateRange(
                $_GET['start'] ?? $minOrderDate,
                $_GET['end']   ?? $maxAllowedDate,
                $minOrderDate, $maxAllowedDate
            );

            if ($mode === 'sql') $engine->clearCacheRange($dateStart, $dateEnd, false);
            else              $engine->clearCacheRange($dateStart, $dateEnd, true);

            $sqlReports = $engine->runAll($dateStart, $dateEnd);

            if ($mode === 'sql') {
                apiRespondJson(['mode' => 'sql', 'start' => $dateStart, 'end' => $dateEnd, 'reports' => $sqlReports, 'recomputed' => true]);
            }

            $bridge = new AIBridge($pdo);
            $aiReports = [];
            $anyAiOk = false;
            foreach ($sqlReports as $key => $entry) {
                $sqlData = $entry['data'] ?? null;
                $aiData = null;
                if ($sqlData && in_array($key, $AI_CURABLE, true) && !analyticsReportIsEmpty($key, $sqlData)) {
                    $aiData = $bridge->processReport($key, $sqlData, $dateStart, $dateEnd, true);
                    if ($aiData !== null) $anyAiOk = true;
                }
                $aiReports[$key] = [
                    'title'      => $entry['title'] ?? ucwords(str_replace('_', ' ', $key)),
                    'data'       => $aiData ?: $sqlData,
                    'ai_curated' => $aiData !== null,
                ];
            }

            apiRespondJson(['mode' => 'ai', 'start' => $dateStart, 'end' => $dateEnd,
                'reports' => $aiReports, 'recomputed' => true, 'any_ai_ok' => $anyAiOk]);
        }

        // ---- GET: single report ------------------------------------
        if ($api === 'report') {
            $key = $_GET['key'] ?? '';
            list($dateStart, $dateEnd) = clampDateRange(
                $_GET['start'] ?? $minOrderDate,
                $_GET['end']   ?? $maxAllowedDate,
                $minOrderDate, $maxAllowedDate
            );
            if ($key === '') apiRespondJson(['error' => 'Missing report key'], 400);

            $data = $engine->runReport($key, $dateStart, $dateEnd);
            if ($data === null) apiRespondJson(['error' => 'Unknown report'], 404);
            apiRespondJson(['key' => $key, 'start' => $dateStart, 'end' => $dateEnd, 'data' => $data]);
        }

        // ---- GET: AI narration (empty reports skip the AI entirely) -
        if ($api === 'explain') {
            $key = $_GET['key'] ?? '';
            list($dateStart, $dateEnd) = clampDateRange(
                $_GET['start'] ?? $minOrderDate,
                $_GET['end']   ?? $maxAllowedDate,
                $minOrderDate, $maxAllowedDate
            );
            $force = !empty($_GET['force']);

            if ($key === '') apiRespondJson(['ok' => false, 'error' => 'Missing report key'], 400);

            $reportData = $engine->runReport($key, $dateStart, $dateEnd);
            if ($reportData === null) apiRespondJson(['ok' => false, 'error' => 'Unknown report'], 404);

            // Short-circuit empty reports so we never burn an AI call
            if (analyticsReportIsEmpty($key, $reportData)) {
                apiRespondJson([
                    'ok'       => true,
                    'text'     => analyticsEmptyNarration($key, $reportData),
                    'provider' => null,
                    'cached'   => false,
                    'skipped'  => 'empty',
                    'error'    => null,
                ]);
            }

            $bridge = new AIBridge($pdo);
            apiRespondJson($bridge->explainReport($key, $reportData, $dateStart, $dateEnd, $force));
        }

        // ---- GET: AI executive review ------------------------------
        if ($api === 'summary_review') {
            list($dateStart, $dateEnd) = clampDateRange(
                $_GET['start'] ?? $minOrderDate,
                $_GET['end']   ?? $maxAllowedDate,
                $minOrderDate, $maxAllowedDate
            );
            $force = !empty($_GET['force']);

            $revenueTrend = $engine->runReport('revenue_trend', $dateStart, $dateEnd);
            $topItems     = $engine->runReport('top_items', $dateStart, $dateEnd);

            $summary = ($revenueTrend && isset($revenueTrend['summary'])) ? $revenueTrend['summary'] : [];
            $days    = ($revenueTrend && isset($revenueTrend['days']))    ? $revenueTrend['days']    : [];
            $items   = ($topItems     && isset($topItems['items']))     ? $topItems['items']       : [];

            $totalQty = 0;
            foreach ($items as $it) $totalQty += (int) ($it['qty'] ?? 0);

            if (analyticsReportIsEmpty('revenue_trend', $revenueTrend ?? [])) {
                apiRespondJson([
                    'ok'       => true,
                    'text'     => 'No orders were recorded in the selected period, so no review can be produced.',
                    'provider' => null,
                    'cached'   => false,
                    'skipped'  => 'empty',
                    'error'    => null,
                ]);
            }

            $context = ['summary' => $summary, 'days' => $days, 'top_items' => $items, 'total_items_sold' => $totalQty];

            $bridge = new AIBridge($pdo);
            apiRespondJson($bridge->executiveReview($context, $dateStart, $dateEnd, $force));
        }

        // ---- GET: weekly briefing (regenerate) ---------------------
        if ($api === 'weekly_generate') {
            $force = !empty($_GET['force']);

            $end   = $maxAllowedDate;
            $start = date('Y-m-d', strtotime($end . ' -6 days'));
            if ($start < $minOrderDate) $start = $minOrderDate;

            $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
            $prevStart = date('Y-m-d', strtotime($prevEnd . ' -6 days'));
            if ($prevStart < $minOrderDate) $prevStart = $minOrderDate;

            $trend     = $engine->runReport('revenue_trend', $start, $end);
            $top       = $engine->runReport('top_items', $start, $end);
            $prevTrend = $engine->runReport('revenue_trend', $prevStart, $prevEnd);

            $weekData = [
                'total_revenue'    => $trend['summary']['total_revenue'] ?? 0,
                'total_orders'     => $trend['summary']['total_orders'] ?? 0,
                'avg_order_value'  => $trend['summary']['avg_order_value'] ?? 0,
                'days_with_orders' => $trend['summary']['days_with_orders'] ?? 0,
                'top_items'        => $top['items'] ?? [],
                'daily_breakdown'  => $trend['days'] ?? [],
                'prev_week'        => $prevTrend['summary'] ?? null,
            ];

            $prevSummaries = $memory->getRecent('weekly_summary', 5);

            $bridge = new AIBridge($pdo);
            $result = $bridge->generateWeeklySummary($weekData, $prevSummaries, $start, $end, $force);

            $latest = $memory->getLatest('weekly_summary');

            apiRespondJson([
                'ok'      => !empty($result['ok']),
                'text'    => $result['text'] ?? '',
                'error'   => $result['error'] ?? null,
                'skipped' => $result['skipped'] ?? null,
                'latest'  => $latest,
            ]);
        }

        // ---- POST: chat with data ----------------------------------
        // Phase 8 · Step 4 — the UI's backend half.
        // Runs classifier → handler → narrator, saves to history,
        // returns prose + metadata.
        if ($api === 'chat') {
            $question = trim($_POST['question'] ?? $_GET['q'] ?? '');

            if ($question === '') {
                apiRespondJson(['ok' => false, 'stage' => 'input', 'error' => 'Please type a question.'], 400);
            }

            if (mb_strlen($question) > 500) {
                apiRespondJson(['ok' => false, 'stage' => 'input', 'error' => 'Question is too long (max 500 characters).'], 400);
            }

            try {
                $t0 = microtime(true);

                $classifier = new IntentClassifier($pdo);
                $handler    = new IntentHandler($pdo);
                $narrator   = new ResponseNarrator($pdo);

                // Stage 1 — classify
                $tCls = microtime(true);
                $classification = $classifier->classify($question);
                $clsMs = (int) ((microtime(true) - $tCls) * 1000);

                if (!$classification['ok']) {
                    apiRespondJson([
                        'ok'    => false,
                        'stage' => 'classify',
                        'error' => $classification['error'] ?? 'Could not understand the question.',
                    ]);
                }

                // Stage 2 — handle
                $tHdl = microtime(true);
                $handlerResult = $handler->handle($classification['data']);
                $hdlMs = (int) ((microtime(true) - $tHdl) * 1000);

                if (!$handlerResult['ok']) {
                    apiRespondJson([
                        'ok'    => false,
                        'stage' => 'handle',
                        'error' => $handlerResult['error'] ?? 'Could not fetch that data.',
                    ]);
                }

                // Stage 3 — narrate
                $tNar = microtime(true);
                $narration = $narrator->narrate($question, $handlerResult);
                $narMs = (int) ((microtime(true) - $tNar) * 1000);

                if (!$narration['ok']) {
                    apiRespondJson([
                        'ok'    => false,
                        'stage' => 'narrate',
                        'error' => $narration['error'] ?? 'Could not generate an answer.',
                    ]);
                }

                $totalMs = (int) ((microtime(true) - $t0) * 1000);

                // Save to history. Best-effort — never block the response.
                try {
                    $stmt = $pdo->prepare("INSERT INTO ai_chat_history (user_id, role, content, metadata) VALUES (?, ?, ?, ?)");
                    $stmt->execute([1, 'user', $question, null]);
                    $stmt->execute([1, 'assistant', $narration['text'], json_encode([
                        'intent'     => $handlerResult['intent'],
                        'date_start' => $handlerResult['date_start'] ?? null,
                        'date_end'   => $handlerResult['date_end']   ?? null,
                        'provider'   => $narration['provider'] ?? null,
                        'model'      => $narration['model']    ?? null,
                        'elapsed_ms' => $totalMs,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                } catch (Throwable $e) {
                    error_log('chat history save failed: ' . $e->getMessage());
                }

                apiRespondJson([
                    'ok'            => true,
                    'text'          => $narration['text'],
                    'intent'        => $handlerResult['intent'],
                    'date_start'    => $handlerResult['date_start']   ?? null,
                    'date_end'      => $handlerResult['date_end']     ?? null,
                    'date_start_2'  => $handlerResult['date_start_2'] ?? null,
                    'date_end_2'    => $handlerResult['date_end_2']   ?? null,
                    'provider'      => $narration['provider'] ?? null,
                    'model'         => $narration['model']    ?? null,
                    'elapsed_ms'    => $totalMs,
                    'classifier_ms' => $clsMs,
                    'handler_ms'    => $hdlMs,
                    'narrator_ms'   => $narMs,
                ]);
            } catch (Throwable $e) {
                error_log('chat endpoint error: ' . $e->getMessage());
                apiRespondJson([
                    'ok'    => false,
                    'stage' => 'exception',
                    'error' => 'Server error. Please try again.',
                ], 500);
            }
        }

        apiRespondJson(['error' => 'Unknown API endpoint'], 404);

    } catch (Throwable $e) {
        error_log('analytics.php api=' . $api . ' error: ' . $e->getMessage());
        error_log('  at ' . $e->getFile() . ':' . $e->getLine());
        apiRespondJson([
            'ok'       => false,
            'error'    => 'Something went wrong on the server. Please try again.',
            'detail'   => $e->getMessage(),
            'endpoint' => $api,
        ], 500);
    }
}

// ================================================================
// ====== PAGE SETUP ======
// ================================================================
$defaultStart = date('Y-m-d', strtotime('-30 days'));
if ($defaultStart < $minOrderDate) $defaultStart = $minOrderDate;
if ($defaultStart > $maxAllowedDate) $defaultStart = $maxAllowedDate;
$defaultEnd = $maxAllowedDate;

$initialReports = $engine->runAll($defaultStart, $defaultEnd);

$latestWeekly = null;
try {
    $latestWeekly = $memory->getLatest('weekly_summary');
} catch (Throwable $e) {
    error_log('analytics.php could not load latest weekly: ' . $e->getMessage());
}

// ── Load recent chat history for the chat box ─────────────────
// Phase 8 · Step 4. Best-effort: if the table is missing or the
// query fails, the chat box still renders — it just starts empty.
$chatHistory = [];
try {
    $stmt = $pdo->prepare("
        SELECT role, content, metadata, created_at
        FROM ai_chat_history
        WHERE user_id = 1
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Reverse so oldest is first — the chat box renders top-to-bottom.
    $rows = array_reverse($rows);

    foreach ($rows as $row) {
        $meta = null;
        if (!empty($row['metadata'])) {
            $meta = json_decode($row['metadata'], true);
            if (!is_array($meta)) $meta = null;
        }
        $chatHistory[] = [
            'role' => $row['role'],
            'text' => $row['content'],
            'meta' => $meta,
        ];
    }
} catch (Throwable $e) {
    error_log('analytics.php chat history load failed: ' . $e->getMessage());
    $chatHistory = [];
}

$layout = new AdminLayout();
$layout->setTitle('Analytics')->setActive('analytics');

ob_start();
?>
<link rel="stylesheet" href="../../assets/css/admin/analytics.css">

<div id="vue-analytics-root"></div>

<script src="../../assets/vendor/vue.global.prod.js"></script>
<script src="../../assets/vendor/chart.umd.js"></script>

<script>
window.__ANALYTICS_DATA__ = {
    start:    <?= json_encode($defaultStart) ?>,
    end:      <?= json_encode($defaultEnd) ?>,
    minDate:  <?= json_encode($minOrderDate) ?>,
    maxDate:  <?= json_encode($maxAllowedDate) ?>,
    reports:  <?= json_encode($initialReports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    mode:     'sql',
    weeklySummary: <?= json_encode($latestWeekly, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    chatHistory:   <?= json_encode($chatHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script src="../../assets/js/admin/analytics-vue.js"></script>

<script>
setTimeout(function () {
    var root = document.getElementById('vue-analytics-root');
    if (!root || root.children.length > 0) return;
    var missing = [];
    if (typeof Vue === 'undefined')   missing.push('Vue');
    if (typeof Chart === 'undefined') missing.push('Chart.js');
    var msg = missing.length
        ? 'Library failed to load: ' + missing.join(', ') + '.'
        : 'App did not mount. Check DevTools Console.';
    root.innerHTML = '<div style="padding:1.2rem;background:#fee2e2;border-left:4px solid #dc2626;border-radius:0.75rem;color:#991b1b;font-family:system-ui;"><strong>Analytics failed to load.</strong><br>' + msg + '</div>';
}, 1500);
</script>

<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();