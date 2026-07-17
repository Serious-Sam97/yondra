<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\AiSetting;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vortex admin API for the global AI settings — which model instances the API uses, in what
 * fallback order, and their non-secret knobs. An instance is one provider type (anthropic/groq/
 * ollama) plus its own label/model/base_url/reasoning; a type may have many instances (e.g. N
 * Ollama models). Never reads or writes API keys: secret providers are reported only as
 * configured/not, and keys stay in env. Writes flush the resolver cache so changes apply on the
 * next request/job with no worker restart.
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
        $providers = implode(',', array_keys(AiSettingsResolver::PROVIDERS));

        $data = $request->validate([
            'max_tokens' => 'required|integer|min:1|max:8000',
            'instances' => 'present|array|max:40',
            'instances.*.id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'instances.*.provider' => "required|in:$providers",
            'instances.*.label' => 'nullable|string|max:120',
            'instances.*.enabled' => 'boolean',
            'instances.*.model' => 'nullable|string|max:200',
            'instances.*.base_url' => 'nullable|url|max:300',
            'instances.*.reasoning_effort' => 'nullable|in:none,low,medium,high',
            'chain' => 'present|array',
            'chain.*' => 'string|distinct',
            'balance_enabled' => 'required|boolean',
            'balance_timeout' => 'required|integer|min:1|max:60',
        ]);

        // Normalize the instances and enforce that every chain entry references a real instance.
        $instances = [];
        $ids = [];
        foreach ($data['instances'] as $inst) {
            $provider = $inst['provider'];
            $id = $inst['id'];
            $ids[$id] = true;
            $instances[] = [
                'id' => $id,
                'provider' => $provider,
                'label' => ($inst['label'] ?? '') !== '' ? $inst['label'] : AiSettingsResolver::PROVIDERS[$provider]['label'],
                'enabled' => (bool) ($inst['enabled'] ?? false),
                'model' => ($inst['model'] ?? '') !== '' ? $inst['model'] : null,
                'base_url' => ($inst['base_url'] ?? '') !== '' ? $inst['base_url'] : null,
                'reasoning_effort' => $provider === 'ollama' ? ($inst['reasoning_effort'] ?? null) : null,
            ];
        }

        $chain = array_values($data['chain']);
        foreach ($chain as $i => $id) {
            if (! isset($ids[$id])) {
                throw ValidationException::withMessages(["chain.$i" => 'Unknown instance in the chain.']);
            }
        }

        $row = AiSetting::current() ?? new AiSetting;
        $row->fill([
            'max_tokens' => $data['max_tokens'],
            'chain' => $chain,
            'instances' => $instances,
            'providers' => [], // legacy per-type map retired; instances are authoritative now.
            'balance_enabled' => $data['balance_enabled'],
            'balance_timeout' => $data['balance_timeout'],
        ])->save();

        $this->resolver->flush();

        return response()->json($this->present($this->resolver->resolve()));
    }

    /**
     * Live health check: a cheap 1-token completion against one INSTANCE with a short timeout.
     * Returns {ok, latency_ms, error}. Not-configured instances short-circuit with no outbound
     * call (and no token spend).
     */
    public function health(string $instance)
    {
        $settings = $this->resolver->resolve();
        $map = $this->resolver->instanceMap($settings);
        abort_unless(isset($map[$instance]), 404);

        $inst = $map[$instance];

        // Apply current settings so shared defaults are current (per-instance knobs come from the
        // wrapped driver built below).
        $this->resolver->apply($settings);

        if (! $this->resolver->configuredInstance($inst)) {
            return response()->json(['ok' => false, 'error' => 'not configured', 'latency_ms' => null]);
        }

        // Fail fast — a dead provider shouldn't hang the admin UI for two minutes.
        Config::set('services.ai.timeout', 8);

        $driver = $this->resolver->buildMember($inst);
        $start = microtime(true);

        try {
            $driver->complete('Reply with the single word: ok.', [['role' => 'user', 'content' => 'ping']], 1);

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
     * Shape the resolved settings for the UI: the ordered instance list with live configured/
     * in-chain state, plus provider-type metadata for the "add instance" picker. All secrets are
     * reduced to booleans.
     *
     * @param  array{max_tokens:int, chain:list<string>, instances:list<array<string,mixed>>, balance:array<string,mixed>}  $settings
     */
    private function present(array $settings): array
    {
        $this->resolver->apply($settings);

        $chain = $settings['chain'];
        $instances = [];
        foreach ($settings['instances'] as $inst) {
            $provider = $inst['provider'];
            $meta = AiSettingsResolver::PROVIDERS[$provider];
            $order = array_search($inst['id'], $chain, true);
            $instances[] = [
                'id' => $inst['id'],
                'provider' => $provider,
                'label' => $inst['label'],
                'enabled' => (bool) ($inst['enabled'] ?? false),
                'model' => $inst['model'] ?? null,
                'base_url' => $inst['base_url'] ?? null,
                'reasoning_effort' => $inst['reasoning_effort'] ?? null,
                'configured' => $this->resolver->configuredInstance($inst),
                'secret_present' => $this->resolver->providerSecretPresent($provider),
                'in_chain' => $order !== false,
                'order' => $order === false ? null : $order,
                'supports' => ['reasoning' => $meta['reasoning'], 'secret' => $meta['secret']],
            ];
        }

        $providerMeta = [];
        foreach (AiSettingsResolver::PROVIDERS as $key => $meta) {
            $providerMeta[] = [
                'key' => $key,
                'label' => $meta['label'],
                'secret' => $meta['secret'],
                'reasoning' => $meta['reasoning'],
                'secret_present' => $this->resolver->providerSecretPresent($key),
                'default_model' => config("services.ai.$key.model"),
                'default_base_url' => config("services.ai.$key.base_url"),
            ];
        }

        return [
            'max_tokens' => $settings['max_tokens'],
            'chain' => array_values($chain),
            'effective_chain' => $this->resolver->chainInstances($settings),
            'instances' => $instances,
            'providers' => $providerMeta,
            'balance' => [
                'enabled' => (bool) ($settings['balance']['enabled'] ?? false),
                'timeout' => (int) ($settings['balance']['timeout'] ?? 5),
            ],
        ];
    }
}
