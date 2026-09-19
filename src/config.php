<?php
// src/config.php – Environment-aware configuration
// =================================================
// Reads DB credentials + BASE_URL from environment variables when available.
// Falls back to local defaults so XAMPP still works out of the box.

/**
 * Small helper: fetch an env var, use fallback if not set.
 */
function env(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

// ───── Database ─────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'cafe_qr_db'));
define('DB_USER', env('DB_USERNAME', 'root'));
define('DB_PASS', env('DB_PASSWORD', ''));
define('DB_PORT', (int) env('DB_PORT', '3306'));

// ───── URLs ─────
define('BASE_URL',    env('BASE_URL', 'http://localhost/cafe-qr'));
define('PUBLIC_URL',  BASE_URL . '/public');

// ───── App identity ─────
define('SITE_NAME', env('SITE_NAME', 'Cafe Side'));

// ───── Fallbacks ─────
define('ADMIN_PASS_FALLBACK', env('ADMIN_PASS_FALLBACK', 'C@f3_M@n4g3r!'));
define('MAX_TABLES_FALLBACK', (int) env('MAX_TABLES_FALLBACK', '20'));

// ───── Environment ─────
define('ENVIRONMENT', env('APP_ENV', 'development'));

if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}