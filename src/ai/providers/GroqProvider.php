<?php
// src/ai/providers/GroqProvider.php
// =============================================================
// Groq — extremely fast inference on Llama, Mixtral, Gemma, and
// others. OpenAI-compatible endpoint. Free tier is generous.
// https://console.groq.com/keys

require_once __DIR__ . '/OpenAICompatProvider.php';

class GroqProvider extends OpenAICompatProvider
{
    public function getName(): string { return 'groq'; }
    protected function getBaseUrl(): string { return 'https://api.groq.com/openai/v1'; }
    protected function getModelsEndpoint(): string { return '/models'; }
    protected function getChatEndpoint(): string { return '/chat/completions'; }

    // Groq's catalog doesn't mark models free — they all are, within
    // the free tier. Everything is usable.
    protected function isFreeModel(array $model): bool { return true; }
}