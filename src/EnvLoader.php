<?php
// src/EnvLoader.php – Minimal .env file parser
// ==============================================
// Reads key=value lines and pushes them into the PHP environment
// via putenv() and $_ENV. Skips comments and blank lines. Idempotent.

class EnvLoader {

    private static $loaded = false;

    /**
     * Load a .env file into the environment.
     * Calling this multiple times only loads once.
     */
    public static function load($path) {
        if (self::$loaded) return true;
        if (!is_readable($path)) return false;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || $line[0] === '#') continue;

            // Must contain '='
            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip surrounding quotes if present
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Don't overwrite existing real env vars
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
        return true;
    }
}