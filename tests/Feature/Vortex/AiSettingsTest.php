<?php

use App\Infrastructure\Models\User;
use App\Services\Ai\AiDriver;
use App\Services\Ai\ConfiguredAiDriver;
use App\Services\Ai\FallbackAiDriver;
use App\Services\Ai\OllamaDriver;
use Illuminate\Support\Facades\Http;

function aiAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('is behind the vortex admin gate', function () {
    User::factory()->create(); // non-admin
    $this->actingAs(User::factory()->create())
        ->getJson('/api/vortex/ai-settings')
        ->assertForbidden();
});

it('returns settings with secrets masked to booleans', function () {
    config(['services.ai.anthropic.api_key' => 'sk-secret-value']);

    $res = $this->actingAs(aiAdmin())
        ->getJson('/api/vortex/ai-settings')
        ->assertOk()
        ->assertJsonStructure([
            'max_tokens',
            'chain',
            'effective_chain',
            'instances' => [
                ['id', 'provider', 'label', 'configured', 'enabled', 'in_chain', 'order', 'model', 'base_url', 'supports' => ['reasoning', 'secret']],
            ],
            'providers' => [
                ['key', 'label', 'secret', 'reasoning', 'secret_present', 'default_model', 'default_base_url'],
            ],
        ]);

    // The api key must NEVER appear anywhere in the payload — only booleans.
    expect(json_encode($res->json()))->not->toContain('sk-secret-value')
        ->and(json_encode($res->json()))->not->toContain('api_key');

    // The default anthropic instance reports configured once the env key is present.
    $anthropic = collect($res->json('instances'))->firstWhere('provider', 'anthropic');
    expect($anthropic['configured'])->toBeTrue();
});

it('persists an instance update and returns the fresh payload', function () {
    $payload = [
        'max_tokens' => 1500,
        'chain' => ['ol-a', 'ol-b', 'anthropic'],
        'instances' => [
            ['id' => 'anthropic', 'provider' => 'anthropic', 'label' => 'Claude', 'enabled' => true, 'model' => 'claude-x', 'base_url' => null, 'reasoning_effort' => null],
            ['id' => 'ol-a', 'provider' => 'ollama', 'label' => 'Qwen', 'enabled' => true, 'model' => 'qwen3.5', 'base_url' => 'http://ollama.test/v1', 'reasoning_effort' => 'none'],
            ['id' => 'ol-b', 'provider' => 'ollama', 'label' => 'Llama', 'enabled' => true, 'model' => 'llama3.1', 'base_url' => 'http://ollama-2.test/v1', 'reasoning_effort' => 'low'],
        ],
        'balance_enabled' => true,
        'balance_timeout' => 7,
    ];

    $res = $this->actingAs(aiAdmin())
        ->putJson('/api/vortex/ai-settings', $payload)
        ->assertOk()
        ->assertJsonPath('max_tokens', 1500)
        ->assertJsonPath('chain', ['ol-a', 'ol-b', 'anthropic'])
        ->assertJsonPath('balance.enabled', true)
        ->assertJsonPath('balance.timeout', 7);

    // Two independent Ollama instances persist with their own models/urls.
    $instances = collect($res->json('instances'))->keyBy('id');
    expect($instances['ol-a']['model'])->toBe('qwen3.5')
        ->and($instances['ol-a']['base_url'])->toBe('http://ollama.test/v1')
        ->and($instances['ol-b']['model'])->toBe('llama3.1')
        ->and($instances['ol-b']['base_url'])->toBe('http://ollama-2.test/v1');

    $this->assertDatabaseHas('ai_settings', ['max_tokens' => 1500, 'balance_enabled' => true, 'balance_timeout' => 7]);
});

it('rejects a chain entry that references no instance', function () {
    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000,
        'chain' => ['ghost'],
        'instances' => [
            ['id' => 'anthropic', 'provider' => 'anthropic', 'enabled' => true],
        ],
        'balance_enabled' => false, 'balance_timeout' => 5,
    ])->assertJsonValidationErrors('chain.0');
});

it('rejects an out-of-range balance timeout', function () {
    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'instances' => [],
        'balance_enabled' => true, 'balance_timeout' => 999,
    ])->assertJsonValidationErrors('balance_timeout');
});

it('validates instance fields', function () {
    $admin = aiAdmin();

    // Unknown provider type.
    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'balance_enabled' => false, 'balance_timeout' => 5,
        'instances' => [['id' => 'x', 'provider' => 'nonsense']],
    ])->assertJsonValidationErrors('instances.0.provider');

    // Bad reasoning value.
    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'balance_enabled' => false, 'balance_timeout' => 5,
        'instances' => [['id' => 'o', 'provider' => 'ollama', 'reasoning_effort' => 'extreme']],
    ])->assertJsonValidationErrors('instances.0.reasoning_effort');

    // Bad base url.
    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'balance_enabled' => false, 'balance_timeout' => 5,
        'instances' => [['id' => 'o', 'provider' => 'ollama', 'base_url' => 'not-a-url']],
    ])->assertJsonValidationErrors('instances.0.base_url');

    // Duplicate ids.
    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'balance_enabled' => false, 'balance_timeout' => 5,
        'instances' => [
            ['id' => 'dup', 'provider' => 'ollama'],
            ['id' => 'dup', 'provider' => 'groq'],
        ],
    ])->assertJsonValidationErrors('instances.0.id');
});

it('health-pings a configured instance and reports latency', function () {
    Http::fake([
        '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
    ]);

    // Save an Ollama instance, then ping it by its id.
    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 700,
        'chain' => ['ol-a'],
        'instances' => [
            ['id' => 'ol-a', 'provider' => 'ollama', 'enabled' => true, 'model' => 'qwen', 'base_url' => 'http://ollama.test/v1'],
        ],
        'balance_enabled' => false, 'balance_timeout' => 5,
    ])->assertOk();

    $this->actingAs(aiAdmin())
        ->postJson('/api/vortex/ai-settings/health/ol-a')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure(['ok', 'latency_ms', 'error']);

    // The ping went to the instance's own base_url.
    Http::assertSent(fn ($r) => $r->url() === 'http://ollama.test/v1/chat/completions');
});

it('health returns not-configured without any outbound call', function () {
    config(['services.ai.anthropic.api_key' => null]);
    Http::fake();

    // The default anthropic instance (id = anthropic) with no env key.
    $this->actingAs(aiAdmin())
        ->postJson('/api/vortex/ai-settings/health/anthropic')
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'not configured');

    Http::assertNothingSent();
});

it('health 404s for an unknown instance id', function () {
    $this->actingAs(aiAdmin())
        ->postJson('/api/vortex/ai-settings/health/does-not-exist')
        ->assertNotFound();
});

it('applies saved parallel Ollama instances to the AiDriver binding with no restart', function () {
    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 700,
        'chain' => ['ol-a', 'ol-b'],
        'instances' => [
            ['id' => 'ol-a', 'provider' => 'ollama', 'enabled' => true, 'model' => 'qwen', 'base_url' => 'http://ollama.test/v1'],
            ['id' => 'ol-b', 'provider' => 'ollama', 'enabled' => true, 'model' => 'llama3.1', 'base_url' => 'http://ollama-2.test/v1'],
        ],
        'balance_enabled' => false,
        'balance_timeout' => 5,
    ])->assertOk();

    // Freshly resolving the driver (as a queued job would) reflects the new DB row: two Ollama
    // members, each wrapping an OllamaDriver.
    app()->forgetInstance(AiDriver::class);
    $driver = app(AiDriver::class);

    expect($driver)->toBeInstanceOf(FallbackAiDriver::class)
        ->and($driver->members())->toHaveCount(2)
        ->and($driver->members()[0])->toBeInstanceOf(ConfiguredAiDriver::class)
        ->and($driver->members()[0]->inner())->toBeInstanceOf(OllamaDriver::class)
        ->and($driver->members()[1]->inner())->toBeInstanceOf(OllamaDriver::class);
});
