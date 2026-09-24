<?php
// src/ai/providers/HuggingFaceProvider.php
// =============================================================
// HuggingFace Inference API — different request shape from OpenAI.
// We adapt it to the same return structure as every other provider.
//
// The API takes:
//   POST /models/{model_id} with body {"inputs": "prompt text"}
// and returns either:
//   [{"generated_text": "..."}]   – success
//   {"error": "..."}              – failure (often "loading")
//
// We flatten the messages array into a single "prompt" string for
// the inputs field. Lossy compared to OpenAI, but workable for
// short-form tasks like report narration.

require_once __DIR__ . '/ProviderBase.php';
require_once __DIR__ . '/../utils/HttpClient.php';

class HuggingFaceProvider extends ProviderBase
{
    private const BASE_URL = 'https://api-inference.huggingface.co/models';

    private HttpClient $http;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->http = new HttpClient(60, 10);
    }

    public function getName(): string { return 'huggingface'; }

    public function isConfigured(): bool
    {
        return strlen($this->getApiKey()) > 5;
    }

    public function listModels(): array
    {
        // HuggingFace doesn't offer a global model list via the
        // inference endpoint. We hardcode the small set of models we
        // trust for text generation. Anything else would need the
        // Hub API (huggingface.co/api/models) which is a separate
        // shape and would require pagination.
        return [
            ['id' => 'mistralai/Mistral-7B-Instruct-v0.2',  'name' => 'Mistral 7B Instruct',        'is_free' => true, 'context_length' => 32768, 'description' => ''],
            ['id' => 'HuggingFaceH4/zephyr-7b-beta',        'name' => 'Zephyr 7B Beta',              'is_free' => true, 'context_length' => 32768, 'description' => ''],
            ['id' => 'meta-llama/Llama-3.2-3B-Instruct',    'name' => 'Llama 3.2 3B Instruct',       'is_free' => true, 'context_length' => 131072, 'description' => ''],
        ];
    }

    public function chat(array $messages, ?string $model = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('HuggingFace token not configured');
        }

        if (empty($messages) || !is_array($messages)) {
            return $this->failure('No messages provided');
        }

        $model = $model ?? (!empty($options['model']) ? $options['model'] : 'mistralai/Mistral-7B-Instruct-v0.2');

        // Flatten messages into a single prompt string.
        $parts = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            if ($content === '') continue;
            $parts[] = ucfirst($role) . ': ' . $content;
        }
        $parts[] = 'Assistant:';
        $prompt = implode("\n", $parts);

        $payload = [
            'inputs'     => $prompt,
            'parameters' => [
                'max_new_tokens' => (int) ($options['max_tokens'] ?? 512),
                'temperature'    => (float) ($options['temperature'] ?? 0.7),
                'return_full_text' => false,
            ],
            'options' => [
                'wait_for_model' => true,
                'use_cache'      => true,
            ],
        ];

        $headers = [
            'Authorization: Bearer ' . $this->getApiKey(),
            'Content-Type: application/json',
        ];

        $resp = $this->http->post(
            self::BASE_URL . '/' . $model,
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$resp['ok']) {
            $errMsg = $resp['error'] ?: ('HTTP ' . $resp['status']);
            $body = trim($resp['body']);
            if ($body !== '') $errMsg .= ' — ' . substr($body, 0, 200);
            return $this->failure($errMsg);
        }

        $json = json_decode($resp['body'], true);

        // Two possible success shapes:
        //   [{"generated_text": "..."}]      (default)
        //   "..."                            (some endpoints)
        $text = '';
        if (is_array($json) && isset($json[0]['generated_text'])) {
            $text = (string) $json[0]['generated_text'];
        } elseif (is_string($json)) {
            $text = $json;
        } elseif (is_array($json) && isset($json['error'])) {
            return $this->failure('HuggingFace: ' . $json['error']);
        }

        if ($text === '') {
            return $this->failure('Empty response from HuggingFace');
        }

        return [
            'ok'         => true,
            'text'       => trim($text),
            'model'      => $model,
            'provider'   => $this->getName(),
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'latency_ms' => (int) $resp['latency_ms'],
            'error'      => null,
        ];
    }
}