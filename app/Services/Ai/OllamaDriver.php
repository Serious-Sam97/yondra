<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Local Ollama driver. Ollama serves an OpenAI-compatible endpoint at
 * {base}/chat/completions (default http://localhost:11434/v1) and needs no API key.
 * "Available" means a base URL is configured — if the daemon is down, the outbound
 * call fails during streaming and surfaces as an `ai.error` frame.
 */
class OllamaDriver extends OpenAiCompatibleDriver
{
    public function isAvailable(): bool
    {
        return (bool) config('services.ai.ollama.base_url');
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('services.ai.ollama.base_url'), '/').'/chat/completions';
    }

    protected function headers(): array
    {
        // No auth — Ollama ignores it. Content-type is all the OpenAI-style call needs.
        return ['content-type' => 'application/json'];
    }

    protected function model(): string
    {
        return (string) config('services.ai.ollama.model');
    }

    /**
     * Thinking models (e.g. Qwen3.5) otherwise burn hundreds of hidden reasoning tokens
     * before answering — several seconds of latency per call at local tok/s. Passing
     * `reasoning_effort: none` turns that off and drops a card summary from ~14s to ~1s.
     * Configurable via OLLAMA_REASONING_EFFORT; set it empty to leave thinking on.
     */
    protected function extraPayload(): array
    {
        $effort = (string) config('services.ai.ollama.reasoning_effort', 'none');

        return $effort === '' ? [] : ['reasoning_effort' => $effort];
    }
}
