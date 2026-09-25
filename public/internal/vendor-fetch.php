<?php
// public/admin/vendor-fetch.php
// =============================================================
// One-shot downloader for the vendor JS libraries.
// Fetches from multiple CDN sources, verifies the content, and
// writes to assets/vendor/. Run once, then delete this file.
//
// Usage:
//   http://localhost/cafe-qr/public/admin/vendor-fetch.php
//
// Requires admin login. Fails loudly if anything is off.

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$targets = [
    'vue.global.prod.js' => [
        'https://cdn.jsdelivr.net/npm/vue@3.5.43/dist/vue.global.prod.js',
        'https://cdnjs.cloudflare.com/ajax/libs/vue/3.5.43/vue.global.prod.min.js',
    ],
    'chart.umd.js' => [
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js',
        'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js',
    ],
];

$vendorDir = __DIR__ . '/../../assets/vendor/';
if (!is_dir($vendorDir)) {
    mkdir($vendorDir, 0777, true);
}

echo "=== Vendor Fetch ===\n\n";

foreach ($targets as $filename => $urls) {
    echo "--- {$filename} ---\n";
    $written = false;

    foreach ($urls as $url) {
        echo "  Trying: {$url}\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'CafeSideVendorFetcher/1.0',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            echo "    ✗ Failed: " . ($err ?: "HTTP {$code}") . "\n";
            continue;
        }

        $size = strlen($body);
        $head = substr(ltrim($body), 0, 40);

        // Sanity checks — reject HTML pages, redirects, empty content
        if ($size < 5000) {
            echo "    ✗ Rejected: too small ({$size} bytes)\n";
            continue;
        }
        if (stripos($head, '<!DOCTYPE') === 0 || stripos($head, '<html') === 0) {
            echo "    ✗ Rejected: HTML response, not JavaScript\n";
            continue;
        }
        // Both Vue and Chart contain JavaScript content; check for a variable declaration
        if (stripos($body, 'Vue') === false && stripos($body, 'Chart') === false) {
            echo "    ✗ Rejected: no Vue/Chart token found\n";
            continue;
        }

        $path = $vendorDir . $filename;
        if (file_put_contents($path, $body) !== false) {
            echo "    ✓ Written: {$filename} ({$size} bytes)\n";
            $written = true;
            break;
        } else {
            echo "    ✗ Could not write to {$path}\n";
        }
    }

    if (!$written) {
        echo "  ✗ Could not fetch {$filename} from any source.\n";
    }
    echo "\n";
}

echo "=== Done ===\n";
echo "Delete this file after verifying the analytics page renders.\n";
