<?php
// src/ai/memory/ReportMemory.php
// =============================================================
// Long-term memory for AI-generated summaries.
//
// Used by:
//   - AIBridge::generateWeeklySummary() to look up previous
//     briefings and to save new ones.
//   - analytics.php to fetch the latest weekly briefing for
//     the dashboard card.
//
// Rows are keyed by a report_key string. Weekly briefings use
// the key "weekly_summary" and are ordered by generated_at.

require_once __DIR__ . '/../../database.php';

class ReportMemory
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Store a new summary. Returns the new row id.
     */
    public function save(
        string $reportKey,
        string $summary,
        ?string $provider = null,
        ?string $model = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO report_memory (report_key, summary, provider, model_id, generated_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$reportKey, $summary, $provider, $model]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Return the most recent summary for a key, or null.
     */
    public function getLatest(string $reportKey): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, report_key, summary, provider, model_id, generated_at
            FROM report_memory
            WHERE report_key = ?
            ORDER BY generated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$reportKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Return up to $limit recent summaries for a key, newest first.
     */
    public function getRecent(string $reportKey, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare("
            SELECT id, report_key, summary, provider, model_id, generated_at
            FROM report_memory
            WHERE report_key = ?
            ORDER BY generated_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$reportKey]);
        return $stmt->fetchAll();
    }

    /**
     * True if a summary for this key was generated within the last
     * $hours hours. Used by the worker to avoid double-runs.
     */
    public function hasRecent(string $reportKey, int $hours = 20): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM report_memory
            WHERE report_key = ?
              AND generated_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$reportKey, $hours]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Keep only the newest $keep rows for a key.
     */
    public function pruneOld(string $reportKey, int $keep = 12): int
    {
        $keep = max(1, $keep);
        $stmt = $this->pdo->prepare("
            DELETE FROM report_memory
            WHERE report_key = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM report_memory
                      WHERE report_key = ?
                      ORDER BY generated_at DESC, id DESC
                      LIMIT ?
                  ) AS keep_ids
              )
        ");
        $stmt->execute([$reportKey, $reportKey, $keep]);
        return $stmt->rowCount();
    }
}
