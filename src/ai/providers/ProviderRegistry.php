<?php
// src/ai/providers/ProviderRegistry.php
// =============================================================
// Single entry point for AI calls.
//
// Hardened in Phase 13.5:
//   - Panic mode: if no non-blacklisted providers exist, retry the
//     least-recently-failed one. This breaks the deadlock where
//     rapid retries keep extending blacklists past the TTL.
//   - Uses classification-aware markFailed() from AIBlacklist.
//   - Exposes getStatusReport() with blacklist details for diagnostics.

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/OpenRouterProvider.php';
require_once __DIR__ . '/GroqProvider.php';
require_once __DIR__ . '/DeepSeekProvider.php';
require_once __DIR__ . '/MistralProvider.php';
require_once __DIR__ . '/TogetherProvider.php';
require_once __DIR__ . '/HuggingFaceProvider.php';
require_once __DIR__ . '/../managers/AIBlacklist.php';
require_once __DIR__ . '/../managers/AIModelCache.php';

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
            $val = (string) ($stmt->fetchColumn() ?: 'openrouter,groq,deepseek,mistral,together,huggingface');
        } catch (PDOException $e) {
            $val = 'openrouter,groq,deepseek,mistral,together,huggingface';
        }
        return array_values(array_filter(array_map('trim', explode(',', $val))));
    }

    /**
     * Called when $this->order is empty — pick the provider whose
     * blacklist entry expires soonest. It's the least-bad option.
     */
    private function pickLeastBadProvider(): ?string
    {
        $candidates = [];
        foreach ($this->providers as $name => $p) {
            if (!$p->isConfigured()) continue;
            $entry = $this->blacklist->getCurrent($name);
            if (!$entry) {
                // Not blacklisted but somehow not in order — use it.
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

        // Panic mode — break the deadlock.
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

        foreach ($chain as $name) {
            $provider = $this->providers[$name];

            $model = $options['model'] ?? null;
            if ($model === null) {
                $model = $this->cache->pickDefaultModel($name);
            }

            $result = $provider->chat($messages, $model, $options);

            if (!empty($result['ok'])) {
                // Success clears the blacklist for this provider.
                $this->blacklist->clearProvider($name);
                return $result;
            }

            $errMsg = (string) ($result['error'] ?? 'Unknown failure');
            $errors[] = $name . ': ' . $errMsg;

            // Classification-aware blacklisting (handled inside markFailed).
            $this->blacklist->markFailed($name, $errMsg);
        }

        return [
            'ok' => false, 'text' => '', 'model' => null, 'provider' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'error' => 'All providers failed — ' . implode(' | ', $errors),
        ];
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