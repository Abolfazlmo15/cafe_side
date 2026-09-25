<?php
// src/ai/managers/AIModelCache.php
// =============================================================
// Writes provider model catalogs to the ai_models table and reads
// them back. The registry uses this to pick default models without
// hitting the network on every request.
//
// Phase 6.5 hardening:
//   - pickDefaultModel() and getFreeModels() now exclude models that
//     aren't chat-capable (code completion, embeddings, moderation,
//     audio, image generation, vision-only). This was triggered by
//     Mistral returning codestral-2508 as its default pick — a code
//     model that sorts alphabetically before every chat model and
//     writes Python-esque prose when asked to narrate a revenue report.
//   - The blocklist is a single class constant, applied to every read
//     query via a shared helper.
//   - Added purgeProvider() and purgeNonChatModels() for targeted
//     cache rebuilds.
//   - storeModels() still writes everything — the catalog remains
//     ground truth, filtering happens at read time.

require_once __DIR__ . '/../../database.php';

class AIModelCache
{
    /**
     * Substrings that indicate a model is NOT suitable for chat.
     * Matched case-insensitively against model_id. If any pattern is
     * present in the id, the model is excluded from default selection.
     *
     * Covers: code completion, embeddings, content moderation, audio,
     * image generation, and vision-only models.
     */
    private const NON_CHAT_PATTERNS = [
        'codestral',          // Mistral's code completion model
        'embed',              // embedding models
        'moderation',         // content classifiers
        'guard',              // safety guards (Llama Prompt Guard)
        'whisper',            // speech-to-text
        'tts',                // text-to-speech
        'rerank',             // ranking models
        'clip',               // vision-language
        'sdxl',               // image generation (Stable Diffusion XL)
        'flux',               // image generation
        'stable-diffusion',   // image generation
        'playground-v',       // image generation variants
        'dall-e',             // image generation
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build the SQL fragment that excludes every blocklisted pattern
     * from a query. Returns a string starting with " AND " so it can be
     * appended to an existing WHERE clause. Returns empty string if the
     * blocklist is empty.
     */
    private function buildExcludeClause(string $column = 'model_id'): string
    {
        if (empty(self::NON_CHAT_PATTERNS)) {
            return '';
        }

        $parts = [];
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            $parts[] = "LOWER({$column}) NOT LIKE "
                     . $this->pdo->quote('%' . strtolower($pattern) . '%');
        }
        return ' AND ' . implode(' AND ', $parts);
    }

    /**
     * True if a model id looks like a chat-capable language model.
     * Static so it can be used by providers and diagnostics without
     * instantiating the cache.
     */
    public static function isChatModel(string $modelId): bool
    {
        $id = strtolower($modelId);
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            if (strpos($id, $pattern) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Upsert a batch of models for a provider.
     * Stores everything — filtering happens at read time so the catalog
     * remains ground truth for diagnostics and future admin UI.
     *
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
     * Fetch cached free chat models for a provider, ordered by name.
     * Only returns models seen in the last 24 hours.
     * Excludes non-chat models via the blocklist.
     */
    public function getFreeModels(string $provider): array
    {
        $exclude = $this->buildExcludeClause();

        $stmt = $this->pdo->prepare("
            SELECT model_id, display_name, context_length
            FROM ai_models
            WHERE provider = ?
              AND is_free = 1
              AND last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              {$exclude}
            ORDER BY display_name
        ");
        $stmt->execute([$provider]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pick one default chat model for a provider.
     * Prefers free models; excludes everything in the blocklist.
     *
     * The previous version of this method returned the alphabetically
     * first model, which for Mistral was codestral-2508 — a code
     * completion model that writes Python, not café memos.
     */
    public function pickDefaultModel(string $provider): ?string
    {
        $exclude = $this->buildExcludeClause();

        $stmt = $this->pdo->prepare("
            SELECT model_id FROM ai_models
            WHERE provider = ?
              AND last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              {$exclude}
            ORDER BY is_free DESC, display_name ASC
            LIMIT 1
        ");
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['model_id'] : null;
    }

    /**
     * Count cached models per provider. Used by the diagnostic.
     * Also reports how many are blocked by the non-chat filter.
     */
    public function getProviderSummary(): array
    {
        // Build a "blocked" expression from the same blocklist, so the
        // diagnostic can show how many models were filtered out.
        $blockedParts = [];
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            $blockedParts[] = "LOWER(model_id) LIKE "
                            . $this->pdo->quote('%' . strtolower($pattern) . '%');
        }
        $blockedExpr = empty($blockedParts)
            ? 'NULL'
            : 'CASE WHEN (' . implode(' OR ', $blockedParts) . ') THEN 1 END';

        $stmt = $this->pdo->query("
            SELECT provider,
                   COUNT(*) AS total,
                   SUM(is_free) AS free,
                   COUNT({$blockedExpr}) AS blocked,
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

    /**
     * Delete every cached model for a specific provider.
     * Use when the blocklist changes and the cache needs a rebuild.
     */
    public function purgeProvider(string $provider): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM ai_models WHERE provider = ?");
        $stmt->execute([$provider]);
        return $stmt->rowCount();
    }

    /**
     * Delete cached models whose IDs match the blocklist.
     * Removes non-chat rows from every provider in one pass.
     * Returns the number of rows removed.
     */
    public function purgeNonChatModels(): int
    {
        if (empty(self::NON_CHAT_PATTERNS)) return 0;

        $parts = [];
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            $parts[] = "LOWER(model_id) LIKE "
                     . $this->pdo->quote('%' . strtolower($pattern) . '%');
        }
        $where = implode(' OR ', $parts);

        $stmt = $this->pdo->query("DELETE FROM ai_models WHERE {$where}");
        return $stmt->rowCount();
    }
}