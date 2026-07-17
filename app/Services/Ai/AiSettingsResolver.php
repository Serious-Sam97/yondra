<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Infrastructure\Models\AiSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The runtime bridge between the DB-backed AI settings row and the drivers. Everything that
 * talks to an LLM reads its knobs from config('services.ai.*') lazily at call time; instead of
 * touching every driver we resolve the DB row and build a driver per chain member, each carrying
 * its own non-secret overrides (see ConfiguredAiDriver). Because the AiDriver binding re-runs per
 * resolution, a settings change takes effect on the very next request/job — no worker restart.
 * API keys are never read from or written to the DB; they stay in env.
 *
 * The unit of configuration is an INSTANCE, not a provider type. An instance is one usable model
 * endpoint — a provider type (anthropic/groq/ollama) plus its own label/model/base_url/reasoning.
 * A provider type may have many instances (e.g. several Ollama models, one instance per model);
 * the ordered `chain` lists instance ids to try in turn.
 */
class AiSettingsResolver
{
    /** Cache key for the resolved settings; short TTL just caps query rate (correctness comes from flush() on save). */
    public const CACHE_KEY = 'ai.settings.resolved';

    private const CACHE_TTL = 5;

    /**
     * Provider-type registry — label, whether the type supports a reasoning knob, and whether
     * "configured" is gated by an api_key (anthropic/groq) or just a base_url (ollama). This is
     * the set of driver families an instance can be an instance OF.
     *
     * @var array<string, array{label:string, reasoning:bool, secret:bool}>
     */
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'reasoning' => false, 'secret' => true],
        'groq' => ['label' => 'Groq', 'reasoning' => false, 'secret' => true],
        'ollama' => ['label' => 'Ollama (self-hosted)', 'reasoning' => true, 'secret' => false],
    ];

    /** Concrete driver class for a provider type. */
    public function driverClassFor(string $provider): string
    {
        return match ($provider) {
            'groq' => GroqDriver::class,
            'ollama' => OllamaDriver::class,
            default => AnthropicDriver::class,
        };
    }

    /**
     * The current settings, merged over config defaults. Cached briefly. Falls back to pure config
     * (no DB) when the table is missing — so console commands and a fresh install before migrate
     * never fatal.
     *
     * @return array{max_tokens:int, chain:list<string>, instances:list<array{id:string,provider:string,label:string,enabled:bool,model:?string,base_url:?string,reasoning_effort:?string}>, balance:array{enabled:bool,timeout:int}}
     */
    public function resolve(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->readFresh());
    }

    /** @return array{max_tokens:int, chain:list<string>, instances:list<array<string,mixed>>, balance:array{enabled:bool,timeout:int}} */
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

        // New (instance-based) rows win. Legacy rows saved before the manager keep working:
        // their provider-keyed `providers` map is projected into one instance per type, and the
        // legacy `chain` of provider keys still resolves because those instance ids equal the keys.
        $instances = $this->normalizeInstances((array) ($row->instances ?? []));
        if ($instances === []) {
            $instances = $this->instancesFromLegacy((array) ($row->providers ?? []), $defaults);
        }
        if ($instances === []) {
            $instances = $defaults['instances'];
        }

        $ids = array_column($instances, 'id');
        $chain = array_values(array_filter(
            (array) ($row->chain ?? []),
            fn ($id) => is_string($id) && in_array($id, $ids, true),
        ));

        return [
            'max_tokens' => (int) ($row->max_tokens ?: $defaults['max_tokens']),
            'chain' => $chain,
            'instances' => $instances,
            'balance' => [
                'enabled' => (bool) $row->balance_enabled,
                'timeout' => (int) ($row->balance_timeout ?: $defaults['balance']['timeout']),
            ],
        ];
    }

    /**
     * Coerce a stored instances array into the canonical shape, dropping entries with an unknown
     * provider type or a blank id, and de-duplicating ids (first wins).
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array{id:string,provider:string,label:string,enabled:bool,model:?string,base_url:?string,reasoning_effort:?string}>
     */
    private function normalizeInstances(array $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $provider = (string) ($entry['provider'] ?? '');
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '' || isset($seen[$id]) || ! isset(self::PROVIDERS[$provider])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $this->normalizeInstance($id, $provider, $entry);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id:string,provider:string,label:string,enabled:bool,model:?string,base_url:?string,reasoning_effort:?string}
     */
    private function normalizeInstance(string $id, string $provider, array $entry): array
    {
        $str = fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        return [
            'id' => $id,
            'provider' => $provider,
            'label' => ($entry['label'] ?? '') !== '' ? (string) $entry['label'] : self::PROVIDERS[$provider]['label'],
            'enabled' => (bool) ($entry['enabled'] ?? true),
            'model' => $str($entry['model'] ?? null),
            'base_url' => $str($entry['base_url'] ?? null),
            'reasoning_effort' => $provider === 'ollama' ? $str($entry['reasoning_effort'] ?? null) : null,
        ];
    }

    /**
     * Project a legacy provider-keyed map ({anthropic:{...}, groq:{...}, ollama:{...}}) into one
     * instance per type, id = provider key. Preserves behaviour of rows saved before the manager.
     *
     * @param  array<string, mixed>  $providers
     * @return list<array<string,mixed>>
     */
    private function instancesFromLegacy(array $providers, array $defaults): array
    {
        if ($providers === []) {
            return [];
        }
        $byId = [];
        foreach ($defaults['instances'] as $inst) {
            $byId[$inst['id']] = $inst;
        }

        $out = [];
        foreach (self::PROVIDERS as $key => $_meta) {
            $stored = is_array($providers[$key] ?? null) ? $providers[$key] : [];
            $def = $byId[$key] ?? null;
            $out[] = $this->normalizeInstance($key, $key, [
                'label' => self::PROVIDERS[$key]['label'],
                'enabled' => $stored['enabled'] ?? true,
                'model' => $stored['model'] ?? ($def['model'] ?? null),
                'base_url' => $stored['base_url'] ?? ($def['base_url'] ?? null),
                'reasoning_effort' => $stored['reasoning_effort'] ?? ($def['reasoning_effort'] ?? null),
            ]);
        }

        return $out;
    }

    /**
     * Settings drawn purely from config/env — the safe fallback and merge base. Defaults to one
     * instance per provider type, id = provider key, and a chain of just the configured default
     * driver, so a fresh install (no row) behaves byte-for-byte like the pre-manager setup.
     */
    private function configDefaults(): array
    {
        $instances = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $instances[] = [
                'id' => $key,
                'provider' => $key,
                'label' => $meta['label'],
                'enabled' => true,
                'model' => config("services.ai.$key.model"),
                'base_url' => config("services.ai.$key.base_url"),
                'reasoning_effort' => $key === 'ollama' ? config('services.ai.ollama.reasoning_effort') : null,
            ];
        }

        return [
            'max_tokens' => (int) config('services.ai.max_tokens', 700),
            'chain' => [(string) config('services.ai.driver', 'anthropic')],
            'instances' => $instances,
            'balance' => [
                'enabled' => (bool) config('services.ai.balance.enabled'),
                'timeout' => (int) config('services.ai.balance.timeout', 5),
            ],
        ];
    }

    /** A fresh, unique instance id for a provider type — used when the client omits one. */
    public function newInstanceId(string $provider): string
    {
        return $provider.'-'.Str::lower(Str::random(6));
    }

    /**
     * Instances keyed by id, for quick lookup.
     *
     * @param  array{instances:list<array<string,mixed>>}  $settings
     * @return array<string, array<string,mixed>>
     */
    public function instanceMap(array $settings): array
    {
        $map = [];
        foreach ($settings['instances'] as $inst) {
            $map[$inst['id']] = $inst;
        }

        return $map;
    }

    /**
     * Re-assert the resolved global knobs onto the live config. Per-instance model/base_url/
     * reasoning are applied by each member's ConfiguredAiDriver at call time, so only truly global
     * settings live here. API keys are deliberately untouched — env stays authoritative.
     *
     * @param  array{max_tokens:int}  $settings
     */
    public function apply(array $settings): void
    {
        config(['services.ai.max_tokens' => $settings['max_tokens']]);
    }

    /**
     * Build the concrete, instance-configured driver for one instance: resolve the provider's
     * driver class and wrap it so its own model/base_url/reasoning are asserted before each call.
     *
     * @param  array{id:string,provider:string,model:?string,base_url:?string,reasoning_effort:?string}  $instance
     */
    public function buildMember(array $instance): AiDriver
    {
        $provider = $instance['provider'];
        $inner = app($this->driverClassFor($provider));

        $overrides = [];
        if (($instance['model'] ?? null) !== null) {
            $overrides["services.ai.$provider.model"] = $instance['model'];
        }
        if (($instance['base_url'] ?? null) !== null) {
            $overrides["services.ai.$provider.base_url"] = $instance['base_url'];
        }
        if ($provider === 'ollama' && ($instance['reasoning_effort'] ?? null) !== null) {
            $overrides['services.ai.ollama.reasoning_effort'] = $instance['reasoning_effort'];
        }

        return new ConfiguredAiDriver($inner, $overrides);
    }

    /**
     * The ordered members for the chain: each chain id resolved to its instance-configured driver,
     * keeping only enabled + usable instances. Call AFTER apply().
     *
     * @param  array{chain:list<string>, instances:list<array<string,mixed>>}  $settings
     * @return list<AiDriver>
     */
    public function buildMembers(array $settings): array
    {
        $map = $this->instanceMap($settings);

        return array_map(
            fn (string $id) => $this->buildMember($map[$id]),
            $this->chainInstances($settings),
        );
    }

    /**
     * The effective, ordered list of instance ids to try: the configured chain order, keeping only
     * instances that are enabled AND actually usable (api_key or base_url present).
     *
     * @param  array{chain:list<string>, instances:list<array<string,mixed>>}  $settings
     * @return list<string>
     */
    public function chainInstances(array $settings): array
    {
        $map = $this->instanceMap($settings);
        $seen = [];
        $ids = [];
        foreach ($settings['chain'] as $id) {
            if (isset($seen[$id]) || ! isset($map[$id])) {
                continue;
            }
            $seen[$id] = true;
            $inst = $map[$id];
            if (($inst['enabled'] ?? false) && $this->configuredInstance($inst)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether an instance is usable right now: for a secret provider its api_key (env, shared
     * across that type's instances) must be set; for Ollama the instance's own base_url (or the
     * config default) must be present.
     *
     * @param  array{provider:string,base_url:?string}  $instance
     */
    public function configuredInstance(array $instance): bool
    {
        $provider = $instance['provider'];
        if (self::PROVIDERS[$provider]['secret'] ?? false) {
            return (bool) config("services.ai.$provider.api_key");
        }

        return (bool) (($instance['base_url'] ?? null) ?: config("services.ai.$provider.base_url"));
    }

    /** Whether a provider TYPE's shared secret is present (drives the UI "no api key" hint). */
    public function providerSecretPresent(string $provider): bool
    {
        if (! (self::PROVIDERS[$provider]['secret'] ?? false)) {
            return true; // non-secret types (Ollama) need no key.
        }

        return (bool) config("services.ai.$provider.api_key");
    }

    /** Drop the cached settings so the next resolve() re-reads the DB (called on save). */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
