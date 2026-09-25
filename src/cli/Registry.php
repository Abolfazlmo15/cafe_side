<?php
// src/cli/Registry.php
// =============================================================
// Loads config/workers.php and returns the list of available
// workers as [name => file_path] pairs.

class Registry
{
    private array $workers = [];

    public function __construct(string $configPath, string $baseDir)
    {
        if (!is_readable($configPath)) {
            throw new RuntimeException('Worker registry not found: ' . $configPath);
        }

        $entries = require $configPath;
        if (!is_array($entries)) {
            throw new RuntimeException('config/workers.php must return an array');
        }

        foreach ($entries as $key => $rel) {
            $full = rtrim($baseDir, '/') . '/' . ltrim($rel, '/');
            if (!is_readable($full)) {
                throw new RuntimeException("Worker file missing: {$rel}");
            }
            $this->workers[$key] = $full;
        }
    }

    public function all(): array { return $this->workers; }

    public function has(string $name): bool { return isset($this->workers[$name]); }

    public function path(string $name): ?string
    {
        return $this->workers[$name] ?? null;
    }
}