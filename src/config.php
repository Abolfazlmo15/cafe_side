<?php
// src/config.php – Environment-aware configuration for Cafe Side
// ==============================================================
// Reads credentials from .env, picks between local/prod based on
// the LOCAL flag, and exposes ACTIVE_ENV for debugging.
//
// LOCAL modes:
//   1  = local only
//   0  = production only
//  -1  = try production, fall back to local if unreachable

ini_set('session.gc_probability', '0');

// ───── Load .env ─────
require_once __DIR__ . '/EnvLoader.php';
EnvLoader::load(__DIR__ . '/../.env');

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

// ───── Read LOCAL mode ─────
$localMode = (int) env('LOCAL', '-1');

// ───── Decide which environment to use ─────
$activeEnv = 'local';   // default fallback

if ($localMode === 1) {
    // Force local
    $activeEnv = 'local';
} elseif ($localMode === 0) {
    // Force production
    $activeEnv = 'prod';
} else {
    // Auto mode: try production first
    $prodHost = env('PROD_DB_HOST');
    $prodPort = (int) env('PROD_DB_PORT', '3306');
    $prodName = env('PROD_DB_NAME');
    $prodUser = env('PROD_DB_USER');
    $prodPass = env('PROD_DB_PASS');

    if ($prodHost && $prodName) {
        try {
            // Short timeout so unreachable prod doesn't hang the request
            $dsnTest = "mysql:host={$prodHost};port={$prodPort};dbname={$prodName};charset=utf8mb4";
            $probe = new PDO($dsnTest, $prodUser, $prodPass, [
                PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT  => 3,
            ]);
            $probe = null;   // release immediately
            $activeEnv = 'prod';
        } catch (PDOException $e) {
            // Prod unreachable — fall back to local
            $activeEnv = 'local';
        }
    }
}

// Expose for debugging (echo ACTIVE_ENV in a page to see what's in use)
define('ACTIVE_ENV', $activeEnv);

// ───── Database constants ─────
if ($activeEnv === 'prod') {
    define('DB_HOST', env('PROD_DB_HOST'));
    define('DB_NAME', env('PROD_DB_NAME'));
    define('DB_USER', env('PROD_DB_USER'));
    define('DB_PASS', env('PROD_DB_PASS'));
    define('DB_PORT', (int) env('PROD_DB_PORT', '3306'));
    define('BASE_URL', env('PROD_BASE_URL'));
} else {
    define('DB_HOST', env('LOCAL_DB_HOST', 'localhost'));
    define('DB_NAME', env('LOCAL_DB_NAME', 'cafe_qr_db'));
    define('DB_USER', env('LOCAL_DB_USER', 'root'));
    define('DB_PASS', env('LOCAL_DB_PASS', ''));
    define('DB_PORT', (int) env('LOCAL_DB_PORT', '3306'));
    define('BASE_URL', env('LOCAL_BASE_URL', 'http://localhost/cafe-qr'));
}

// ───── URLs ─────
define('PUBLIC_URL', BASE_URL . '/public');

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