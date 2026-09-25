<?php
// src/cli/Worker.php
// =============================================================
// Abstract base class for every worker.
//
// A worker is a chunk of logic that runs on a schedule. It must
// be callable two ways:
//   1. Over HTTP from cron (token-gated)
//   2. From the CLI with `php worker.php [args]` (no token)
//
// Subclasses only implement run(). Everything else — context
// detection, token check, timing, error handling — lives here.

require_once __DIR__ . '/Output.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

abstract class Worker
{
    protected bool    $isCli;
    protected string  $name;
    protected string  $description;
    protected float   $startedAt;
    protected array   $argv = [];

    public function __construct()
    {
        $this->isCli       = (PHP_SAPI === 'cli');
        $this->startedAt   = microtime(true);
        $this->name        = $this->getName();
        $this->description = $this->getDescription();
        $this->bootstrap();
    }

    /**
     * Subclasses override these to identify themselves in the menu.
     */
    abstract public function getName(): string;
    abstract public function getDescription(): string;
    abstract public function run(): int;  // returns exit code (0 = success)

    /**
     * Called after construction. Detects context, sets headers,
     * enforces the token check for HTTP requests.
     */
    private function bootstrap(): void
    {
        if (!$this->isCli) {
            header('Content-Type: text/plain; charset=utf-8');
            $expected = env('AI_CRON_TOKEN', '');
            $given    = $_GET['token'] ?? '';
            if ($expected === '' || !hash_equals($expected, $given)) {
                http_response_code(403);
                echo "Forbidden\n";
                exit;
            }
        } else {
            // In CLI mode we accept extra arguments after the filename.
            global $argv;
            $this->argv = $argv ?? [];
        }
    }

    /**
     * Optional argument helpers for subclasses.
     */
    protected function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->argv, true);
    }

    protected function getArg(int $position, ?string $default = null): ?string
    {
        return $this->argv[$position] ?? $default;
    }

    /**
     * Runs the worker, handles timing + exceptions, prints a footer.
     * This is what the runner calls. Subclasses override run(), not
     * this method.
     */
    public function execute(): int
    {
        if (!$this->isCli) {
            echo "=== {$this->name} ===\n";
            echo 'Time: ' . date('c') . "\n\n";
        } else {
            Output::title($this->name);
        }

        try {
            $exitCode = $this->run();
        } catch (Throwable $e) {
            Output::fail('Uncaught: ' . $e->getMessage());
            Output::info('at ' . $e->getFile() . ':' . $e->getLine());
            $exitCode = 1;
        }

        $elapsed = microtime(true) - $this->startedAt;

        if (!$this->isCli) {
            echo "\nDone in " . number_format($elapsed, 2) . "s\n";
        } else {
            Output::dim('  finished in ' . number_format($elapsed, 2) . 's');
            Output::line();
        }

        return $exitCode;
    }
}