<?php
// config/paths.php
// =============================================================
// Absolute filesystem paths. The single source of truth for
// "where is X". Every other file uses these constants, never
// __DIR__ relative paths.
//
// __DIR__ here is the config/ folder. APP_ROOT is one level up.

define('APP_ROOT',        dirname(__DIR__));
define('CONFIG_PATH',     APP_ROOT . '/config');
define('SRC_PATH',        APP_ROOT . '/src');
define('PUBLIC_PATH',     APP_ROOT . '/public');
define('UTILS_PATH',      APP_ROOT . '/utils');
define('ASSETS_PATH',     APP_ROOT . '/assets');
define('MIGRATIONS_PATH', SRC_PATH  . '/migrations/versions');

// Storage for logs, cache dumps, temp files. Created lazily by
// code that needs it — nothing here writes to it automatically.
define('STORAGE_PATH',    APP_ROOT . '/storage');
define('LOGS_PATH',       STORAGE_PATH . '/logs');