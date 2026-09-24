<?php
// public/admin/analytics-diag.php
// =============================================================
// Temporary self-test for the analytics page.
// Delete after the page works.

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/analytics/AnalyticsEngine.php';

$pdo = getDbConnection();
$engine = new AnalyticsEngine($pdo);

// Try to run the engine with a 30-day window
$engineOk = true;
$engineErr = '';
$reportKeys = [];
$sampleReport = null;
try {
    $reports = $engine->runAll(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
    $reportKeys = array_keys($reports);
    $sampleReport = $reports['revenue_trend'] ?? null;
} catch (Throwable $e) {
    $engineOk = false;
    $engineErr = $e->getMessage();
}

// JSON encode a small test payload — same path analytics.php uses
$testPayload = json_encode([
    'start' => date('Y-m-d', strtotime('-30 days')),
    'end' => date('Y-m-d'),
    'reports' => $reports ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsonBytes = strlen($testPayload);
$jsonValid = (json_decode($testPayload, true) !== null);
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Analytics Self-Test</title>
<style>
body { font-family: system-ui, sans-serif; background:#f8f5f2; padding:2rem; color:#2d1b0e; line-height:1.6; }
h1 { font-size:1.5rem; margin-bottom:1rem; }
table { width:100%; max-width:800px; border-collapse:collapse; background:#fff; border-radius:0.75rem; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
th, td { padding:0.7rem 1rem; text-align:left; border-bottom:1px solid #f0e4db; font-size:0.92rem; }
th { background:#f3e8e0; font-weight:700; }
.ok   { color:#22c55e; font-weight:700; }
.bad  { color:#dc2626; font-weight:700; }
.warn { color:#f59e0b; font-weight:700; }
pre { background:#0f172a; color:#e2e8f0; padding:1rem; border-radius:0.5rem; font-size:0.82rem; overflow-x:auto; margin-top:1rem; }
</style>
</head>
<body>

<h1>🔬 Analytics Self-Test</h1>

<table>
  <tr><th style="width:40%">Check</th><th>Result</th></tr>

  <tr>
    <td>PHP version</td>
    <td><?= PHP_VERSION ?></td>
  </tr>

  <tr>
    <td>AnalyticsEngine instantiated</td>
    <td><?= $engineOk ? '<span class="ok">✓ OK</span>' : '<span class="bad">✗ ' . htmlspecialchars($engineErr) . '</span>' ?></td>
  </tr>

  <tr>
    <td>Reports returned</td>
    <td><?= count($reportKeys) ?> (<?= implode(', ', $reportKeys) ?>)</td>
  </tr>

  <tr>
    <td>JSON payload size</td>
    <td><?= number_format($jsonBytes) ?> bytes</td>
  </tr>

  <tr>
    <td>JSON round-trip valid</td>
    <td><?= $jsonValid ? '<span class="ok">✓ OK</span>' : '<span class="bad">✗ Broken</span>' ?></td>
  </tr>

  <tr>
    <td>analytics-vue.js file exists</td>
    <td><?= file_exists(__DIR__ . '/../../assets/js/admin/analytics-vue.js') ? '<span class="ok">✓ Found</span>' : '<span class="bad">✗ MISSING</span>' ?></td>
  </tr>

  <tr>
    <td>analytics.css file exists</td>
    <td><?= file_exists(__DIR__ . '/../../assets/css/admin/analytics.css') ? '<span class="ok">✓ Found</span>' : '<span class="bad">✗ MISSING</span>' ?></td>
  </tr>

  <tr>
    <td>vendor/vue.global.prod.js exists</td>
    <td><?= file_exists(__DIR__ . '/../../assets/vendor/vue.global.prod.js') ? '<span class="ok">✓ Found (self-hosted)</span>' : '<span class="warn">— Not downloaded</span>' ?></td>
  </tr>

  <tr>
    <td>vendor/chart.umd.js exists</td>
    <td><?= file_exists(__DIR__ . '/../../assets/vendor/chart.umd.js') ? '<span class="ok">✓ Found (self-hosted)</span>' : '<span class="warn">— Not downloaded</span>' ?></td>
  </tr>

  <tr>
    <td>Vue reachable (unpkg)</td>
    <td id="vue-check">testing…</td>
  </tr>

  <tr>
    <td>Chart.js reachable (unpkg)</td>
    <td id="chart-check">testing…</td>
  </tr>

  <tr>
    <td>Chart.js reachable (jsdelivr)</td>
    <td id="jsdelivr-check">testing…</td>
  </tr>

  <tr>
    <td>Vue + Chart mountable</td>
    <td id="mount-check">testing…</td>
  </tr>
</table>

<h2 style="margin-top:2rem; font-size:1.1rem;">First 500 bytes of the report JSON</h2>
<pre><?= htmlspecialchars(substr($testPayload, 0, 500)) ?></pre>

<div id="mount-result" style="margin-top:1rem;"></div>

<!-- Live network tests -->
<script>
(function () {
    function loadScript(src, cb) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = function () { cb(true); };
        s.onerror = function () { cb(false); };
        document.head.appendChild(s);
    }

    loadScript('https://unpkg.com/vue@3/dist/vue.global.prod.js', function (ok) {
        document.getElementById('vue-check').innerHTML = ok
            ? '<span class="ok">✓ Reachable</span>'
            : '<span class="bad">✗ Blocked / 404</span>';
    });

    loadScript('https://unpkg.com/chart.js@4.4.0/dist/chart.umd.js', function (ok) {
        document.getElementById('chart-check').innerHTML = ok
            ? '<span class="ok">✓ Reachable</span>'
            : '<span class="bad">✗ Blocked / 404</span>';
    });

    loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', function (ok) {
        document.getElementById('jsdelivr-check').innerHTML = ok
            ? '<span class="ok">✓ Reachable</span>'
            : '<span class="bad">✗ Blocked / 404 — this is the bug</span>';
    });

    setTimeout(function () {
        var hasVue = typeof Vue !== 'undefined';
        var hasChart = typeof Chart !== 'undefined';
        var el = document.getElementById('mount-check');
        var res = document.getElementById('mount-result');

        if (hasVue && hasChart) {
            el.innerHTML = '<span class="ok">✓ Both libraries loaded</span>';
            // Try a real mount
            try {
                var testApp = Vue.createApp({ template: '<div>mount test</div>' });
                var div = document.createElement('div');
                document.body.appendChild(div);
                testApp.mount(div);
                res.innerHTML = '<div style="background:#d1fae5;padding:1rem;border-radius:0.5rem;color:#065f46;"><strong>✓ Vue mount works.</strong> The blank page is NOT a Vue problem — it\'s the script load order or a JS error in analytics-vue.js. Open DevTools Console on the real analytics page and look for red errors.</div>';
            } catch (e) {
                res.innerHTML = '<div style="background:#fee2e2;padding:1rem;border-radius:0.5rem;color:#991b1b;"><strong>✗ Vue exists but mount failed:</strong> ' + e.message + '</div>';
            }
        } else {
            var missing = [];
            if (!hasVue) missing.push('Vue');
            if (!hasChart) missing.push('Chart.js');
            el.innerHTML = '<span class="bad">✗ Missing: ' + missing.join(', ') + '</span>';
            res.innerHTML = '<div style="background:#fee2e2;padding:1rem;border-radius:0.5rem;color:#991b1b;"><strong>✗ A library never loaded.</strong> That\'s why your analytics page is blank. Fix: self-host both under assets/vendor/ and load from there.</div>';
        }
    }, 3500);
})();
</script>

</body>
</html>
