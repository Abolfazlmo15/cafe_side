<?php
// src/ai/providers/DeepSeekProvider.php
// =============================================================
// DeepSeek — cheap paid inference, borderline free at café scale.
// https://platform.deepseek.com

require_once __DIR__ . '/OpenAICompatProvider.php';

class DeepSeekProvider extends OpenAICompatProvider
{
    public function getName(): string { return 'deepseek'; }
    protected function getBaseUrl(): string { return 'https://api.deepseek.com/v1'; }
    protected function getModelsEndpoint(): string { return '/models'; }
    protected function getChatEndpoint(): string { return '/chat/completions'; }

    // DeepSeek doesn't mark a free tier explicitly — pricing is per
    // token but negligible. Treat everything as usable.
    protected function isFreeModel(array $model): bool { return true; }
}