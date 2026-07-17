<?php

use App\Infrastructure\Models\AiSetting;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Ai\ConfiguredAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

function resolver(): AiSettingsResolver
{
    return app(AiSettingsResolver::class);
}

it('apply() sets max_tokens without touching api keys, and per-instance knobs ride the member', function () {
    // Legacy provider-keyed row still resolves — projected into one instance per type (id = key).
    AiSetting::create([
        'max_tokens' => 1234,
        'chain' => ['ollama'],
        'providers' => [
            'ollama' => ['enabled' => true, 'model' => 'my-model', 'base_url' => 'http://ollama.test/v1', 'reasoning_effort' => 'none'],
        ],
    ]);
    config(['services.ai.anthropic.api_key' => 'sk-should-survive']);

    $r = resolver();
    $r->flush();
    $settings = $r->resolve();
    $r->apply($settings);

    expect(config('services.ai.max_tokens'))->toBe(1234)
        // apply() never writes secrets — env stays authoritative.
        ->and(config('services.ai.anthropic.api_key'))->toBe('sk-should-survive');

    // The per-instance model/base_url are carried by the member (ConfiguredAiDriver), not by
    // apply() — a completion goes to the instance's own url with its own model.
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);
    $member = $r->buildMember($r->instanceMap($settings)['ollama']);
    expect($member)->toBeInstanceOf(ConfiguredAiDriver::class);
    $member->complete('s', [['role' => 'user', 'content' => 'q']], 1);

    Http::assertSent(fn ($req) => $req->url() === 'http://ollama.test/v1/chat/completions' && $req['model'] === 'my-model');
});

it('two Ollama instances keep independent models/urls when built as members', function () {
    AiSetting::create([
        'max_tokens' => 700,
        'chain' => ['a', 'b'],
        'providers' => [], // legacy column retired; the app writes [] on every save.
        'instances' => [
            ['id' => 'a', 'provider' => 'ollama', 'label' => 'A', 'enabled' => true, 'model' => 'qwen', 'base_url' => 'http://a.test/v1'],
            ['id' => 'b', 'provider' => 'ollama', 'label' => 'B', 'enabled' => true, 'model' => 'llama3.1', 'base_url' => 'http://b.test/v1'],
        ],
    ]);
    Http::fake([
        'a.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
        'b.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
    ]);

    $r = resolver();
    $r->flush();
    $members = $r->buildMembers($r->resolve());

    expect($members)->toHaveCount(2);
    $members[0]->complete('s', [['role' => 'user', 'content' => 'q']], 1);
    $members[1]->complete('s', [['role' => 'user', 'content' => 'q']], 1);

    Http::assertSent(fn ($req) => $req->url() === 'http://a.test/v1/chat/completions' && $req['model'] === 'qwen');
    Http::assertSent(fn ($req) => $req->url() === 'http://b.test/v1/chat/completions' && $req['model'] === 'llama3.1');
});

it('chainInstances() keeps only enabled + configured instances, preserving order', function () {
    config([
        'services.ai.anthropic.api_key' => 'sk',   // configured
        'services.ai.groq.api_key' => null,        // NOT configured
        'services.ai.ollama.base_url' => 'http://ollama.test/v1', // configured
    ]);

    AiSetting::create([
        'max_tokens' => 700,
        'chain' => ['ollama', 'groq', 'anthropic'],
        'providers' => [
            'anthropic' => ['enabled' => true],
            'groq' => ['enabled' => true],
            'ollama' => ['enabled' => false], // enabled=false → dropped
        ],
    ]);

    $r = resolver();
    $r->flush();
    $settings = $r->resolve();
    $r->apply($settings);

    // ollama disabled, groq unconfigured → only anthropic survives.
    expect($r->chainInstances($settings))->toBe(['anthropic']);
});

it('resolve() surfaces the balance settings from the row', function () {
    AiSetting::create([
        'max_tokens' => 700,
        'chain' => ['ollama'],
        'providers' => [],
        'balance_enabled' => true,
        'balance_timeout' => 8,
    ]);

    $r = resolver();
    $r->flush();
    $settings = $r->resolve();

    expect($settings['balance'])->toBe(['enabled' => true, 'timeout' => 8]);
});

it('resolve() balance falls back to config defaults with no row', function () {
    config(['services.ai.balance.enabled' => false, 'services.ai.balance.timeout' => 5]);

    $r = resolver();
    $r->flush();

    expect($r->resolve()['balance'])->toBe(['enabled' => false, 'timeout' => 5]);
});

it('resolve() falls back to config defaults when the settings table is missing', function () {
    Schema::drop('ai_settings');
    config(['services.ai.driver' => 'groq', 'services.ai.max_tokens' => 555]);

    $r = resolver();
    $r->flush();
    $settings = $r->resolve();

    expect($settings['max_tokens'])->toBe(555)
        ->and($settings['chain'])->toBe(['groq']);
});
