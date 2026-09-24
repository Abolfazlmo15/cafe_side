<?php
// src/ai/providers/OpenRouterProvider.php
// =============================================================
// Concrete provider for OpenRouter (openrouter.ai).
//
// OpenRouter is a routing layer that fronts dozens of models from
// many labs. Many of them are free with a ":free" suffix on the
// model id. We only ever call those — anything without :free
// would cost money.
//
// Public endpoints used:
//   GET  /api/v1/models          – list every model (no auth needed)
//   POST /api/v1/chat/completions – send a chat request

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/../utils/HttpClient.php';

class OpenRouterProvider extends ProviderBase
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

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
     * Fetch the live model catalog and return only the free ones
     * plus some context for each.
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
            if (!is_array($m) || empty($m['id'])) {
                continue;
            }

            $id = (string) $m['id'];

            // A model is free if:
            //   - its id contains ":free", OR
            //   - both prompt and completion prices are the string "0"
            $pricing = is_array($m['pricing'] ?? null) ? $m['pricing'] : [];
            $priceZero = (($pricing['prompt'] ?? '1') === '0')
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
     * Send a chat completion. If $model is null, picks the first
     * free model from the catalog.
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
            return $this->failure('No free model available');
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

        if (!$resp['ok']) {
            $errMsg = $resp['error'] ?: ('HTTP ' . $resp['status']);
            // Include body excerpt for easier debugging
            $body = trim($resp['body']);
            if ($body !== '') {
                $errMsg .= ' — ' . substr($body, 0, 200);
            }
            return $this->failure($errMsg);
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
            return $this->failure('Malformed response from OpenRouter');
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