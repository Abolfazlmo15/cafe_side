<?php
// src/analytics/AnalyticsCache.php
// =============================================================
// Reads and writes the analytics_cache table.
//
// Key = report_key + date_start + date_end. The unique key on the
// table means we only ever have one row per report per range.
//
// Entries older than $maxAgeMinutes are considered stale and
// recomputed. Default: 60 minutes.

require_once __DIR__ . '/../database.php';

class AnalyticsCache
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return cached data or null if missing / stale.
     */
    public function get(string $key, string $dateStart, string $dateEnd, int $maxAgeMinutes = 60): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT data, generated_at
            FROM analytics_cache
            WHERE report_key = ? AND date_start = ? AND date_end = ?
        ");
        $stmt->execute([$key, $dateStart, $dateEnd]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $age = time() - strtotime($row['generated_at']);
        if ($age > $maxAgeMinutes * 60) return null;

        $data = json_decode($row['data'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * Store or refresh a cache entry.
     */
    public function put(string $key, string $dateStart, string $dateEnd, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cache (report_key, date_start, date_end, data, generated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                data = VALUES(data),
                generated_at = NOW()
        ");
        $stmt->execute([$key, $dateStart, $dateEnd, $json]);
    }

    /**
     * Wipe every cached report. Called manually when the underlying
     * data changed and you want to force regeneration.
     */
    public function clearAll(): int
    {
        $stmt = $this->pdo->query("DELETE FROM analytics_cache");
        return $stmt->rowCount();
    }
}
