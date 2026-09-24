<?php
// src/ai/providers/MistralProvider.php
// =============================================================
// Mistral — free experimental tier available. OpenAI-compatible.
// https://console.mistral.ai

require_once __DIR__ . '/OpenAICompatProvider.php';

class MistralProvider extends OpenAICompatProvider
{
    public function getName(): string { return 'mistral'; }
    protected function getBaseUrl(): string { return 'https://api.mistral.ai/v1'; }
    protected function getModelsEndpoint(): string { return '/models'; }
    protected function getChatEndpoint(): string { return '/chat/completions'; }

    // Mistral's free tier is limited but not flagged per-model. Treat
    // all as usable — the rate limiter in Phase 4 handles the rest.
    protected function isFreeModel(array $model): bool { return true; }
}