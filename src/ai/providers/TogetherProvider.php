<?php
// src/ai/providers/TogetherProvider.php
// =============================================================
// Together AI — OpenAI-compatible. $25 signup credit, several
// free-tier models.
// https://api.together.xyz

require_once __DIR__ . '/OpenAICompatProvider.php';

class TogetherProvider extends OpenAICompatProvider
{
    public function getName(): string { return 'together'; }
    protected function getBaseUrl(): string { return 'https://api.together.xyz/v1'; }
    protected function getModelsEndpoint(): string { return '/models'; }
    protected function getChatEndpoint(): string { return '/chat/completions'; }

    protected function isFreeModel(array $model): bool
    {
        $id = (string) ($model['id'] ?? '');
        return strpos($id, ':free') !== false;
    }
}