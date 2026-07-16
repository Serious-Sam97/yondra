<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Infrastructure\Models\AiSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The runtime bridge between the DB-backed AI settings row and the drivers. Everything
 * that talks to an LLM reads its knobs from config('services.ai.*') lazily at call time,
 * so instead of touching every driver we resolve the DB row and re-assert it onto config
 * just before the AiDriver is built (see AppServiceProvider). Because the binding re-runs
 * per resolution, a settings change takes effect on the very next request/job — no worker
 * restart. API keys are never read from or written to the DB; they stay in env.
 */
class AiSettingsResolver
{
    /** Cache key for the resolved settings; short TTL just caps query rate (correctness comes from Cache::forget on save). */
    public const CACHE_KEY = 'ai.settings.resolved';

    private const CACHE_TTL = 5;

    /**
     * Provider registry — label, whether the provider supports a reasoning knob, and
     * whether "configured" is gated by an api_key (anthropic/groq) or just a base_url (ollama).
     *
     * @var array<string, array{label:string, reasoning:bool, secret:bool}>
     */
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'reasoning' => false, 'secret' => true],
        'groq' => ['label' => 'Groq', 'reasoning' => false, 'secret' => true],
        'ollama' => ['label' => 'Ollama (self-hosted)', 'reasoning' => true, 'secret' => false],
    ];

    /** Concrete driver class for a provider key. */
    public function driverClassFor(string $key): string
    {
        return match ($key) {
            'groq' => GroqDriver::class,
            'ollama' => OllamaDriver::class,
            default => AnthropicDriver::class,
        };
    }

    /**
     * The current settings, merged over config defaults. Cached briefly. Falls back to
     * pure config (no DB) when the table is missing — so console commands and a fresh
     * install before migrate never fatal.
     *
     * @return array{max_tokens:int, chain:list<string>, providers:array<string,array{enabled:bool,model:?string,base_url:?string,reasoning_effort:?string}>, balance:array{enabled:bool,timeout:int}}
     */
    public function resolve(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->readFresh());
    }

    /** @return array{max_tokens:int, chain:list<string>, providers:array<string,array<string,mixed>>} */
    private function readFresh(): array
    {
        $row = null;
        try {
            if (Schema::hasTable('ai_settings')) {
                $row = AiSetting::current();
            }
        } catch (Throwable) {
            $row = null; // DB unavailable / pre-migration — fall back to config defaults.
        }

        $defaults = $this->configDefaults();

        if ($row === null) {
            return $defaults;
        }

        $providers = [];
        foreach (self::PROVIDERS as $key => $_meta) {
            $stored = $row->providers[$key] ?? [];
            $def = $defaults['providers'][$key];
            $providers[$key] = [
                'enabled' => (bool) ($stored['enabled'] ?? true),
                'model' => $stored['model'] ?? $def['model'],
                'base_url' => $stored['base_url'] ?? $def['base_url'],
                'reasoning_effort' => $stored['reasoning_effort'] ?? $def['reasoning_effort'],
            ];
        }

        $chain = array_values(array_filter(
            (array) ($row->chain ?? []),
            fn ($k) => is_string($k) && isset(self::PROVIDERS[$k]),
        ));

        return [
            'max_tokens' => (int) ($row->max_tokens ?: $defaults['max_tokens']),
            'chain' => $chain,
            'providers' => $providers,
            'balance' => [
                'enabled' => (bool) $row->balance_enabled,
                'timeout' => (int) ($row->balance_timeout ?: $defaults['balance']['timeout']),
            ],
        ];
    }

    /** Settings shape drawn purely from config/env — the safe fallback and merge base. */
    private function configDefaults(): array
    {
        return [
            'max_tokens' => (int) config('services.ai.max_tokens', 700),
            'chain' => [(string) config('services.ai.driver', 'anthropic')],
            'providers' => [
                'anthropic' => [
                    'enabled' => true,
                    'model' => config('services.ai.anthropic.model'),
                    'base_url' => config('services.ai.anthropic.base_url'),
                    'reasoning_effort' => null,
                ],
                'groq' => [
                    'enabled' => true,
                    'model' => config('services.ai.groq.model'),
                    'base_url' => config('services.ai.groq.base_url'),
                    'reasoning_effort' => null,
                ],
                'ollama' => [
                    'enabled' => true,
                    'model' => config('services.ai.ollama.model'),
                    'base_url' => config('services.ai.ollama.base_url'),
                    'reasoning_effort' => config('services.ai.ollama.reasoning_effort'),
                ],
            ],
            'balance' => [
                'enabled' => (bool) config('services.ai.balance.enabled'),
                'timeout' => (int) config('services.ai.balance.timeout', 5),
            ],
        ];
    }

    /**
     * Re-assert the resolved (non-secret) knobs onto the live config so the drivers pick
     * them up. API keys are deliberately untouched — env stays authoritative.
     *
     * @param  array{max_tokens:int, providers:array<string,array<string,mixed>>}  $settings
     */
    public function apply(array $settings): void
    {
        $overrides = ['services.ai.max_tokens' => $settings['max_tokens']];

        foreach (self::PROVIDERS as $key => $_meta) {
            $p = $settings['providers'][$key] ?? [];
            if (($p['model'] ?? null) !== null) {
                $overrides["services.ai.$key.model"] = $p['model'];
            }
            if (($p['base_url'] ?? null) !== null) {
                $overrides["services.ai.$key.base_url"] = $p['base_url'];
            }
            if ($key === 'ollama' && ($p['reasoning_effort'] ?? null) !== null) {
                $overrides['services.ai.ollama.reasoning_effort'] = $p['reasoning_effort'];
            }
        }

        config($overrides);
    }

    /**
     * The effective, ordered list of provider keys to try: the configured chain order,
     * keeping only providers that are enabled AND actually usable (key/base_url present).
     * Call AFTER apply() so `configured()` sees the resolved base_urls.
     *
     * @param  array{chain:list<string>, providers:array<string,array<string,mixed>>}  $settings
     * @return list<string>
     */
    public function chainKeys(array $settings): array
    {
        $seen = [];
        $keys = [];
        foreach ($settings['chain'] as $key) {
            if (isset($seen[$key]) || ! isset(self::PROVIDERS[$key])) {
                continue;
            }
            $seen[$key] = true;
            $enabled = (bool) ($settings['providers'][$key]['enabled'] ?? false);
            if ($enabled && $this->configured($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Whether a provider is usable right now: api_key present (anthropic/groq) or base_url set (ollama). */
    public function configured(string $key): bool
    {
        if (self::PROVIDERS[$key]['secret'] ?? false) {
            return (bool) config("services.ai.$key.api_key");
        }

        return (bool) config("services.ai.$key.base_url");
    }

    /** Drop the cached settings so the next resolve() re-reads the DB (called on save). */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
