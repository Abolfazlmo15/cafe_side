<?php
// src/ai/managers/AIHealthChecker.php
// =============================================================
// Pings each configured provider to confirm reachability.
//
// Uses listModels() instead of chat() because:
//   1. It's free — no tokens consumed.
//   2. It doesn't depend on any model's output shape.
//   3. It proves both network reachability AND API-key validity.
//
// Writes one row per check to ai_health_log. Never throws.

require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/AIBlacklist.php';

class AIHealthChecker
{
    private PDO $pdo;
    private AIBlacklist $blacklist;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->blacklist = new AIBlacklist($pdo);
    }

    /**
     * Ping a single provider by fetching its model list.
     * Returns ['provider', 'status', 'latency_ms', 'error'].
     *   status: 'healthy' | 'unhealthy' | 'unconfigured'
     */
    public function checkProvider(ProviderBase $provider): array
    {
        $name = $provider->getName();

        if (!$provider->isConfigured()) {
            $this->log($name, 'unconfigured', 0, 'Not configured');
            return ['provider' => $name, 'status' => 'unconfigured', 'latency_ms' => 0, 'error' => 'Not configured'];
        }

        $start = microtime(true);
        $models = $provider->listModels();
        $latency = (int) ((microtime(true) - $start) * 1000);

        if (!empty($models) && is_array($models)) {
            $this->log($name, 'healthy', $latency, '');
            // Any successful reachability clears the blacklist for this provider.
            $this->blacklist->clearProvider($name);
            return ['provider' => $name, 'status' => 'healthy', 'latency_ms' => $latency, 'error' => ''];
        }

        // listModels() returned empty — could be auth failure, DNS failure, or timeout.
        $error = 'No models returned (reachability, auth, or DNS failure)';
        $this->log($name, 'unhealthy', $latency, $error);
        $this->blacklist->markFailed($name, $error, 15);

        return ['provider' => $name, 'status' => 'unhealthy', 'latency_ms' => $latency, 'error' => $error];
    }

    public function checkAll(array $providers): array
    {
        $results = [];
        foreach ($providers as $p) {
            try {
                $results[] = $this->checkProvider($p);
            } catch (Throwable $e) {
                $results[] = [
                    'provider'   => $p->getName(),
                    'status'     => 'unhealthy',
                    'latency_ms' => 0,
                    'error'      => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    public function getLatestPerProvider(): array
    {
        $stmt = $this->pdo->query("
            SELECT h.provider, h.status, h.latency_ms, h.error_message, h.checked_at
            FROM ai_health_log h
            INNER JOIN (
                SELECT provider, MAX(id) AS max_id
                FROM ai_health_log
                GROUP BY provider
            ) latest ON latest.max_id = h.id
            ORDER BY h.provider
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pruneOldLogs(): int
    {
        $stmt = $this->pdo->query("
            DELETE FROM ai_health_log
            WHERE checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        return $stmt->rowCount();
    }

    private function log(string $provider, string $status, int $latency, string $error): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_health_log (provider, status, latency_ms, error_message)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$provider, $status, $latency, substr($error, 0, 500)]);
        } catch (PDOException $e) {
            error_log('AIHealthChecker::log failed: ' . $e->getMessage());
        }
    }
}