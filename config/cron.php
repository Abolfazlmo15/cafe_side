<?php
// config/cron.php
// =============================================================
// Schedule for the internal dispatcher (utils/CronDispatcher.php).
//
// Each entry:
//   name      unique identifier, also used for the last-run key
//   url       full URL of the worker to call
//   interval  seconds between runs
//   enabled   true / false
//
// Updated in Phase 13.6: worker paths now point at the new
// /public/workers/*.php locations after the restructure.

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