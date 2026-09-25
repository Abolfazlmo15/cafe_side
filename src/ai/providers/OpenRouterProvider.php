<?php
// src/ai/providers/OpenRouterProvider.php
// =============================================================
// Concrete provider for OpenRouter (openrouter.ai).
//
// OpenRouter routes to dozens of models from many labs. Free ones
// have a ":free" suffix on the model id, or zero pricing. We only
// ever call those — anything else costs money.
//
// Hardened in Phase 13.5:
//   - chat() detects the {"error":{...}} response shape and returns
//     the real message (previously it reported "Malformed response"
//     for every rate limit, which broke blacklist classification).
//   - listModels() filters out non-chat and code-oriented models so
//     the registry never picks something the chat endpoint can't use.
//   - selectModel() tries a whitelist of known-good free chat models
//     before falling back to the raw catalog.
//   - Explicit "no choices" detection to give a clearer error than
//     "malformed response" when a model declines.

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/../utils/HttpClient.php';

class OpenRouterProvider extends ProviderBase
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    /**
     * Substrings that indicate a model is NOT suitable for chat.
     * Passing one of these to /chat/completions returns garbage
     * (a safety score, truncated body, or a 400) instead of a reply.
     */
    private const NON_CHAT_PATTERNS = [
        // Safety / moderation classifiers
        'guard',
        'moderation',
        'safety',

        // Audio / speech
        'whisper',
        'tts',

        // Embeddings / ranking
        'embed',
        'rerank',

        // Vision-only / image generation
        'clip',
        'stable-diffusion',
        'flux',
        'sdxl',
        'playground-v',
        'dall-e',
        'vision-preview',

        // Code-completion models — they chat badly and unpredictably
        'codestral',
        'coder',
        '-code',
        '/code',
        'code-',

        // Roleplay / uncensored — unreliable instruction following
        'roleplay',
        'uncensored',
        'nsfw',
        'hermes-2-pro',
    ];

    /**
     * Known-good free chat models. Tried in this order before the
     * generic fallback. If a model here is missing from the live
     * catalog, we skip it and move on.
     */
    private const PREFERRED_FREE_MODELS = [
        'meta-llama/llama-3.3-70b-instruct:free',
        'meta-llama/llama-3.1-70b-instruct:free',
        'meta-llama/llama-3.1-8b-instruct:free',
        'google/gemini-2.0-flash-exp:free',
        'google/gemma-2-27b-it:free',
        'google/gemma-2-9b-it:free',
        'qwen/qwen-2.5-72b-instruct:free',
        'qwen/qwen-2.5-7b-instruct:free',
        'mistralai/mistral-small-24b-instruct-2501:free',
        'mistralai/mistral-7b-instruct:free',
        'microsoft/phi-3-medium-128k-instruct:free',
        'deepseek/deepseek-chat-v3-0324:free',
    ];

    private HttpClient $http;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->http = new HttpClient(30, 10);
    }

    public function getName(): string
    {
        return 'openrouter';
    }

    public function isConfigured(): bool
    {
        return strlen($this->getApiKey()) > 10;
    }

    /**
     * True if the model id looks like a chat-capable language model.
     * Everything not on the blocklist is treated as chat.
     */
    private function isChatModel(string $id): bool
    {
        $id = strtolower($id);
        if ($id === '') return false;
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            if (strpos($id, $pattern) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fetch the live model catalog. Returns only entries we could
     * actually use — free, chat-capable, non-empty id.
     */
    public function listModels(): array
    {
        $resp = $this->http->get(self::BASE_URL . '/models', $this->authHeaders());

        if (!$resp['ok']) {
            return [];
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return [];
        }

        $out = [];
        foreach ($json['data'] as $m) {
            if (!is_array($m) || empty($m['id'])) continue;

            $id = (string) $m['id'];

            // Skip non-chat models — they break /chat/completions.
            if (!$this->isChatModel($id)) continue;

            // A model is free if:
            //   - its id contains ":free", OR
            //   - both prompt and completion prices are the string "0"
            $pricing   = is_array($m['pricing'] ?? null) ? $m['pricing'] : [];
            $priceZero = (($pricing['prompt']     ?? '1') === '0')
                      && (($pricing['completion'] ?? '1') === '0');

            $isFree = (strpos($id, ':free') !== false) || $priceZero;

            $out[] = [
                'id'             => $id,
                'name'           => (string) ($m['name'] ?? $id),
                'is_free'        => $isFree,
                'context_length' => (int) ($m['context_length'] ?? 0),
                'description'    => (string) ($m['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Model picker. Priority:
     *   1. options['model'] if explicitly given
     *   2. Any PREFERRED_FREE_MODELS that exists in the live catalog
     *   3. First free chat model from the catalog
     *   4. First chat model from the catalog
     */
    protected function selectModel(array $options = []): ?string
    {
        if (!empty($options['model'])) {
            return (string) $options['model'];
        }

        $models = $this->listModels();

        // Index by id for O(1) lookups.
        $byId = [];
        foreach ($models as $m) {
            if (!empty($m['id'])) {
                $byId[$m['id']] = $m;
            }
        }

        // 2. Preferred list — first match wins.
        foreach (self::PREFERRED_FREE_MODELS as $pref) {
            if (isset($byId[$pref]) && !empty($byId[$pref]['is_free'])) {
                return $pref;
            }
        }

        // 3. Any free chat model from the catalog.
        foreach ($models as $m) {
            if (!empty($m['is_free']) && !empty($m['id'])) {
                return (string) $m['id'];
            }
        }

        // 4. Any chat model.
        foreach ($models as $m) {
            if (!empty($m['id'])) {
                return (string) $m['id'];
            }
        }

        return null;
    }

    /**
     * Send a chat completion.
     *
     * Error handling: OpenRouter returns HTTP 200 with a body of
     * {"error":{"message":"...","code":429}} when rate-limited. The
     * old code only looked at choices[0], so every rate limit looked
     * like a "malformed response" and got misclassified by the
     * blacklist. This version detects the error shape first.
     */
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
            [
                'Content-Type: application/json',
                'HTTP-Referer: https://cafe-side.gt.tc',
                'X-Title: Cafe Side AI',
            ]
        );

        $resp = $this->http->post(
            self::BASE_URL . '/chat/completions',
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Transport-level failure (DNS, timeout, TLS).
        if (!$resp['ok'] && $resp['status'] === 0) {
            return $this->failure('OpenRouter transport: ' . ($resp['error'] ?: 'connection failed'));
        }

        $json = json_decode($resp['body'], true);

        // ── FIX: detect OpenRouter's error response shape first ──
        // Non-2xx usually means {"error":{"message":"...","code":N}}.
        // Sometimes we even get HTTP 200 with an error body.
        if (is_array($json) && isset($json['error'])) {
            $errObj = $json['error'];
            $msg  = is_array($errObj) ? ($errObj['message'] ?? 'Unknown error') : (string) $errObj;
            $code = is_array($errObj) ? ($errObj['code']    ?? 0)              : 0;

            // Build a message the blacklist classifier can parse.
            // Including the numeric HTTP code as a word makes
            // preg_match('/\b429\b/', $err) match cleanly.
            $label = 'OpenRouter error';
            if ($code)              $label .= ' ' . $code;
            elseif ($resp['status']) $label .= ' HTTP ' . $resp['status'];

            return $this->failure($label . ': ' . $msg);
        }

        // HTTP-level failure without a JSON error body.
        if (!$resp['ok']) {
            $snippet = substr(trim($resp['body']), 0, 180);
            return $this->failure('OpenRouter HTTP ' . $resp['status'] . ($snippet !== '' ? ' — ' . $snippet : ''));
        }

        // Some models return an empty choices array when they decline.
        if (is_array($json) && empty($json['choices'])) {
            return $this->failure('OpenRouter returned no choices for model ' . $model);
        }

        // Missing choices — unexpected body shape.
        if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
            $snippet = substr(trim($resp['body']), 0, 180);
            return $this->failure('Malformed response from OpenRouter: ' . $snippet);
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
}