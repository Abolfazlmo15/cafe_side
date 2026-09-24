<?php
// src/ai/managers/AIRateLimiter.php
// =============================================================
// Sliding-window rate limiter backed by ai_call_log.
//
// Every AI call is logged. Before a call, we count recent
// successful calls. If the count exceeds the limit, we refuse.
//
// Default: 30 calls per hour. Plenty for a café admin, low enough
// to catch runaway loops.

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
    public function countRecent(int $minutes = 60): int
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
     */
    public function allow(int $perHour = 30): bool
    {
        return $this->countRecent(60) < $perHour;
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
    public function pruneOld(int $days = 30): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM ai_call_log
            WHERE called_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}