<?php
// config/workers.php
// =============================================================
// Registry of every runnable worker.
//
// Key = short name shown in the CLI menu and used by run.php.
// Value = path relative to the project root.
//
// To add a worker: create the file, add one line here. Done.

return [
    'ai_health'   => 'public/workers/ai_health.php',
    'ai_models'   => 'public/workers/ai_models.php',
    'ai_recovery' => 'public/workers/ai_recovery.php',
    'analytics'   => 'public/workers/analytics.php',
];