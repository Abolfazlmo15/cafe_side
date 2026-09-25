<?php
// bootstrap.php
// =============================================================
// The single entry point for the entire application.
//
// Every public-facing file, CLI script, and worker starts with:
//
//     require_once __DIR__ . '/../bootstrap.php';
//
// (adjust the ../ depth for the file's location)
//
// After this one line:
//   - all path constants are defined (APP_ROOT, SRC_PATH, ...)
//   - every class is autoloadable by name
//   - .env is loaded
//   - application constants are defined (DB_HOST, BASE_URL, ...)
//   - procedural helpers are loaded (apiBuild*, getDbConnection, ...)

// ── 1. Path constants ──────────────────────────────────────
require_once __DIR__ . '/config/paths.php';

// ── 2. Autoloader ──────────────────────────────────────────
// Load the classmap once. The closure captures it. When PHP
// hits an unknown class, we look it up and require its file.
$classMap = require CONFIG_PATH . '/classmap.php';

spl_autoload_register(function (string $class) use ($classMap): void {
    if (isset($classMap[$class])) {
        require_once $classMap[$class];
    }
});

// ── 3. Environment ─────────────────────────────────────────
require_once SRC_PATH . '/EnvLoader.php';
EnvLoader::load(APP_ROOT . '/.env');

// ── 4. Application config ──────────────────────────────────
// Defines DB_HOST, DB_NAME, BASE_URL, SITE_NAME, ACTIVE_ENV, etc.
// Note: config.php also calls EnvLoader::load(). That call is
// idempotent — it returns early because we already loaded it.
require_once SRC_PATH . '/config.php';

// ── 5. Procedural files ────────────────────────────────────
// These define functions, not classes. They can't be autoloaded.
require_once SRC_PATH . '/database.php';  // getDbConnection()
require_once SRC_PATH . '/urls.php';      // URL_* constants + helpers
require_once SRC_PATH . '/api.php';       // apiBuild*, apiRespond*

// ── 6. Session hygiene ─────────────────────────────────────
// Safe to call at any point; does nothing if a session is
// already active. Every entry point hits this on the way in.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_probability', '0');
}

// Note: we do NOT start the session here. Some entry points are
// workers/cron that never touch sessions. Files that need a
// session start it themselves (config.php already sets gc_probability).