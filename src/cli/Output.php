<?php
// src/cli/Output.php
// =============================================================
// Terminal output helper. Colors only when stdout is a TTY
// (i.e., we're actually in a terminal) — plain text otherwise.

class Output
{
    private static ?bool $colored = null;

    private const RESET  = "\033[0m";
    private const BOLD   = "\033[1m";
    private const DIM    = "\033[2m";
    private const RED    = "\033[31m";
    private const GREEN  = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE   = "\033[34m";
    private const CYAN   = "\033[36m";

    private static function isColored(): bool
    {
        if (self::$colored !== null) return self::$colored;
        // Only color if stdout is a real terminal.
        if (PHP_SAPI !== 'cli') { return self::$colored = false; }
        if (!function_exists('posix_isatty')) { return self::$colored = true; }
        return self::$colored = @posix_isatty(STDOUT);
    }

    private static function wrap(string $text, string $code): string
    {
        if (!self::isColored()) return $text;
        return $code . $text . self::RESET;
    }

    public static function line(string $text = ''): void { echo $text . PHP_EOL; }

    public static function title(string $text): void
    {
        echo PHP_EOL;
        echo self::wrap('─── ' . $text . ' ' . str_repeat('─', max(0, 60 - strlen($text))), self::CYAN) . PHP_EOL;
    }

    public static function ok(string $text): void
    {
        echo self::wrap('  ✓ ', self::GREEN) . $text . PHP_EOL;
    }

    public static function fail(string $text): void
    {
        echo self::wrap('  ✗ ', self::RED) . $text . PHP_EOL;
    }

    public static function warn(string $text): void
    {
        echo self::wrap('  ⊘ ', self::YELLOW) . $text . PHP_EOL;
    }

    public static function info(string $text): void
    {
        echo self::wrap('    ', self::DIM) . $text . PHP_EOL;
    }

    public static function dim(string $text): void
    {
        echo self::wrap($text, self::DIM) . PHP_EOL;
    }

    public static function bold(string $text): void
    {
        echo self::wrap($text, self::BOLD) . PHP_EOL;
    }

    public static function label(string $key, string $value, int $pad = 14): void
    {
        echo '  ' . str_pad($key, $pad, '.') . ' ' . $value . PHP_EOL;
    }

    // Add these methods to the Output class, alongside the existing dim().

    // Returns a dim-colored string instead of echoing.
    public static function dimText(string $text): string
    {
        return self::wrap($text, self::DIM);
    }

    // Returns an OK-colored string (for inline use).
    public static function okText(string $text): string
    {
        return self::wrap('  ✓ ', self::GREEN) . $text;
    }

    // Returns a FAIL-colored string (for inline use).
    public static function failText(string $text): string
    {
        return self::wrap('  ✗ ', self::RED) . $text;
    }


}