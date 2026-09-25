<?php
// utils/CronDispatcher.php
// =============================================================
// Internal cron dispatcher. Called by public/cron.php.
//
// Reads the task list from config/cron.php, checks each task's
// last-run timestamp against its interval, and fires the ones
// that are due. Records the new last-run time in the settings
// table under cron_last_run_<task_name>.
//
// Result shape (one per task):
//   [
//     'name'        => 'ai_health',
//     'status'      => 'ran' | 'not_due' | 'failed' | 'disabled',
//     'http_code'   => 200,
//     'duration_ms' => 342,
//     'error'       => '',
//   ]

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

    public function run(): array
    {
        $results = [];
        foreach ($this->tasks as $task) {
            $results[] = $this->runTask($task);
        }
        return $results;
    }

    private function runTask(array $task): array
    {
        $name     = (string) ($task['name']     ?? 'unknown');
        $url      = (string) ($task['url']      ?? '');
        $interval = (int)    ($task['interval'] ?? 0);
        $enabled  = (bool)   ($task['enabled']  ?? true);

        if (!$enabled) {
            return $this->result($name, 'disabled', 0, 0, '');
        }

        if ($url === '' || $interval <= 0) {
            return $this->result($name, 'failed', 0, 0, 'Missing url or interval');
        }

        $lastRun = $this->getLastRun($name);
        $now     = time();
        if ($lastRun > 0 && ($now - $lastRun) < $interval) {
            return $this->result($name, 'not_due', 0, 0, '');
        }

        // Fire the worker with the token appended.
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $fireUrl = $url . $sep . 'token=' . urlencode($this->token);

        $start = microtime(true);
        [$httpCode, $body, $err] = $this->fetch($fireUrl);
        $duration = (int) ((microtime(true) - $start) * 1000);

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->setLastRun($name, $now);
            return $this->result($name, 'ran', $httpCode, $duration, '');
        }

        return $this->result($name, 'failed', $httpCode, $duration, $err ?: ("HTTP " . $httpCode));
    }

    private function getLastRun(string $name): int
    {
        try {
            $key = 'cron_last_run_' . $name;
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val ? (int) $val : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function setLastRun(string $name, int $ts): void
    {
        try {
            $key = 'cron_last_run_' . $name;
            $stmt = $this->pdo->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, (string) $ts]);
        } catch (PDOException $e) {
            // Non-critical: the task ran, we just couldn't record it.
            error_log('CronDispatcher::setLastRun failed: ' . $e->getMessage());
        }
    }

    private function fetch(string $url): array
    {
        $body = '';
        $code = 0;
        $err  = '';

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_USERAGENT      => 'CafeSideCron/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch) ?: '';
            // curl_close() is a no-op since PHP 8.0, deprecated in 8.5.
            // We simply don't call it — the handle is garbage-collected.
            if ($body === false) $body = '';
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => 120,
                    'ignore_errors' => true,
                    'user_agent'    => 'CafeSideCron/1.0',
                ],
                'socket' => ['bindto' => '0.0.0.0:0'],
            ]);
            $body = @file_get_contents($url, false, $ctx);

            // PHP 8.5 deprecated the $http_response_header magic variable.
            // Use the new function when available, fall back to the global
            // for older PHP versions.
            if (function_exists('http_get_last_response_headers')) {
                $rawLines = http_get_last_response_headers() ?? [];
            } else {
                $rawLines = $http_response_header ?? [];
            }

            if (is_array($rawLines)) {
                foreach ($rawLines as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                        $code = (int) $m[1];
                    }
                }
            }

            if ($body === false) {
                $lastErr = error_get_last();
                $err = $lastErr['message'] ?? 'stream request failed';
                $body = '';
            }
        }

        return [$code, $body, $err];
    }

    private function result(string $name, string $status, int $httpCode, int $durationMs, string $error): array
    {
        return [
            'name'        => $name,
            'status'      => $status,
            'http_code'   => $httpCode,
            'duration_ms' => $durationMs,
            'error'       => $error,
        ];
    }
}