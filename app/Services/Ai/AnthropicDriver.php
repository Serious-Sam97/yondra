<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Anthropic (Claude) Messages API driver. Speaks the `/v1/messages` SSE stream and
 * reads text out of `content_block_delta` frames. All provider knowledge lives here;
 * the streaming loop is inherited from SseAiDriver.
 */
class AnthropicDriver extends SseAiDriver
{
    public function isAvailable(): bool
    {
        return (bool) config('services.ai.anthropic.api_key');
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('services.ai.anthropic.base_url'), '/').'/v1/messages';
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => (string) config('services.ai.anthropic.api_key'),
            'anthropic-version' => (string) config('services.ai.anthropic.version'),
            'content-type' => 'application/json',
        ];
    }

    protected function payload(string $system, array $messages, int $maxTokens): array
    {
        return [
            'model' => config('services.ai.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'stream' => true,
        ];
    }

    protected function extractDelta(array $event): ?string
    {
        if (($event['type'] ?? null) !== 'content_block_delta'
            || ($event['delta']['type'] ?? null) !== 'text_delta') {
            return null;
        }

        return (string) ($event['delta']['text'] ?? '');
    }
}
