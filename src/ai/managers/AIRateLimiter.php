<?php
// src/ai/managers/AIRateLimiter.php
// =============================================================
// Sliding-window rate limiter backed by ai_call_log.
//
// v2 — the window is now per-MINUTE, not per-hour.
//
// Two independent checks, both must pass:
//   - per-minute burst cap    (default 60 / 60s)
//   - per-day safety cap      (default 1000 / 24h)
//
// The per-minute cap matches typical free-tier provider limits.
// The per-day cap catches runaway loops that stay just under the
// per-minute threshold.

require_once __DIR__ . '/../../database.php';

class AIRateLimiter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Count successful calls in the last N minutes.
     */
    public function countRecent(int $minutes = 1): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM ai_call_log
            WHERE called_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND success = 1
        ");
        $stmt->execute([$minutes]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Is another call allowed right now?
     *
     * $perMinute — burst cap, default 60
     * $perDay    — daily safety cap, default 1000
     */
    public function allow(int $perMinute = 60, int $perDay = 1000): bool
    {
        // Per-minute burst cap
        if ($this->countRecent(1) >= $perMinute) {
            return false;
        }

        // Per-day safety cap
        if ($this->countRecent(60 * 24) >= $perDay) {
            return false;
        }

        return true;
    }

    /**
     * Log an AI call. Called on every attempt, success or failure.
     */
    public function log(string $promptKey, array $result): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_call_log
                    (provider, model_id, prompt_key, tokens_in, tokens_out,
                     success, latency_ms, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $result['provider'] ?? null,
                $result['model']    ?? null,
                $promptKey,
                (int) ($result['tokens_in']  ?? 0),
                (int) ($result['tokens_out'] ?? 0),
                !empty($result['ok']) ? 1 : 0,
                (int) ($result['latency_ms'] ?? 0),
                substr((string) ($result['error'] ?? ''), 0, 500),
            ]);
        } catch (PDOException $e) {
            error_log('AIRateLimiter::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete log rows older than N days. Called by cron.
     */
    public function pruneOld(int $days = 7): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM ai_call_log
            WHERE called_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}