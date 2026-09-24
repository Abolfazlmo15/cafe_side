<?php
// public/admin/analytics.php – Analytics dashboard (Vue + Chart.js)
// ===================================================================
// Phase 3.1: four reports, cached. No AI yet — that's Phase 4.
//
// Script load order matters:
//   1. Vue + Chart.js (libraries first)
//   2. window.__ANALYTICS_DATA__ (bootstrap payload)
//   3. analytics-vue.js (app code, runs last)
//
// Both libraries are self-hosted under assets/vendor/ — no CDN.
// This works on laptops, servers, offline, behind any filter.

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/api.php';
require_once __DIR__ . '/../../src/analytics/AnalyticsEngine.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';

$pdo = getDbConnection();
$engine = new AnalyticsEngine($pdo);

// ================================================================
// ====== API ENDPOINTS ======
// ================================================================
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'reports') {
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) $dateStart = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd))   $dateEnd   = date('Y-m-d');

        if (!empty($_GET['refresh'])) {
            $engine->clearCache();
        }

        apiRespondJson([
            'start'   => $dateStart,
            'end'     => $dateEnd,
            'reports' => $engine->runAll($dateStart, $dateEnd),
        ]);
    }

    if ($api === 'report') {
        $key       = $_GET['key'] ?? '';
        $dateStart = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateEnd   = $_GET['end']   ?? date('Y-m-d');

        if ($key === '') {
            apiRespondJson(['error' => 'Missing report key'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) $dateStart = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd))   $dateEnd   = date('Y-m-d');

        $data = $engine->runReport($key, $dateStart, $dateEnd);
        if ($data === null) {
            apiRespondJson(['error' => 'Unknown report'], 404);
        }

        apiRespondJson([
            'key'   => $key,
            'start' => $dateStart,
            'end'   => $dateEnd,
            'data'  => $data,
        ]);
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

<!-- 1. Libraries FIRST — from local vendor, no CDN -->
<script src="../../assets/vendor/vue.global.prod.js"></script>
<script src="../../assets/vendor/chart.umd.js"></script>

<!-- 2. Bootstrap payload -->
<script>
window.__ANALYTICS_DATA__ = {
    start:   <?= json_encode($defaultStart) ?>,
    end:     <?= json_encode($defaultEnd) ?>,
    reports: <?= json_encode($initialReports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<!-- 3. App code LAST — now Vue and Chart are guaranteed to exist -->
<script src="../../assets/js/admin/analytics-vue.js"></script>

<script>
// Watchdog: if libraries failed to load, say so loudly.
// Runs after the app JS has had a chance to mount.
setTimeout(function () {
    var root = document.getElementById('vue-analytics-root');
    if (!root || root.children.length > 0) return;   // app mounted, all good

    var missing = [];
    if (typeof Vue === 'undefined')   missing.push('Vue');
    if (typeof Chart === 'undefined') missing.push('Chart.js');

    var msg = missing.length
        ? 'Library failed to load: ' + missing.join(', ') + '. Check assets/vendor/ — file may be corrupt.'
        : 'App did not mount. Check DevTools Console for a JS error.';

    root.innerHTML =
        '<div style="padding:1.2rem 1.5rem;background:#fee2e2;border-left:4px solid #dc2626;border-radius:0.75rem;color:#991b1b;font-family:system-ui,sans-serif;">' +
        '<strong><i class="fas fa-exclamation-triangle"></i> Analytics failed to load.</strong><br>' +
        msg +
        '</div>';
    console.error('[analytics] Watchdog triggered:', msg);
}, 1500);
</script>


<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();
