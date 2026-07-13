<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Shared behaviour for providers that speak the OpenAI chat-completions wire format
 * over an SSE stream — the de-facto standard that Groq and a local Ollama both
 * expose. Only the request body and delta shape are common here; a concrete driver
 * still supplies its endpoint, auth headers, model, and availability check.
 *
 * Sits between SseAiDriver (transport) and the concrete providers, the same way
 * CloudApiDriver sits between the transport and MetaCloudDriver/BspDriver.
 */
abstract class OpenAiCompatibleDriver extends SseAiDriver
{
    protected function payload(string $system, array $messages, int $maxTokens): array
    {
        // OpenAI chat format carries the system prompt as the first message (there is no
        // top-level `system` field like Anthropic).
        return [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'stream' => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $system]],
                $messages,
            ),
        ];
    }

    protected function extractDelta(array $event): ?string
    {
        // { "choices": [ { "delta": { "content": "..." } } ] }
        $delta = $event['choices'][0]['delta']['content'] ?? null;

        return is_string($delta) ? $delta : null;
    }

    /** The model id to request (provider-specific config + env override). */
    abstract protected function model(): string;
}
