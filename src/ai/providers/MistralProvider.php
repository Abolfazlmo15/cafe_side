<?php
// src/ai/providers/MistralProvider.php
// =============================================================
// Mistral — free experimental tier. OpenAI-compatible API.
//
// Model selection is overridden: the default Mistral catalog
// returns `codestral-*` first (a code-completion model), which
// technically works but reads like a programmer, not an analyst.
// We force the chat-tuned family instead.

require_once __DIR__ . '/OpenAICompatProvider.php';

class MistralProvider extends OpenAICompatProvider
{
    public function getName(): string { return 'mistral'; }

    protected function getBaseUrl(): string { return 'https://api.mistral.ai/v1'; }
    protected function getModelsEndpoint(): string { return '/models'; }
    protected function getChatEndpoint(): string { return '/chat/completions'; }

    // Every Mistral model is usable within the free tier's quota.
    protected function isFreeModel(array $model): bool { return true; }

    /**
     * Force a chat-tuned model. Priority:
     *   1. options['model'] if explicitly given
     *   2. First preferred model that exists in the live catalog
     *   3. First non-codestral model in the catalog
     */
    protected function selectModel(array $options = []): ?string
    {
        if (!empty($options['model'])) {
            return (string) $options['model'];
        }

        // Chat-tuned models, most capable first.
        $preferred = [
            'mistral-small-latest',
            'mistral-medium-latest',
            'open-mistral-7b',
            'open-mixtral-8x7b',
            'mistral-tiny',
        ];

        $catalog = $this->listModels();
        $ids = [];
        foreach ($catalog as $m) {
            if (!empty($m['id'])) $ids[] = (string) $m['id'];
        }

        foreach ($preferred as $p) {
            if (in_array($p, $ids, true)) return $p;
        }

        // Skip codestral and anything code-oriented.
        foreach ($ids as $id) {
            if (stripos($id, 'codestral') !== false) continue;
            if (stripos($id, 'embed') !== false) continue;
            return $id;
        }

        return parent::selectModel($options);
    }
}