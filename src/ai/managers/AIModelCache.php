<?php
// src/ai/managers/AIModelCache.php
// =============================================================
// Writes provider model catalogs to the ai_models table and reads
// them back. The registry uses this to pick default models without
// hitting the network on every request.

require_once __DIR__ . '/../../database.php';

class AIModelCache
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Upsert a batch of models for a provider.
     * $models: array of ['id', 'name', 'is_free', 'context_length', 'description'].
     * Returns number of rows written.
     */
    public function storeModels(string $provider, array $models): int
    {
        if (empty($models)) return 0;

        $stmt = $this->pdo->prepare("
            INSERT INTO ai_models
                (provider, model_id, display_name, is_free, context_length, description, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                display_name   = VALUES(display_name),
                is_free        = VALUES(is_free),
                context_length = VALUES(context_length),
                description    = VALUES(description),
                last_seen      = NOW()
        ");

        $written = 0;
        foreach ($models as $m) {
            if (empty($m['id'])) continue;
            $stmt->execute([
                $provider,
                $m['id'],
                $m['name'] ?? $m['id'],
                !empty($m['is_free']) ? 1 : 0,
                (int) ($m['context_length'] ?? 0),
                $m['description'] ?? '',
            ]);
            $written++;
        }
        return $written;
    }

    /**
     * Fetch cached free models for a provider, ordered by name.
     * Only returns models seen in the last 24 hours.
     */
    public function getFreeModels(string $provider): array
    {
        $stmt = $this->pdo->prepare("
            SELECT model_id, display_name, context_length
            FROM ai_models
            WHERE provider = ?
              AND is_free = 1
              AND last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY display_name
        ");
        $stmt->execute([$provider]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pick one default model for a provider. Prefers free models;
     * falls back to any model seen recently.
     */
    public function pickDefaultModel(string $provider): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT model_id FROM ai_models
            WHERE provider = ?
              AND last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY is_free DESC, display_name ASC
            LIMIT 1
        ");
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['model_id'] : null;
    }

    /**
     * Count cached models per provider. Used by the diagnostic.
     */
    public function getProviderSummary(): array
    {
        $stmt = $this->pdo->query("
            SELECT provider,
                   COUNT(*) AS total,
                   SUM(is_free) AS free,
                   MAX(last_seen) AS last_seen
            FROM ai_models
            GROUP BY provider
            ORDER BY provider
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove models not seen in the last 7 days. Called by cron.
     */
    public function pruneStale(): int
    {
        $stmt = $this->pdo->query("
            DELETE FROM ai_models
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        return $stmt->rowCount();
    }
}