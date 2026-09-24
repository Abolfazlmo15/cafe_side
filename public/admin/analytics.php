<?php
// public/admin/analytics.php
// ===================================================================
// Mode-aware analytics dashboard.
//
// Endpoints:
//   ?api=reports&mode=sql|ai           Reports in the requested mode
//   ?api=recompute&mode=sql|ai         Recompute the requested mode
//   ?api=report&key=...                Single SQL report
//   ?api=explain&key=...               AI narration of one report
//   ?api=summary_review                Executive AI review

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/api.php';
require_once __DIR__ . '/../../src/analytics/AnalyticsEngine.php';
require_once __DIR__ . '/../../src/ai/bridge/AIBridge.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';

$pdo    = getDbConnection();
$engine = new AnalyticsEngine($pdo);

// Reports that support AI curation (their SQL data can be re-processed).
$AI_CURABLE = ['top_items', 'least_items', 'item_combos', 'fading_items', 'rising_items', 'price_tier_shift'];

if (isset($_GET['api'])) {
    $api = $_GET['api'];

    // ---- GET: reports in a given mode --------------------------
    if ($api === 'reports') {
        $mode      = ($_GET['mode'] ?? 'sql') === 'ai' ? 'ai' : 'sql';
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) $dateStart = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd))   $dateEnd   = date('Y-m-d');

        $sqlReports = $engine->runAll($dateStart, $dateEnd);

        if ($mode === 'sql') {
            apiRespondJson(['mode' => 'sql', 'start' => $dateStart, 'end' => $dateEnd, 'reports' => $sqlReports]);
        }

        // AI mode: fetch AI-curated data where available; fall back to SQL.
        $aiReports = [];
        foreach ($sqlReports as $key => $entry) {
            $aiData = null;
            if (in_array($key, $AI_CURABLE, true)) {
                $aiData = $engine->getCachedAiReport($key, $dateStart, $dateEnd);
            }
            $aiReports[$key] = [
                'title'       => $entry['title'] ?? ucwords(str_replace('_', ' ', $key)),
                'data'        => $aiData ?: $entry['data'],
                'ai_curated'  => $aiData !== null,
            ];
        }
        apiRespondJson(['mode' => 'ai', 'start' => $dateStart, 'end' => $dateEnd, 'reports' => $aiReports]);
    }

    // ---- POST: recompute the requested mode ---------------------
    if ($api === 'recompute') {
        $mode      = ($_GET['mode'] ?? 'sql') === 'ai' ? 'ai' : 'sql';
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) $dateStart = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd))   $dateEnd   = date('Y-m-d');

        // Clear cache for the requested mode.
        if ($mode === 'sql') {
            $engine->clearCacheRange($dateStart, $dateEnd, false);
        } else {
            $engine->clearCacheRange($dateStart, $dateEnd, true);
        }

        // Always have SQL data ready - AI mode feeds off it.
        $sqlReports = $engine->runAll($dateStart, $dateEnd);

        if ($mode === 'sql') {
            apiRespondJson(['mode' => 'sql', 'start' => $dateStart, 'end' => $dateEnd,
                            'reports' => $sqlReports, 'recomputed' => true]);
        }

        // AI mode: process each curable report, fall back to SQL for the rest.
        $bridge    = new AIBridge($pdo);
        $aiReports = [];
        $anyAiOk   = false;
        foreach ($sqlReports as $key => $entry) {
            $sqlData = $entry['data'] ?? null;
            $aiData  = null;
            if ($sqlData && in_array($key, $AI_CURABLE, true)) {
                $aiData = $bridge->processReport($key, $sqlData, $dateStart, $dateEnd, true);
                if ($aiData !== null) $anyAiOk = true;
            }
            $aiReports[$key] = [
                'title'      => $entry['title'] ?? ucwords(str_replace('_', ' ', $key)),
                'data'       => $aiData ?: $sqlData,
                'ai_curated' => $aiData !== null,
            ];
        }

        apiRespondJson([
            'mode'       => 'ai',
            'start'      => $dateStart,
            'end'        => $dateEnd,
            'reports'    => $aiReports,
            'recomputed' => true,
            'any_ai_ok'  => $anyAiOk,
        ]);
    }

    // ---- GET: single report ------------------------------------
    if ($api === 'report') {
        $key       = $_GET['key'] ?? '';
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');
        if ($key === '') apiRespondJson(['error' => 'Missing report key'], 400);

        $data = $engine->runReport($key, $dateStart, $dateEnd);
        if ($data === null) apiRespondJson(['error' => 'Unknown report'], 404);
        apiRespondJson(['key' => $key, 'start' => $dateStart, 'end' => $dateEnd, 'data' => $data]);
    }

    // ---- GET: AI narration -------------------------------------
    if ($api === 'explain') {
        $key       = $_GET['key'] ?? '';
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');
        $force     = !empty($_GET['force']);

        if ($key === '') apiRespondJson(['ok' => false, 'error' => 'Missing report key'], 400);

        $reportData = $engine->runReport($key, $dateStart, $dateEnd);
        if ($reportData === null) apiRespondJson(['ok' => false, 'error' => 'Unknown report'], 404);

        $bridge = new AIBridge($pdo);
        apiRespondJson($bridge->explainReport($key, $reportData, $dateStart, $dateEnd, $force));
    }

    // ---- GET: AI executive review ------------------------------
    if ($api === 'summary_review') {
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');
        $force     = !empty($_GET['force']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            apiRespondJson(['ok' => false, 'error' => 'Invalid date range'], 400);
        }

        $revenueTrend = $engine->runReport('revenue_trend', $dateStart, $dateEnd);
        $topItems     = $engine->runReport('top_items', $dateStart, $dateEnd);

        $summary = ($revenueTrend && isset($revenueTrend['summary'])) ? $revenueTrend['summary'] : [];
        $days    = ($revenueTrend && isset($revenueTrend['days']))    ? $revenueTrend['days']    : [];
        $items   = ($topItems     && isset($topItems['items']))        ? $topItems['items']       : [];

        $totalQty = 0;
        foreach ($items as $it) $totalQty += (int) ($it['qty'] ?? 0);

        $context = ['summary' => $summary, 'days' => $days, 'top_items' => $items, 'total_items_sold' => $totalQty];

        $bridge = new AIBridge($pdo);
        apiRespondJson($bridge->executiveReview($context, $dateStart, $dateEnd, $force));
    }

    apiRespondJson(['error' => 'Unknown API endpoint'], 404);
}

// ================================================================
// ====== PAGE SETUP ======
// ================================================================
$defaultStart = date('Y-m-d', strtotime('-30 days'));
$defaultEnd   = date('Y-m-d');
$initialReports = $engine->runAll($defaultStart, $defaultEnd);

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
    start:   <?= json_encode($defaultStart) ?>,
    end:     <?= json_encode($defaultEnd) ?>,
    reports: <?= json_encode($initialReports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    mode:    'sql'
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
