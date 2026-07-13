<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Groq driver (groq.com — LPU inference for open models like Llama). Groq exposes an
 * OpenAI-compatible chat-completions API at https://api.groq.com/openai/v1 with Bearer
 * auth, so it supplies only endpoint, auth, and model — the request/stream format is
 * inherited from OpenAiCompatibleDriver.
 */
class GroqDriver extends OpenAiCompatibleDriver
{
    public function isAvailable(): bool
    {
        return (bool) config('services.ai.groq.api_key');
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('services.ai.groq.base_url'), '/').'/chat/completions';
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.config('services.ai.groq.api_key'),
            'content-type' => 'application/json',
        ];
    }

    protected function model(): string
    {
        return (string) config('services.ai.groq.model');
    }
}
