<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Shared behaviour for providers that speak the OpenAI chat-completions wire format —
 * the de-facto standard that Groq and a local Ollama both expose. Only the request body
 * and response/delta shape are common here; a concrete driver still supplies its
 * endpoint, auth headers, model, and availability check.
 *
 * Sits between SseAiDriver (transport) and the concrete providers, the same way
 * CloudApiDriver sits between the transport and MetaCloudDriver/BspDriver.
 */
abstract class OpenAiCompatibleDriver extends SseAiDriver
{
    protected function payload(string $system, array $messages, int $maxTokens, bool $stream, bool $json): array
    {
        // OpenAI chat format carries the system prompt as the first message (there is no
        // top-level `system` field like Anthropic).
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $system]],
                $messages,
            ),
        ];
        if ($stream) {
            $payload['stream'] = true;
        }
        if ($json) {
            // OpenAI-compatible JSON mode (Groq + recent Ollama). The prompt must also
            // mention JSON, which our structured prompts always do.
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return array_merge($payload, $this->extraPayload());
    }

    /**
     * Provider-specific extra body fields (e.g. reasoning controls). Base returns none;
     * concrete drivers override to inject params their endpoint understands.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(): array
    {
        return [];
    }

    protected function extractDelta(array $event): ?string
    {
        // { "choices": [ { "delta": { "content": "..." } } ] }
        $delta = $event['choices'][0]['delta']['content'] ?? null;

        return is_string($delta) ? $delta : null;
    }

    protected function extractText(array $response): string
    {
        // { "choices": [ { "message": { "content": "..." } } ] }
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }

    /** The model id to request (provider-specific config + env override). */
    abstract protected function model(): string;
}
