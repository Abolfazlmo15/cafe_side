<?php
// src/ai/bridge/AIBridge.php
// =============================================================
// Entry point for report narration AND AI-curated report data.
//
//   explainReport()   -> prose narration of SQL data (short)
//   executiveReview() -> prose review of the whole period (longer)
//   processReport()   -> re-curated report data as JSON
//
// Narration is cached under the plain report key (ai_summary column).
// AI-curated data is cached under `{reportKey}__ai` (data column).

require_once __DIR__ . '/../providers/ProviderRegistry.php';
require_once __DIR__ . '/../prompts/PromptLibrary.php';
require_once __DIR__ . '/../managers/AIRateLimiter.php';

class AIBridge
{
    private PDO $pdo;
    private ProviderRegistry $registry;
    private AIRateLimiter $rateLimiter;

    public function __construct(PDO $pdo)
    {
        $this->pdo         = $pdo;
        $this->registry    = new ProviderRegistry($pdo);
        $this->rateLimiter = new AIRateLimiter($pdo);
    }

    public function explainReport(
        string $reportKey, array $reportData,
        string $dateStart, string $dateEnd, bool $force = false
    ): array {
        return $this->runNarration($reportKey, $reportData, $dateStart, $dateEnd, $force, 400, 0.4);
    }

    public function executiveReview(
        array $summaryData, string $dateStart, string $dateEnd, bool $force = false
    ): array {
        return $this->runNarration('executive_summary', $summaryData, $dateStart, $dateEnd, $force, 500, 0.5);
    }

    /**
     * Re-curate a report. Returns the AI-processed JSON structure
     * on success, or null on failure. Uses a __ai suffix cache key.
     */
    public function processReport(
        string $reportKey, array $sqlData,
        string $dateStart, string $dateEnd, bool $force = false
    ): ?array {
        $cacheKey = $reportKey . '__ai';

        if (!$force) {
            $cached = $this->getCachedData($cacheKey, $dateStart, $dateEnd);
            if ($cached !== null) return $cached;
        }

        if (!$this->rateLimiter->allow()) return null;

        $messages = PromptLibrary::buildProcessMessages($reportKey, $sqlData, $dateStart, $dateEnd);
        if ($messages === null) return null;

        $result = $this->registry->chat($messages, [
            'max_tokens'  => 2000,
            'temperature' => 0.3,
        ]);

        $this->rateLimiter->log('process_' . $reportKey, $result);

        if (empty($result['ok'])) return null;

        $text = trim((string) $result['text']);
        // Strip optional code fences that the AI sometimes adds.
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) return null;

        $this->saveCachedData($cacheKey, $dateStart, $dateEnd, $parsed);
        return $parsed;
    }

    public function clearSummary(string $reportKey, string $dateStart, string $dateEnd): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE analytics_cache
            SET ai_summary = NULL, ai_summary_provider = NULL
            WHERE report_key = ? AND date_start = ? AND date_end = ?
        ");
        $stmt->execute([$reportKey, $dateStart, $dateEnd]);
    }

    // ---------------------------------------------------------
    // Narration path
    // ---------------------------------------------------------

    private function runNarration(
        string $promptKey, array $data,
        string $dateStart, string $dateEnd,
        bool $force, int $maxTokens, float $temperature
    ): array {
        if (!$force) {
            $cached = $this->getCachedSummary($promptKey, $dateStart, $dateEnd);
            if ($cached !== null) {
                return ['ok' => true, 'text' => $cached, 'provider' => null, 'cached' => true, 'error' => null];
            }
        }

        if (!$this->rateLimiter->allow()) {
            return ['ok' => false, 'text' => '', 'provider' => null, 'cached' => false,
                    'error' => 'Too many AI calls in the last hour. Try again shortly.'];
        }

        $messages = PromptLibrary::buildMessages($promptKey, $data, $dateStart, $dateEnd);
        if ($messages === null) {
            return ['ok' => false, 'text' => '', 'provider' => null, 'cached' => false,
                    'error' => "No narration template for '" . $promptKey . "'."];
        }

        $result = $this->registry->chat($messages, ['max_tokens' => $maxTokens, 'temperature' => $temperature]);
        $this->rateLimiter->log($promptKey, $result);

        if (!empty($result['ok'])) {
            $this->saveCachedSummary($promptKey, $dateStart, $dateEnd,
                (string)($result['text'] ?? ''), $result['provider'] ?? null);
        }

        return [
            'ok'       => !empty($result['ok']),
            'text'     => (string)($result['text'] ?? ''),
            'provider' => $result['provider'] ?? null,
            'cached'   => false,
            'error'    => $result['error'] ?? null,
        ];
    }

    // ---------------------------------------------------------
    // Cache helpers
    // ---------------------------------------------------------

    private function getCachedSummary(string $key, string $dateStart, string $dateEnd): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT ai_summary FROM analytics_cache
            WHERE report_key = ? AND date_start = ? AND date_end = ?
              AND ai_summary IS NOT NULL AND ai_summary <> ''
        ");
        $stmt->execute([$key, $dateStart, $dateEnd]);
        $row = $stmt->fetch();
        return $row ? (string) $row['ai_summary'] : null;
    }

    private function saveCachedSummary(string $key, string $dateStart, string $dateEnd, string $text, ?string $provider): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cache (report_key, date_start, date_end, ai_summary, ai_summary_provider, generated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE ai_summary = VALUES(ai_summary), ai_summary_provider = VALUES(ai_summary_provider)
        ");
        $stmt->execute([$key, $dateStart, $dateEnd, $text, $provider]);
    }

    private function getCachedData(string $key, string $dateStart, string $dateEnd): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT data FROM analytics_cache
            WHERE report_key = ? AND date_start = ? AND date_end = ?
              AND data IS NOT NULL
        ");
        $stmt->execute([$key, $dateStart, $dateEnd]);
        $row = $stmt->fetch();
        if (!$row || empty($row['data'])) return null;
        $decoded = json_decode($row['data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function saveCachedData(string $key, string $dateStart, string $dateEnd, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cache (report_key, date_start, date_end, data, generated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE data = VALUES(data), generated_at = NOW()
        ");
        $stmt->execute([$key, $dateStart, $dateEnd, $json]);
    }
}
