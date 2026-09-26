<?php
// src/ai/chat/ResponseNarrator.php
// =============================================================
// Step 3 of Phase 8. Takes the structured output of IntentHandler
// and asks the LLM for 2-3 sentences of prose about it.
//
// The LLM never sees SQL. Every number it can cite comes from the
// data block the prompt builder passes it.

require_once __DIR__ . '/../providers/ProviderRegistry.php';
require_once __DIR__ . '/../managers/AIRateLimiter.php';
require_once __DIR__ . '/ChatPromptLibrary.php';

class ResponseNarrator
{
    private ProviderRegistry $registry;
    private AIRateLimiter $limiter;

    public function __construct(PDO $pdo)
    {
        $this->registry = new ProviderRegistry($pdo);
        $this->limiter  = new AIRateLimiter($pdo);
    }

    /**
     * @param string $question       The user's original question
     * @param array  $handlerResult  The output of IntentHandler::handle()
     * @return array{ok: bool, text?: string, provider?: ?string, model?: ?string, error?: string}
     */
    public function narrate(string $question, array $handlerResult): array
    {
        // Unknown intent doesn't need an AI call — return the hint directly
        if (($handlerResult['intent'] ?? '') === 'unknown') {
            return [
                'ok'       => true,
                'text'     => $handlerResult['message']
                            ?? 'I can only answer questions about your café sales data.',
                'provider' => null,
                'model'    => null,
                'skipped'  => 'unknown_intent',
            ];
        }

        if (!$this->limiter->allow()) {
            return [
                'ok'    => false,
                'error' => 'Hourly AI rate limit reached. Try again in a few minutes.',
            ];
        }

        $messages = ChatPromptLibrary::buildNarratorMessages($question, $handlerResult);

        $result = $this->registry->chat($messages, [
            'max_tokens'  => 400,
            'temperature' => 0.4,
        ]);

        $this->limiter->log('chat_narrate', $result);

        if (empty($result['ok'])) {
            return [
                'ok'    => false,
                'error' => $result['error'] ?? 'All AI providers failed',
            ];
        }

        $text = trim((string) $result['text']);

        // Strip any accidental code fences or stray markdown
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        if ($text === '') {
            return [
                'ok'    => false,
                'error' => 'Narrator returned empty text',
            ];
        }

        return [
            'ok'       => true,
            'text'     => $text,
            'provider' => $result['provider'] ?? null,
            'model'    => $result['model']    ?? null,
        ];
    }
}