<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * A per-instance wrapper around a concrete provider driver. The concrete drivers
 * (Anthropic/Groq/Ollama) read their model / base_url / reasoning_effort from the global
 * config('services.ai.<provider>.*') slots — one slot per provider TYPE. That is fine for a
 * single provider, but the Vortex manager now lets an admin register N instances of the same
 * type (e.g. several Ollama models, one instance per model). Each instance needs its own
 * model/base_url without clobbering the others.
 *
 * This decorator carries that instance's non-secret overrides and re-asserts them onto the
 * live config the moment before it delegates any call. Because the chain runs its members
 * strictly sequentially (FallbackAiDriver — including the latency-race load-balance mode), the
 * last bind() before a call always reflects the member about to run, so two instances of the
 * same type never step on each other. API keys are never carried here — they stay in env,
 * shared across instances of a secret provider.
 *
 * Transparent by construction: it applies the overrides and forwards verbatim, so the
 * concrete drivers (and their tests) are untouched.
 */
final class ConfiguredAiDriver implements AiDriver
{
    /**
     * @param  AiDriver  $inner  The concrete provider driver to delegate to.
     * @param  array<string, mixed>  $overrides  config() key => value pairs to assert before each call
     *                                           (e.g. ['services.ai.ollama.model' => 'qwen3.5']).
     */
    public function __construct(private AiDriver $inner, private array $overrides = []) {}

    /** The wrapped concrete driver — for logging/introspection and tests. */
    public function inner(): AiDriver
    {
        return $this->inner;
    }

    public function isAvailable(): bool
    {
        $this->bind();

        return $this->inner->isAvailable();
    }

    public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
    {
        $this->bind();

        return $this->inner->streamChat($system, $messages, $onDelta, $maxTokens);
    }

    public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
    {
        $this->bind();

        return $this->inner->complete($system, $messages, $maxTokens, $json);
    }

    /** Push this instance's knobs onto the live config the concrete driver reads. */
    private function bind(): void
    {
        if ($this->overrides !== []) {
            config($this->overrides);
        }
    }
}
