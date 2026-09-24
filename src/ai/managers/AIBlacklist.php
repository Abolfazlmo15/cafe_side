<?php
// src/ai/managers/AIBlacklist.php
// =============================================================
// Manages the ai_blacklist table. A row means: this provider
// failed recently — skip it until <expires_at> passes.

require_once __DIR__ . '/../../database.php';

class AIBlacklist
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mark a provider as failed. Inserts a row that lives until
     * now + $ttlMinutes. If a row already exists for this provider,
     * replace it (extend the blacklist).
     */
    public function markFailed(string $provider, string $reason, int $ttlMinutes = 15): void
    {
        $this->clearProvider($provider);
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_blacklist (provider, reason, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ");
        $stmt->execute([$provider, substr($reason, 0, 250), $ttlMinutes]);
    }

    /**
     * True if the provider is currently blacklisted.
     */
    public function isBlacklisted(string $provider): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM ai_blacklist
            WHERE provider = ? AND expires_at > NOW()
        ");
        $stmt->execute([$provider]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Returns all currently-blacklisted provider names.
     */
    public function getActive(): array
    {
        $stmt = $this->pdo->query("
            SELECT provider, reason, expires_at FROM ai_blacklist
            WHERE expires_at > NOW()
            ORDER BY provider
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove any blacklist rows for a specific provider.
     */
    public function clearProvider(string $provider): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM ai_blacklist WHERE provider = ?");
        $stmt->execute([$provider]);
    }

    /**
     * Delete expired rows. Called by cron and opportunistically on
     * every registry call.
     */
    public function pruneExpired(): int
    {
        $stmt = $this->pdo->query("DELETE FROM ai_blacklist WHERE expires_at <= NOW()");
        return $stmt->rowCount();
    }

    /**
     * Delete every blacklist row, regardless of expiry.
     *
     * Used by the diagnostic tool during development — after a
     * series of failed attempts you want to see which providers
     * actually work right now, not which ones were marked dead
     * ten minutes ago.
     */
    public function clearAll(): int
    {
        $stmt = $this->pdo->query("DELETE FROM ai_blacklist");
        return $stmt->rowCount();
    }
}