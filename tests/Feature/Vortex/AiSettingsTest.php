<?php

use App\Infrastructure\Models\User;
use App\Services\Ai\AiDriver;
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
            'providers' => [
                'anthropic' => ['key', 'label', 'configured', 'enabled', 'in_chain', 'order', 'model', 'base_url', 'supports' => ['reasoning', 'secret']],
            ],
        ]);

    // The api key must NEVER appear anywhere in the payload — only a `configured` boolean.
    expect(json_encode($res->json()))->not->toContain('sk-secret-value')
        ->and(json_encode($res->json()))->not->toContain('api_key')
        ->and($res->json('providers.anthropic.configured'))->toBeTrue();
});

it('persists an update and returns the fresh payload', function () {
    $payload = [
        'max_tokens' => 1500,
        'chain' => ['ollama', 'anthropic'],
        'providers' => [
            'anthropic' => ['enabled' => true, 'model' => 'claude-x', 'base_url' => null, 'reasoning_effort' => null],
            'groq' => ['enabled' => false, 'model' => null, 'base_url' => null, 'reasoning_effort' => null],
            'ollama' => ['enabled' => true, 'model' => 'qwen', 'base_url' => 'http://ollama.test/v1', 'reasoning_effort' => 'none'],
        ],
        'balance_enabled' => true,
        'balance_timeout' => 7,
    ];

    $this->actingAs(aiAdmin())
        ->putJson('/api/vortex/ai-settings', $payload)
        ->assertOk()
        ->assertJsonPath('max_tokens', 1500)
        ->assertJsonPath('chain', ['ollama', 'anthropic'])
        ->assertJsonPath('providers.ollama.model', 'qwen')
        ->assertJsonPath('balance.enabled', true)
        ->assertJsonPath('balance.timeout', 7);

    $this->assertDatabaseHas('ai_settings', ['max_tokens' => 1500, 'balance_enabled' => true, 'balance_timeout' => 7]);
});

it('rejects an out-of-range balance timeout', function () {
    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'providers' => [],
        'balance_enabled' => true, 'balance_timeout' => 999,
    ])->assertJsonValidationErrors('balance_timeout');
});

it('validates the update', function () {
    $admin = aiAdmin();

    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => ['nonsense'], 'providers' => [],
    ])->assertJsonValidationErrors('chain.0');

    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'providers' => ['ollama' => ['reasoning_effort' => 'extreme']],
    ])->assertJsonValidationErrors('providers.ollama.reasoning_effort');

    $this->actingAs($admin)->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 1000, 'chain' => [], 'providers' => ['ollama' => ['base_url' => 'not-a-url']],
    ])->assertJsonValidationErrors('providers.ollama.base_url');
});

it('health-pings a configured provider and reports latency', function () {
    config(['services.ai.ollama.base_url' => 'http://ollama.test/v1']);
    Http::fake([
        '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
    ]);

    $this->actingAs(aiAdmin())
        ->postJson('/api/vortex/ai-settings/health/ollama')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure(['ok', 'latency_ms', 'error']);
});

it('health returns not-configured without any outbound call', function () {
    config(['services.ai.anthropic.api_key' => null]);
    Http::fake();

    $this->actingAs(aiAdmin())
        ->postJson('/api/vortex/ai-settings/health/anthropic')
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'not configured');

    Http::assertNothingSent();
});

it('applies a saved chain to the AiDriver binding with no restart', function () {
    config(['services.ai.ollama.base_url' => 'http://ollama.test/v1']);

    $this->actingAs(aiAdmin())->putJson('/api/vortex/ai-settings', [
        'max_tokens' => 700,
        'chain' => ['ollama'],
        'providers' => [
            'anthropic' => ['enabled' => false],
            'groq' => ['enabled' => false],
            'ollama' => ['enabled' => true, 'base_url' => 'http://ollama.test/v1'],
        ],
        'balance_enabled' => false,
        'balance_timeout' => 5,
    ])->assertOk();

    // Freshly resolving the driver (as a queued job would) reflects the new DB row.
    app()->forgetInstance(AiDriver::class);
    $driver = app(AiDriver::class);

    expect($driver)->toBeInstanceOf(FallbackAiDriver::class)
        ->and($driver->members())->toHaveCount(1)
        ->and($driver->members()[0])->toBeInstanceOf(OllamaDriver::class);
});
