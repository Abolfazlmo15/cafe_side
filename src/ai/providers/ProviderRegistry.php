<?php
// src/ai/providers/ProviderRegistry.php
// =============================================================
// Single entry point for AI calls.
//
// Phase 7 — Cloudflare Worker relay fallback + fixed isNetworkError():
//   The 403/forbidden check must come BEFORE the generic 403
//   exclusion, otherwise the exclusion swallows it and the relay
//   retry never fires. Groq returns 403 from a Cloudflare edge WAF,
//   not from an auth failure — the tell is the word "forbidden" in
//   the body with no other auth-related text.

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/OpenRouterProvider.php';
require_once __DIR__ . '/GroqProvider.php';
require_once __DIR__ . '/DeepSeekProvider.php';
require_once __DIR__ . '/MistralProvider.php';
require_once __DIR__ . '/TogetherProvider.php';
require_once __DIR__ . '/HuggingFaceProvider.php';
require_once __DIR__ . '/../managers/AIBlacklist.php';
require_once __DIR__ . '/../managers/AIModelCache.php';
require_once __DIR__ . '/../utils/HttpClient.php';

class ProviderRegistry
{
    private PDO $pdo;
    private AIBlacklist $blacklist;
    private AIModelCache $cache;
    private array $providers = [];
    private array $order = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo       = $pdo;
        $this->blacklist = new AIBlacklist($pdo);
        $this->cache     = new AIModelCache($pdo);

        $this->blacklist->pruneExpired();
        $this->buildProviders();
        $this->order = $this->resolveOrder();
    }

    private function buildProviders(): void
    {
        $configs = [
            'openrouter'  => ['api_key' => env('OPENROUTER_API_KEY', '')],
            'groq'        => ['api_key' => env('GROQ_API_KEY', '')],
            'deepseek'    => ['api_key' => env('DEEPSEEK_API_KEY', '')],
            'mistral'     => ['api_key' => env('MISTRAL_API_KEY', '')],
            'together'    => ['api_key' => env('TOGETHER_API_KEY', '')],
            'huggingface' => ['api_key' => env('HUGGINGFACE_TOKEN', '')],
        ];

        $this->providers = [
            'openrouter'  => new OpenRouterProvider($configs['openrouter']),
            'groq'        => new GroqProvider($configs['groq']),
            'deepseek'    => new DeepSeekProvider($configs['deepseek']),
            'mistral'     => new MistralProvider($configs['mistral']),
            'together'    => new TogetherProvider($configs['together']),
            'huggingface' => new HuggingFaceProvider($configs['huggingface']),
        ];
    }

    private function resolveOrder(): array
    {
        $priority = $this->readPrioritySetting();
        $ordered = [];

        foreach ($priority as $name) {
            if (!isset($this->providers[$name])) continue;
            $p = $this->providers[$name];
            if (!$p->isConfigured()) continue;
            if ($this->blacklist->isBlacklisted($name)) continue;
            $ordered[] = $name;
        }

        foreach ($this->providers as $name => $p) {
            if (in_array($name, $ordered, true)) continue;
            if (!$p->isConfigured()) continue;
            if ($this->blacklist->isBlacklisted($name)) continue;
            $ordered[] = $name;
        }

        return $ordered;
    }

    private function readPrioritySetting(): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ai_provider_priority'");
            $stmt->execute();
            $val = (string) ($stmt->fetchColumn() ?: 'openrouter,mistral,groq,huggingface,deepseek,together');
        } catch (PDOException $e) {
            $val = 'openrouter,mistral,groq,huggingface,deepseek,together';
        }
        return array_values(array_filter(array_map('trim', explode(',', $val))));
    }

    private function pickLeastBadProvider(): ?string
    {
        $candidates = [];
        foreach ($this->providers as $name => $p) {
            if (!$p->isConfigured()) continue;
            $entry = $this->blacklist->getCurrent($name);
            if (!$entry) {
                return $name;
            }
            $candidates[$name] = strtotime($entry['expires_at']);
        }
        if (empty($candidates)) return null;
        asort($candidates);
        return array_key_first($candidates);
    }

    public function chat(array $messages, array $options = []): array
    {
        $chain = $this->order;

        if (empty($chain)) {
            $fallback = $this->pickLeastBadProvider();
            if ($fallback === null) {
                return [
                    'ok' => false, 'text' => '', 'model' => null, 'provider' => null,
                    'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
                    'error' => 'No AI providers are configured. Check .env keys on the server.',
                ];
            }
            $chain = [$fallback];
        }

        $errors = [];
        $relayReady = HttpClient::relayAvailable();

        foreach ($chain as $name) {
            $provider = $this->providers[$name];

            $model = $options['model'] ?? null;
            if ($model === null) {
                $model = $this->cache->pickDefaultModel($name);
            }

            // ── Attempt 1: direct ────────────────────────────────
            $provider->setForceRelay(false);
            $result = $provider->chat($messages, $model, $options);

            if (!empty($result['ok'])) {
                $this->blacklist->clearProvider($name);
                return $result;
            }

            $errMsg = (string) ($result['error'] ?? 'Unknown failure');

            // ── Attempt 2: through relay, only on network-level errors ──
            if ($relayReady && $this->isNetworkError($errMsg)) {
                $provider->setForceRelay(true);
                $relayResult = $provider->chat($messages, $model, $options);
                $provider->setForceRelay(false);

                if (!empty($relayResult['ok'])) {
                    error_log("ProviderRegistry: {$name} succeeded via Cloudflare relay");
                    $this->blacklist->clearProvider($name);
                    $relayResult['via_relay'] = true;
                    return $relayResult;
                }

                $errMsg .= ' | relay also failed: ' . ($relayResult['error'] ?? '?');
            }

            $errors[] = $name . ': ' . $errMsg;
            $this->blacklist->markFailed($name, $errMsg);
        }

        return [
            'ok' => false, 'text' => '', 'model' => null, 'provider' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'error' => 'All providers failed — ' . implode(' | ', $errors),
        ];
    }

    /**
     * True if the error looks like a network-layer failure that a
     * relay might bypass.
     *
     * ORDER MATTERS. The 403+forbidden check must run before the
     * generic 403 exclusion below. Otherwise the exclusion swallows
     * it, the relay retry never fires, and Groq stays dead.
     */
    private function isNetworkError(string $error): bool
    {
        $e = strtolower($error);

        // ── Special case FIRST: Cloudflare WAF 403 ───────────────
        // The tell is "403" AND "forbidden" with no other auth word.
        // A genuine auth failure says "invalid api key" or similar,
        // not just "Forbidden".
        if (strpos($e, '403') !== false
            && strpos($e, 'forbidden') !== false) {
            return true;
        }

        // ── Explicitly NOT network errors ────────────────────────
        if (strpos($e, '429') !== false)                  return false;
        if (strpos($e, 'rate limit') !== false)           return false;
        if (strpos($e, '401') !== false)                  return false;
        if (strpos($e, '402') !== false)                  return false; // insufficient balance
        if (strpos($e, '403') !== false)                  return false;
        if (strpos($e, 'unauthorized') !== false)         return false;
        if (strpos($e, 'invalid api key') !== false)      return false;
        if (strpos($e, 'invalid_api_key') !== false)      return false;
        if (strpos($e, 'authentication') !== false)       return false;
        if (strpos($e, 'insufficient balance') !== false)   return false;
        if (strpos($e, 'no free chat model') !== false)     return false;
        if (strpos($e, 'model not found') !== false)      return false;
        if (strpos($e, 'unknown model') !== false)        return false;
        if (strpos($e, 'forbidden') !== false)            return false;

        // ── Network markers ──────────────────────────────────────
        if (strpos($e, 'could not resolve') !== false)     return true;
        if (strpos($e, 'could not connect') !== false)     return true;
        if (strpos($e, 'connection refused') !== false)    return true;
        if (strpos($e, 'connection reset') !== false)      return true;
        if (strpos($e, 'connection timed out') !== false)  return true;
        if (strpos($e, 'timeout') !== false)              return true;
        if (strpos($e, 'timed out') !== false)             return true;
        if (strpos($e, 'ssl') !== false)                  return true;
        if (strpos($e, 'tls') !== false)                  return true;
        if (strpos($e, 'transport') !== false)            return true;
        if (strpos($e, 'network') !== false)              return true;
        if (strpos($e, 'curl') !== false)                 return true;
        if (strpos($e, 'http 0') !== false)               return true;

        return false;
    }

    public function getOrder(): array { return $this->order; }
    public function getAllProviders(): array { return $this->providers; }
    public function getBlacklist(): AIBlacklist { return $this->blacklist; }
    public function getModelCache(): AIModelCache { return $this->cache; }

    public function getStatusReport(): array
    {
        $out = [];
        foreach ($this->providers as $name => $p) {
            $entry = $this->blacklist->getCurrent($name);
            $out[$name] = [
                'configured'        => $p->isConfigured(),
                'blacklisted'       => $entry !== null,
                'blacklist_reason'  => $entry['reason'] ?? null,
                'blacklist_expires' => $entry['expires_at'] ?? null,
                'in_priority_order' => in_array($name, $this->order, true),
            ];
        }
        return $out;
    }
}