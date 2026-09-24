<?php
// src/ai/providers/ProviderRegistry.php
// =============================================================
// The single entry point for AI calls.
//
// Loads every provider class, instantiates the ones that are
// configured, orders them by the priority list from settings, and
// exposes chat() that walks the chain.
//
// On any provider failure (except rate-limit), the failing provider
// is added to the blacklist for a TTL. The registry skips
// blacklisted providers on the next call.

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
    private array $providers = [];    // name => ProviderBase
    private array $order = [];        // priority order after blacklist filter

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->blacklist = new AIBlacklist($pdo);
        $this->cache     = new AIModelCache($pdo);

        // Opportunistic cleanup — cheap and keeps the tables tidy.
        $this->blacklist->pruneExpired();

        $this->buildProviders();
        $this->order = $this->resolveOrder();
    }

    // ─────────────────────────────────────────────────────────
    // Provider instantiation
    // ─────────────────────────────────────────────────────────

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

        $instances = [
            'openrouter'  => new OpenRouterProvider($configs['openrouter']),
            'groq'        => new GroqProvider($configs['groq']),
            'deepseek'    => new DeepSeekProvider($configs['deepseek']),
            'mistral'     => new MistralProvider($configs['mistral']),
            'together'    => new TogetherProvider($configs['together']),
            'huggingface' => new HuggingFaceProvider($configs['huggingface']),
        ];

        foreach ($instances as $name => $provider) {
            $this->providers[$name] = $provider;
        }
    }

    // ─────────────────────────────────────────────────────────
    // Priority resolution
    // ─────────────────────────────────────────────────────────

    /**
     * Reads ai_provider_priority from settings, filters to configured
     * and non-blacklisted providers, returns ordered names.
     */
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

        // Append any configured provider not already in the list —
        // catches new providers you add to .env without touching the
        // settings table.
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
            $val = (string) ($stmt->fetchColumn() ?: 'openrouter');
        } catch (PDOException $e) {
            $val = 'openrouter';
        }

        return array_values(array_filter(array_map('trim', explode(',', $val))));
    }

    // ─────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────

    /**
     * Walk the priority chain. Returns the first successful response
     * in the same shape every provider returns.
     */
    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->order)) {
            return [
                'ok'         => false,
                'text'       => '',
                'model'      => null,
                'provider'   => null,
                'tokens_in'  => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
                'error'      => 'No configured providers available',
            ];
        }

        $errors = [];

        foreach ($this->order as $name) {
            $provider = $this->providers[$name];

            // Use the cached default model if the caller didn't pin one.
            $model = $options['model'] ?? null;
            if ($model === null) {
                $model = $this->cache->pickDefaultModel($name);
            }

            $result = $provider->chat($messages, $model, $options);

            if (!empty($result['ok'])) {
                return $result;
            }

            $errMsg = (string) ($result['error'] ?? 'Unknown');
            $errors[] = $name . ': ' . $errMsg;

            // Blacklist unless it's a rate-limit error.
            $lower = strtolower($errMsg);
            if (strpos($lower, '429') === false && strpos($lower, 'rate') === false) {
                $this->blacklist->markFailed($name, $errMsg, 15);
            }
        }

        return [
            'ok'         => false,
            'text'       => '',
            'model'      => null,
            'provider'   => null,
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'error'      => 'All providers failed — ' . implode(' | ', $errors),
        ];
    }

    /**
     * Names in priority order after blacklist filtering.
     */
    public function getOrder(): array
    {
        return $this->order;
    }

    /**
     * Every instantiated provider (configured or not).
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Detailed status per provider for the diagnostic.
     */
    public function getStatusReport(): array
    {
        $out = [];
        foreach ($this->providers as $name => $p) {
            $out[$name] = [
                'configured' => $p->isConfigured(),
                'blacklisted' => $this->blacklist->isBlacklisted($name),
                'in_priority_order' => in_array($name, $this->order, true),
            ];
        }
        return $out;
    }

    public function getBlacklist(): AIBlacklist { return $this->blacklist; }
    public function getModelCache(): AIModelCache { return $this->cache; }
}