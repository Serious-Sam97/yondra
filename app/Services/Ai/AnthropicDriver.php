<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Anthropic (Claude) Messages API driver. Speaks the `/v1/messages` API — SSE
 * `content_block_delta` frames when streaming, a `content` array when not. All provider
 * knowledge lives here; the transports are inherited from SseAiDriver.
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

    protected function payload(string $system, array $messages, int $maxTokens, bool $stream, bool $json): array
    {
        $payload = [
            'model' => config('services.ai.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($stream) {
            $payload['stream'] = true;
        }
        // Anthropic has no bare "json_object" mode; the prompt requests the exact shape
        // and the caller parses. $json is intentionally a no-op here.

        return $payload;
    }

    protected function extractDelta(array $event): ?string
    {
        if (($event['type'] ?? null) !== 'content_block_delta'
            || ($event['delta']['type'] ?? null) !== 'text_delta') {
            return null;
        }

        return (string) ($event['delta']['text'] ?? '');
    }

    protected function extractText(array $response): string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }
}
