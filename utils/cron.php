<?php
// utils/cron.php
// =============================================================
// The dispatcher. Reads config/cron.php, checks the last-run time
// for each task, and calls any task that's due.
//
// Runs are stored in settings under cron_last_{name}. The first
// run of any task is always allowed.
//
// This file can be included from a public URL (public/cron.php)
// or called from the CLI:
//   php utils/cron.php --token=YOUR_TOKEN

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';

class CronDispatcher
{
    private PDO $pdo;
    private array $tasks;
    private string $token;

    public function __construct(PDO $pdo, array $tasks, string $token)
    {
        $this->pdo   = $pdo;
        $this->tasks = $tasks;
        $this->token = $token;
    }

    /**
     * Run all due tasks. Returns a list of result arrays:
     *   ['name', 'status', 'http_code', 'duration_ms', 'error']
     */
    public function run(): array
    {
        $results = [];
        foreach ($this->tasks as $task) {
            if (empty($task['enabled'])) {
                $results[] = [
                    'name' => $task['name'],
                    'status' => 'disabled',
                    'http_code' => 0,
                    'duration_ms' => 0,
                    'error' => '',
                ];
                continue;
            }

            if (!$this->isDue($task)) {
                $results[] = [
                    'name' => $task['name'],
                    'status' => 'not_due',
                    'http_code' => 0,
                    'duration_ms' => 0,
                    'error' => '',
                ];
                continue;
            }

            $results[] = $this->runTask($task);
        }
        return $results;
    }

    // ─────────────────────────────────────────────────────────
    // Due-check
    // ─────────────────────────────────────────────────────────

    private function isDue(array $task): bool
    {
        $lastRun = $this->getLastRun($task['name']);
        if ($lastRun === null) return true;  // never run
        return (time() - $lastRun) >= (int) $task['interval'];
    }

    private function getLastRun(string $name): ?int
    {
        $key = 'cron_last_' . $name;
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val === false || $val === '' ? null : (int) $val;
        } catch (PDOException $e) {
            return null;
        }
    }

    private function setLastRun(string $name): void
    {
        $key = 'cron_last_' . $name;
        $now = (string) time();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $now]);
        } catch (PDOException $e) {
            error_log('CronDispatcher::setLastRun failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // Task execution
    // ─────────────────────────────────────────────────────────

    private function runTask(array $task): array
    {
        $url = $task['url'] . (strpos($task['url'], '?') === false ? '?' : '&')
             . 'token=' . urlencode($this->token);

        $start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $duration = (int) ((microtime(true) - $start) * 1000);

        if ($code >= 200 && $code < 300) {
            $this->setLastRun($task['name']);
            return [
                'name' => $task['name'],
                'status' => 'ran',
                'http_code' => $code,
                'duration_ms' => $duration,
                'error' => '',
            ];
        }

        return [
            'name' => $task['name'],
            'status' => 'failed',
            'http_code' => $code,
            'duration_ms' => $duration,
            'error' => $err ?: ('HTTP ' . $code),
        ];
    }
}