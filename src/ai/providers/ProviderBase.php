<?php
// src/ai/providers/ProviderBase.php
// =============================================================
// Abstract base class that every AI provider extends.
//
// A provider is a single class that knows how to talk to ONE
// service (OpenRouter, Groq, etc.). It does not know about the
// registry, blacklist, or rate limits — those are managers that
// wrap providers in later phases.
//
// Required methods:
//   getName()       – the provider's identifier (e.g. "openrouter")
//   listModels()    – return every model the provider currently offers
//   chat()          – send a chat request and return the response
//
// Optional overrides:
//   isConfigured()  – whether an API key is present

abstract class ProviderBase
{
    /** @var array Provider-specific config (api_key, base_url, etc.) */
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    // ---------------------------------------------------------
    // Required methods — every provider implements these.
    // ---------------------------------------------------------

    /**
     * Short identifier for this provider. Used in logs, settings,
     * and the priority list. Always lowercase, no spaces.
     */
    abstract public function getName(): string;

    /**
     * Return an array of models currently offered by this provider.
     *
     * Each entry must have at least:
     *   [
     *     'id'             => 'meta-llama/llama-3.3-70b-instruct:free',
     *     'name'           => 'Llama 3.3 70B (free)',
     *     'is_free'        => true,
     *     'context_length' => 128000,
     *   ]
     *
     * Return an empty array on any failure — the caller decides
     * what to do with that.
     */
    abstract public function listModels(): array;

    /**
     * Send a chat completion request.
     *
     * $messages is OpenAI-shaped:
     *   [
     *     ['role' => 'system', 'content' => '...'],
     *     ['role' => 'user',   'content' => '...'],
     *   ]
     *
     * $model — pass null to let the provider pick a default (usually
     * the first free model it finds). Pass a specific model id to
     * pin the request.
     *
     * $options — max_tokens, temperature, stop.
     *
     * Returns:
     *   [
     *     'ok'         => true,
     *     'text'       => 'the assistant reply',
     *     'model'      => 'meta-llama/llama-3.3-70b-instruct:free',
     *     'provider'   => 'openrouter',
     *     'tokens_in'  => 42,
     *     'tokens_out' => 118,
     *     'latency_ms' => 1340,
     *     'error'      => null,
     *   ]
     */
    abstract public function chat(array $messages, ?string $model = null, array $options = []): array;

    // ---------------------------------------------------------
    // Optional overrides.
    // ---------------------------------------------------------

    /**
     * True if the provider has everything it needs to make requests.
     * Default: an API key must be present. Override for providers
     * that don't need authentication.
     */
    public function isConfigured(): bool
    {
        return !empty($this->getApiKey());
    }

    // ---------------------------------------------------------
    // Shared helpers — used by every concrete provider.
    // ---------------------------------------------------------

    protected function getApiKey(): string
    {
        return (string) $this->getConfig('api_key', '');
    }

    /**
     * Read a value from the provider's config, with a default.
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Standard Bearer auth header. Most providers use this.
     * Override if the provider expects a different scheme.
     */
    protected function authHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->getApiKey()];
    }

    /**
     * Consistent failure response. Every provider returns this shape
     * on any error so callers never have to check provider-specific
     * fields.
     */
    protected function failure(string $reason, array $extra = []): array
    {
        return array_merge([
            'ok'         => false,
            'text'       => '',
            'model'      => null,
            'provider'   => $this->getName(),
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'error'      => $reason,
        ], $extra);
    }

    /**
     * Pick a model to use. Priority:
     *   1. Explicit $options['model']
     *   2. First free model in listModels()
     *   3. null (caller decides what to do)
     *
     * Providers that need different selection logic can override.
     */
    protected function selectModel(array $options = []): ?string
    {
        if (!empty($options['model'])) {
            return (string) $options['model'];
        }

        $models = $this->listModels();
        foreach ($models as $m) {
            if (!empty($m['is_free']) && !empty($m['id'])) {
                return (string) $m['id'];
            }
        }

        return null;
    }

    public function setForceRelay(bool $force): void
    {
        // Optional — overridden by providers that hold an HttpClient.
    }
}