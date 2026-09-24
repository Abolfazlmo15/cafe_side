<?php
// src/ai/bridge/AIBridge.php
// =============================================================
// The single entry point for report-level AI narration.
//
// Flow:
//   1. Check analytics_cache for an existing AI summary
//   2. Check rate limiter
//   3. Build messages via PromptLibrary
//   4. Walk the provider chain via ProviderRegistry
//   5. Log to ai_call_log
//   6. On success, save summary to analytics_cache
//   7. Return the result

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

    /**
     * Generate an AI explanation for a report.
     *
     * @return array {
     *   ok:       bool,
     *   text:     string,
     *   provider: ?string,
     *   cached:   bool,
     *   error:    ?string,
     * }
     */
    public function explainReport(
        string $reportKey,
        array $reportData,
        string $dateStart,
        string $dateEnd,
        bool $force = false
    ): array {
        // 1. Cache hit?
        if (!$force) {
            $cached = $this->getCachedSummary($reportKey, $dateStart, $dateEnd);
            if ($cached !== null) {
                return [
                    'ok'       => true,
                    'text'     => $cached,
                    'provider' => null,
                    'cached'   => true,
                    'error'    => null,
                ];
            }
        }

        // 2. Rate limit
        if (!$this->rateLimiter->allow()) {
            return [
                'ok'       => false,
                'text'     => '',
                'provider' => null,
                'cached'   => false,
                'error'    => 'Too many AI calls in the last hour. Try again shortly.',
            ];
        }

        // 3. Build messages
        $messages = PromptLibrary::buildMessages($reportKey, $reportData, $dateStart, $dateEnd);
        if ($messages === null) {
            return [
                'ok'       => false,
                'text'     => '',
                'provider' => null,
                'cached'   => false,
                'error'    => "No prompt template for report '" . $reportKey . "'.",
            ];
        }

        // 4. Call the provider chain
        $result = $this->registry->chat($messages, [
            'max_tokens'  => 400,
            'temperature' => 0.4,
        ]);

        // 5. Log (always)
        $this->rateLimiter->log($reportKey, $result);

        // 6. Cache on success
        if (!empty($result['ok'])) {
            $this->saveCachedSummary(
                $reportKey,
                $dateStart,
                $dateEnd,
                (string) ($result['text'] ?? ''),
                $result['provider'] ?? null
            );
        }

        return [
            'ok'       => !empty($result['ok']),
            'text'     => (string) ($result['text'] ?? ''),
            'provider' => $result['provider'] ?? null,
            'cached'   => false,
            'error'    => $result['error'] ?? null,
        ];
    }

    /**
     * Clear the AI summary for a report+range. Useful if the
     * underlying report data changed and you want a fresh take.
     */
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
    // Cache helpers
    // ---------------------------------------------------------

    private function getCachedSummary(string $key, string $dateStart, string $dateEnd): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT ai_summary FROM analytics_cache
            WHERE report_key = ? AND date_start = ? AND date_end = ?
              AND ai_summary IS NOT NULL
              AND ai_summary <> ''
        ");
        $stmt->execute([$key, $dateStart, $dateEnd]);
        $row = $stmt->fetch();
        return $row ? (string) $row['ai_summary'] : null;
    }

    private function saveCachedSummary(
        string $key,
        string $dateStart,
        string $dateEnd,
        string $text,
        ?string $provider
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cache
                (report_key, date_start, date_end, ai_summary, ai_summary_provider, generated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                ai_summary          = VALUES(ai_summary),
                ai_summary_provider = VALUES(ai_summary_provider)
        ");
        $stmt->execute([$key, $dateStart, $dateEnd, $text, $provider]);
    }
}