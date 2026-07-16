<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\AiSetting;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Vortex admin API for the global AI settings — which provider(s) the API uses, in what
 * fallback order, and their non-secret knobs. Never reads or writes API keys: providers are
 * reported only as configured/not, and keys stay in env. Writes flush the resolver cache so
 * changes apply on the next request/job with no worker restart.
 */
class AiSettingsController extends Controller
{
    public function __construct(private AiSettingsResolver $resolver) {}

    /** Current settings, secrets masked to a `configured` boolean. */
    public function index()
    {
        return response()->json($this->present($this->resolver->resolve()));
    }

    public function update(Request $request)
    {
        $keys = implode(',', array_keys(AiSettingsResolver::PROVIDERS));

        $data = $request->validate([
            'max_tokens' => 'required|integer|min:1|max:8000',
            'chain' => 'present|array',
            'chain.*' => "distinct|in:$keys",
            'providers' => 'present|array',
            'providers.*.enabled' => 'boolean',
            'providers.*.model' => 'nullable|string|max:200',
            'providers.*.base_url' => 'nullable|url|max:300',
            'providers.*.reasoning_effort' => 'nullable|in:none,low,medium,high',
            'balance_enabled' => 'required|boolean',
            'balance_timeout' => 'required|integer|min:1|max:60',
        ]);

        // Keep only known provider keys, and store the full non-secret knob set per provider.
        $providers = [];
        foreach (AiSettingsResolver::PROVIDERS as $key => $_meta) {
            $p = $data['providers'][$key] ?? [];
            $providers[$key] = [
                'enabled' => (bool) ($p['enabled'] ?? false),
                'model' => $p['model'] ?? null,
                'base_url' => $p['base_url'] ?? null,
                'reasoning_effort' => $p['reasoning_effort'] ?? null,
            ];
        }

        $row = AiSetting::current() ?? new AiSetting;
        $row->fill([
            'max_tokens' => $data['max_tokens'],
            'chain' => array_values($data['chain']),
            'providers' => $providers,
            'balance_enabled' => $data['balance_enabled'],
            'balance_timeout' => $data['balance_timeout'],
        ])->save();

        $this->resolver->flush();

        return response()->json($this->present($this->resolver->resolve()));
    }

    /**
     * Live health check: a cheap 1-token completion against one provider with a short
     * timeout. Returns {ok, latency_ms, error}. Not-configured providers short-circuit with
     * no outbound call (and no token spend).
     */
    public function health(string $driver)
    {
        abort_unless(isset(AiSettingsResolver::PROVIDERS[$driver]), 404);

        // Apply current settings so the concrete driver sees the resolved base_url/model.
        $settings = $this->resolver->resolve();
        $this->resolver->apply($settings);

        if (! $this->resolver->configured($driver)) {
            return response()->json(['ok' => false, 'error' => 'not configured', 'latency_ms' => null]);
        }

        // Fail fast — a dead provider shouldn't hang the admin UI for two minutes.
        Config::set('services.ai.timeout', 8);

        $instance = app($this->resolver->driverClassFor($driver));
        $start = microtime(true);

        try {
            $instance->complete('Reply with the single word: ok.', [['role' => 'user', 'content' => 'ping']], 1);

            return response()->json([
                'ok' => true,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Shape the resolved settings for the UI: per-provider metadata + live configured/in-chain
     * state, with all secrets reduced to booleans.
     *
     * @param  array{max_tokens:int, chain:list<string>, providers:array<string,array<string,mixed>>}  $settings
     */
    private function present(array $settings): array
    {
        // configured() reads config('services.ai.*') — apply settings so base_urls are current.
        $this->resolver->apply($settings);

        $chain = $settings['chain'];
        $providers = [];
        foreach (AiSettingsResolver::PROVIDERS as $key => $meta) {
            $p = $settings['providers'][$key] ?? [];
            $order = array_search($key, $chain, true);
            $providers[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'configured' => $this->resolver->configured($key),
                'enabled' => (bool) ($p['enabled'] ?? false),
                'in_chain' => $order !== false,
                'order' => $order === false ? null : $order,
                'model' => $p['model'] ?? null,
                'base_url' => $p['base_url'] ?? null,
                'reasoning_effort' => $p['reasoning_effort'] ?? null,
                'supports' => ['reasoning' => $meta['reasoning'], 'secret' => $meta['secret']],
            ];
        }

        return [
            'max_tokens' => $settings['max_tokens'],
            'chain' => array_values($chain),
            'effective_chain' => $this->resolver->chainKeys($settings),
            'providers' => $providers,
            'balance' => [
                'enabled' => (bool) ($settings['balance']['enabled'] ?? false),
                'timeout' => (int) ($settings['balance']['timeout'] ?? 5),
            ],
        ];
    }
}
