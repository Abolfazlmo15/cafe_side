<?php
// src/config.php – Environment-aware configuration for Cafe Side
// ==============================================================
// Uses env() to read variables if set, else falls back to hardcoded defaults.
// On InfinityFree, no env vars are set, so the fallback values are used.
ini_set('session.gc_probability', '0');

/**
 * Fetch an env var; return default if not set or empty.
 */
function env(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

// ───── Database (InfinityFree) ─────
define('DB_HOST', env('DB_HOST', 'sql309.infinityfree.com'));
define('DB_NAME', env('DB_NAME', 'if0_42960545_cafeside'));
define('DB_USER', env('DB_USERNAME', 'if0_42960545'));
define('DB_PASS', env('DB_PASSWORD', 'OINa7Gda8G'));
define('DB_PORT', (int) env('DB_PORT', '3306'));

// ───── URLs ─────
// InfinityFree serves files directly from htdocs/, so there is no /public segment in URLs.
define('BASE_URL',   env('BASE_URL', 'https://cafe-side.gt.tc'));
define('PUBLIC_URL', BASE_URL . '/public');

// ───── App identity ─────
define('SITE_NAME', env('SITE_NAME', 'Cafe Side'));

// ───── Fallbacks ─────
define('ADMIN_PASS_FALLBACK', env('ADMIN_PASS_FALLBACK', 'C@f3_M@n4g3r!'));
define('MAX_TABLES_FALLBACK', (int) env('MAX_TABLES_FALLBACK', '20'));

// ───── Environment ─────
// Set to 'development' locally to see errors; 'production' hides them on live site.
define('ENVIRONMENT', env('APP_ENV', 'production'));

if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}