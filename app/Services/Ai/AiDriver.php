<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Provider-agnostic contract for every AI (LLM) interaction in the app. Feature code
 * (AiAssistService, controllers, jobs) depends ONLY on this interface — never on a
 * concrete provider. To switch or add a provider, implement this contract (extend
 * SseAiDriver for the shared streaming plumbing) and map it in AppServiceProvider;
 * no calling code changes.
 *
 *  - AnthropicDriver — Claude Messages API (default)
 *  - GroqDriver / OllamaDriver — OpenAI-compatible providers
 *
 * The provider is chosen by config('services.ai.driver').
 */
interface AiDriver
{
    /** Whether this provider is configured and usable (drives the pre-dispatch 503 gate). */
    public function isAvailable(): bool;

    /**
     * Stream a chat completion. Invokes $onDelta($text) for each text chunk as it
     * arrives and returns the fully assembled text. Throws on transport/API failure.
     *
     * @param  string  $system  System prompt (behaviour + guardrails).
     * @param  list<array{role:string,content:string}>  $messages  Conversation turns.
     * @param  callable(string):void  $onDelta  Called once per streamed text chunk.
     */
    public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string;

    /**
     * Non-streaming completion — for short, structured answers (e.g. a story-point
     * estimate as JSON). Returns the full response text. When $json is true the provider
     * is asked to return a JSON object where it supports a native JSON mode; regardless,
     * callers should still prompt for the exact shape and defensively parse.
     *
     * @param  list<array{role:string,content:string}>  $messages
     */
    public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string;
}
