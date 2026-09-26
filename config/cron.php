<?php
// config/cron.php
// =============================================================
// Schedule for the internal dispatcher.
//
// Phase 7: ai_proxies removed (abandoned). The Cloudflare Worker
// relay needs no local cron task — it runs on Cloudflare's edge.

return [
    [
        'name'     => 'ai_health',
        'url'      => PUBLIC_URL . '/workers/ai_health.php',
        'interval' => 1800,    // 30 minutes
        'enabled'  => true,
    ],
    [
        'name'     => 'ai_models',
        'url'      => PUBLIC_URL . '/workers/ai_models.php',
        'interval' => 21600,   // 6 hours
        'enabled'  => true,
    ],
    [
        'name'     => 'ai_recovery',
        'url'      => PUBLIC_URL . '/workers/ai_recovery.php',
        'interval' => 300,     // 5 minutes
        'enabled'  => true,
    ],
    [
        'name'     => 'analytics_weekly',
        'url'      => PUBLIC_URL . '/workers/analytics.php?task=weekly',
        'interval' => 604800,  // 7 days
        'enabled'  => true,
    ],
];