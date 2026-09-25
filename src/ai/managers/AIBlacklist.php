<?php
// src/ai/managers/AIBlacklist.php
// =============================================================
// Manages the ai_blacklist table. A row means: skip this provider
// until expires_at passes.
//
// Failure classification (hardened in Phase 13.5):
//   - Auth failures      → 60 minutes  (key is wrong, no point retrying soon)
//   - Rate limits        → 60 seconds  (transient, try again shortly)
//   - Network errors     → 90 seconds  (timeout, DNS, connection)
//   - Server 5xx         → 90 seconds
//   - Model-not-found    → 10 minutes  (config issue, worth fixing first)
//   - Unknown            → 5 minutes   (conservative default)

require_once __DIR__ . '/../../database.php';

class AIBlacklist
{
    const TTL_HARD     = 3600;   // auth failures
    const TTL_RATE     = 60;     // rate limits
    const TTL_NETWORK  = 90;     // timeout, DNS, connection
    const TTL_CONFIG   = 600;    // model not found, bad config
    const TTL_DEFAULT  = 300;    // unknown failure

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Classify an error string into a severity + cooldown.
     * Returns ['severity' => string, 'ttl' => int, 'label' => string].
     */
    public function classifyFailure(string $error): array
    {
        $e = strtolower($error);

        // Auth failures — key is wrong, long cooldown
        if (preg_match('/\b(401|403)\b/', $e)
            || strpos($e, 'unauthorized') !== false
            || strpos($e, 'invalid api key') !== false
            || strpos($e, 'invalid_api_key') !== false
            || strpos($e, 'authentication') !== false
            || strpos($e, 'forbidden') !== false) {
            return ['severity' => 'hard', 'ttl' => self::TTL_HARD, 'label' => 'auth'];
        }

        // Rate limits — short cooldown
        if (strpos($e, '429') !== false
            || strpos($e, 'rate limit') !== false
            || strpos($e, 'rate-limit') !== false
            || strpos($e, 'too many request') !== false) {
            return ['severity' => 'rate', 'ttl' => self::TTL_RATE, 'label' => 'rate_limit'];
        }

        // Transient network errors — short cooldown
        if (strpos($e, 'timeout') !== false
            || strpos($e, 'timed out') !== false
            || strpos($e, 'connection') !== false
            || strpos($e, 'could not resolve') !== false
            || strpos($e, 'dns') !== false
            || strpos($e, 'network') !== false
            || strpos($e, 'ssl') !== false) {
            return ['severity' => 'soft', 'ttl' => self::TTL_NETWORK, 'label' => 'network'];
        }

        // Server errors — soft
        if (preg_match('/\b50\d\b/', $e)
            || strpos($e, 'internal server error') !== false
            || strpos($e, 'service unavailable') !== false
            || strpos($e, 'bad gateway') !== false) {
            return ['severity' => 'soft', 'ttl' => self::TTL_NETWORK, 'label' => 'server'];
        }

        // Model config problems
        if (strpos($e, 'model') !== false &&
            (strpos($e, 'not found') !== false || strpos($e, 'unknown') !== false)) {
            return ['severity' => 'config', 'ttl' => self::TTL_CONFIG, 'label' => 'model'];
        }

        return ['severity' => 'unknown', 'ttl' => self::TTL_DEFAULT, 'label' => 'unknown'];
    }

    /**
     * Mark a provider as failed. Classification decides the TTL.
     * $ttlMinutes is ignored unless explicitly passed (override).
     */
    public function markFailed(string $provider, string $reason, ?int $ttlSeconds = null): void
    {
        $c = $ttlSeconds !== null
            ? ['severity' => 'override', 'ttl' => $ttlSeconds, 'label' => 'override']
            : $this->classifyFailure($reason);

        // Only replace if the new cooldown is longer than what's there.
        // This prevents a soft 90s failure from shortening a hard 1h ban.
        $existing = $this->getCurrent($provider);
        if ($existing) {
            $remaining = strtotime($existing['expires_at']) - time();
            if ($remaining >= $c['ttl']) {
                // Existing ban is longer. Leave it alone.
                return;
            }
        }

        $this->clearProvider($provider);
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_blacklist (provider, reason, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $reasonLabel = $c['label'] . ': ' . substr($reason, 0, 220);
        $stmt->execute([$provider, $reasonLabel, $c['ttl']]);
    }

    public function isBlacklisted(string $provider): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ai_blacklist WHERE provider = ? AND expires_at > NOW()");
        $stmt->execute([$provider]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function getCurrent(string $provider): ?array
    {
        $stmt = $this->pdo->prepare("SELECT provider, reason, expires_at FROM ai_blacklist WHERE provider = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getActive(): array
    {
        $stmt = $this->pdo->query("SELECT provider, reason, expires_at FROM ai_blacklist WHERE expires_at > NOW() ORDER BY provider");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearProvider(string $provider): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM ai_blacklist WHERE provider = ?");
        $stmt->execute([$provider]);
    }

    public function pruneExpired(): int
    {
        $stmt = $this->pdo->query("DELETE FROM ai_blacklist WHERE expires_at <= NOW()");
        return $stmt->rowCount();
    }

    public function clearAll(): int
    {
        $stmt = $this->pdo->query("DELETE FROM ai_blacklist");
        return $stmt->rowCount();
    }
}