<?php
// config/cron.php
// =============================================================
// Schedule for the internal dispatcher. Add new tasks by appending
// to the array. Each entry has:
//
//   name      – unique identifier
//   url       – full URL of the worker to call
//   interval  – seconds between runs
//   enabled   – true/false, so you can pause without removing
//
// The dispatcher (utils/cron.php) reads this file on every run
// and decides which tasks are due.

return [
    [
        'name'     => 'ai_health_worker',
        'url'      => BASE_URL . '/utils/ai_health_worker.php',
        'interval' => 1800,   // 30 minutes
        'enabled'  => true,
    ],
    [
        'name'     => 'ai_model_refresh',
        'url'      => BASE_URL . '/utils/ai_model_refresh.php',
        'interval' => 21600,  // 6 hours
        'enabled'  => true,
    ],
    [
        'name'     => 'analytics_worker',
        'url'      => BASE_URL . '/utils/analytics_worker.php',
        'interval' => 86400,  // 24 hours
        'enabled'  => false,  // enable in Phase 5
    ],
];