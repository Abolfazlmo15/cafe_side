<?php
// src/ai/providers/OpenAICompatProvider.php
// =============================================================
// Abstract base class for every provider that uses OpenAI's
// request shape. Groq, DeepSeek, Mistral, Together all share this
// — they only differ in their base URL, auth header, and whether
// their model catalog has a "free" flag.
//
// Concrete subclasses must define:
//   getBaseUrl()          – e.g. 'https://api.groq.com/openai/v1'
//   getModelsEndpoint()   – usually '/models'
//   getChatEndpoint()     – usually '/chat/completions'
//   isFreeModel($model)   – how to tell if a model is free

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/../utils/HttpClient.php';

abstract class OpenAICompatProvider extends ProviderBase
{
    protected HttpClient $http;

    /**
     * Model IDs containing any of these substrings are NOT chat
     * models. They're moderation classifiers, speech processors,
     * embedding models, or image generators. Passing them to
     * /chat/completions returns garbage (a score, or an error).
     */
    private const NON_CHAT_PATTERNS = [
        'guard',        // llama-prompt-guard
        'moderation',
        'safety',
        'whisper',      // speech-to-text
        'tts',          // text-to-speech
        'embed',        // embeddings
        'rerank',
        'clip',         // vision
        'stable-diffusion',
        'flux',         // image generation
        'sdxl',
        'playground-v', // image gen variants
    ];

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->http = new HttpClient(30, 10);
    }

    abstract protected function getBaseUrl(): string;
    abstract protected function getModelsEndpoint(): string;
    abstract protected function getChatEndpoint(): string;

    /**
     * Default: a model is free if its id contains ':free'. Subclasses
     * can override for other conventions (Mistral has none; Together
     * uses a separate endpoint; HuggingFace is a different shape and
     * doesn't use this class at all).
     */
    protected function isFreeModel(array $model): bool
    {
        $id = (string) ($model['id'] ?? '');
        return strpos($id, ':free') !== false;
    }

    /**
     * True if the model ID looks like a chat-capable language model.
     * Everything that's not clearly a classifier/audio/embedding/img
     * is treated as chat.
     */
    protected function isChatModel(array $model): bool
    {
        $id = strtolower((string) ($model['id'] ?? ''));
        if ($id === '') return false;

        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            if (strpos($id, $pattern) !== false) {
                return false;
            }
        }
        return true;
    }

    public function isConfigured(): bool
    {
        return strlen($this->getApiKey()) > 10;
    }

    public function listModels(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $resp = $this->http->get(
            $this->getBaseUrl() . $this->getModelsEndpoint(),
            $this->authHeaders()
        );

        if (!$resp['ok']) {
            return [];
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return [];
        }

        $out = [];
        foreach ($json['data'] as $m) {
            if (!is_array($m) || empty($m['id'])) {
                continue;
            }
            $out[] = [
                'id'             => (string) $m['id'],
                'name'           => (string) ($m['name'] ?? $m['id']),
                'is_free'        => $this->isFreeModel($m),
                'context_length' => (int) ($m['context_length'] ?? $m['context_window'] ?? 0),
                'description'    => (string) ($m['description'] ?? ''),
            ];
        }
        return $out;
    }

    public function chat(array $messages, ?string $model = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('API key not configured');
        }

        if (empty($messages) || !is_array($messages)) {
            return $this->failure('No messages provided');
        }

        $model = $model ?? $this->selectModel($options);
        if ($model === null) {
            return $this->failure('No free chat model available');
        }

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 800),
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ];
        if (!empty($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        $headers = array_merge(
            $this->authHeaders(),
            ['Content-Type: application/json']
        );

        $resp = $this->http->post(
            $this->getBaseUrl() . $this->getChatEndpoint(),
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$resp['ok']) {
            $errMsg = $resp['error'] ?: ('HTTP ' . $resp['status']);
            $body = trim($resp['body']);
            if ($body !== '') {
                $errMsg .= ' — ' . substr($body, 0, 200);
            }
            return $this->failure($errMsg);
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
            return $this->failure('Malformed response from ' . $this->getName());
        }

        return [
            'ok'         => true,
            'text'       => (string) $json['choices'][0]['message']['content'],
            'model'      => (string) ($json['model'] ?? $model),
            'provider'   => $this->getName(),
            'tokens_in'  => (int) ($json['usage']['prompt_tokens']     ?? 0),
            'tokens_out' => (int) ($json['usage']['completion_tokens'] ?? 0),
            'latency_ms' => (int) $resp['latency_ms'],
            'error'      => null,
        ];
    }

    protected function selectModel(array $options = []): ?string
    {
        if (!empty($options['model'])) {
            return (string) $options['model'];
        }

        $models = $this->listModels();

        // Prefer free chat models first.
        foreach ($models as $m) {
            if (!empty($m['is_free']) && !empty($m['id']) && $this->isChatModel($m)) {
                return (string) $m['id'];
            }
        }

        // Fall back to any chat model (paid-only catalogs still work
        // within free tiers — Mistral, Together).
        foreach ($models as $m) {
            if (!empty($m['id']) && $this->isChatModel($m)) {
                return (string) $m['id'];
            }
        }

        return null;
    }
}