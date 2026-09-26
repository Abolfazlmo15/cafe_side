<?php
// src/ai/providers/OpenRouterProvider.php
// =============================================================
// Concrete provider for OpenRouter (openrouter.ai).
//
// Phase 7 — parser hardening:
//   OpenRouter sometimes returns HTTP 200 with:
//     - a top-level {"error": {...}} body
//     - or an "error" field alongside an empty choices[] array
//     - or a valid envelope with a null content field
//   The previous parser only checked choices[0].message.content and
//   declared "malformed" for all of those. Now it reports exactly
//   what went wrong, with a truncated body dump so the operator can
//   see the real cause.

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/../utils/HttpClient.php';

class OpenRouterProvider extends ProviderBase
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    private const NON_CHAT_PATTERNS = [
        'guard',
        'moderation',
        'safety',
        'whisper',
        'tts',
        'embed',
        'rerank',
        'clip',
        'stable-diffusion',
        'flux',
        'sdxl',
        'playground-v',
        'dall-e',
        'vision-preview',
        'codestral',
        'coder',
        '-code',
        '/code',
        'code-',
        'codellama',
        'fim',
        'roleplay',
        'uncensored',
        'nsfw',
        'hermes-2-pro',
    ];

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

    public function getName(): string { return 'openrouter'; }

    public function isConfigured(): bool
    {
        return strlen($this->getApiKey()) > 10;
    }

    public function setForceRelay(bool $force): void
    {
        $this->http->setForceRelay($force);
    }

    private function isChatModel(string $id): bool
    {
        $id = strtolower($id);
        if ($id === '') return false;
        foreach (self::NON_CHAT_PATTERNS as $pattern) {
            if (strpos($id, $pattern) !== false) return false;
        }
        return true;
    }

    public function listModels(): array
    {
        $resp = $this->http->get(self::BASE_URL . '/models', $this->authHeaders());
        if (!$resp['ok']) return [];

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return [];
        }

        $out = [];
        foreach ($json['data'] as $m) {
            if (!is_array($m) || empty($m['id'])) continue;
            $id = (string) $m['id'];
            if (!$this->isChatModel($id)) continue;

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

    protected function selectModel(array $options = []): ?string
    {
        if (!empty($options['model'])) return (string) $options['model'];

        $models = $this->listModels();
        $byId = [];
        foreach ($models as $m) {
            if (!empty($m['id'])) $byId[$m['id']] = $m;
        }

        foreach (self::PREFERRED_FREE_MODELS as $pref) {
            if (isset($byId[$pref]) && !empty($byId[$pref]['is_free'])) return $pref;
        }
        foreach ($models as $m) {
            if (!empty($m['is_free']) && !empty($m['id'])) return (string) $m['id'];
        }
        foreach ($models as $m) {
            if (!empty($m['id'])) return (string) $m['id'];
        }
        return null;
    }

    public function chat(array $messages, ?string $model = null, array $options = []): array
    {
        if (!$this->isConfigured()) return $this->failure('API key not configured');
        if (empty($messages)) return $this->failure('No messages provided');

        $model = $model ?? $this->selectModel($options);
        if ($model === null) return $this->failure('No free chat model available');

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 800),
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ];
        if (!empty($options['stop'])) $payload['stop'] = $options['stop'];

        $headers = array_merge($this->authHeaders(), [
            'Content-Type: application/json',
            'HTTP-Referer: https://cafe-side.gt.tc',
            'X-Title: Cafe Side AI',
        ]);

        $resp = $this->http->post(
            self::BASE_URL . '/chat/completions',
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Transport-level failure (no HTTP response at all)
        if (!$resp['ok'] && $resp['status'] === 0) {
            return $this->failure('OpenRouter transport: ' . ($resp['error'] ?: 'connection failed'));
        }

        $bodySnippet = substr((string) $resp['body'], 0, 400);
        $json = json_decode($resp['body'], true);
        $jsonValid = is_array($json);

        // ── Case 1: top-level error object (any HTTP status) ─────
        if ($jsonValid && isset($json['error'])) {
            $errObj = $json['error'];
            if (is_array($errObj)) {
                $msg  = (string) ($errObj['message'] ?? 'Unknown error');
                $code = (int)    ($errObj['code']    ?? 0);
            } else {
                $msg  = (string) $errObj;
                $code = 0;
            }
            $label = 'OpenRouter error';
            if ($code)              $label .= ' ' . $code;
            elseif ($resp['status']) $label .= ' HTTP ' . $resp['status'];
            return $this->failure($label . ': ' . $msg);
        }

        // ── Case 2: HTTP-level failure without JSON error body ───
        if (!$resp['ok']) {
            return $this->failure('OpenRouter HTTP ' . $resp['status']
                . ($bodySnippet !== '' ? ' — ' . $bodySnippet : ''));
        }

        // ── Case 3: HTTP 200 but body isn't valid JSON ───────────
        if (!$jsonValid) {
            return $this->failure('OpenRouter non-JSON 200 — ' . $bodySnippet);
        }

        // ── Case 4: 200 with valid JSON but missing choices ──────
        if (empty($json['choices']) || !is_array($json['choices'])) {
            return $this->failure('OpenRouter no choices for ' . $model . ' — body: ' . $bodySnippet);
        }

        // ── Case 5: choices[0] exists but content is missing ─────
        $first = $json['choices'][0] ?? null;
        if (!is_array($first)) {
            return $this->failure('OpenRouter choice[0] not an object — body: ' . $bodySnippet);
        }

        if (!isset($first['message']['content'])) {
            // Sometimes the model returns a finish_reason but no content.
            $finish = $first['finish_reason'] ?? 'unknown';
            return $this->failure('OpenRouter choice[0].message.content missing (finish_reason='
                . $finish . ') — body: ' . $bodySnippet);
        }

        $content = $first['message']['content'];
        if (!is_string($content) || trim($content) === '') {
            return $this->failure('OpenRouter returned empty content — body: ' . $bodySnippet);
        }

        return [
            'ok'         => true,
            'text'       => $content,
            'model'      => (string) ($json['model'] ?? $model),
            'provider'   => $this->getName(),
            'tokens_in'  => (int) ($json['usage']['prompt_tokens']     ?? 0),
            'tokens_out' => (int) ($json['usage']['completion_tokens'] ?? 0),
            'latency_ms' => (int) $resp['latency_ms'],
            'error'      => null,
        ];
    }
}