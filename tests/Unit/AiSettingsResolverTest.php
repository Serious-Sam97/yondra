<?php

use App\Infrastructure\Models\AiSetting;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

function resolver(): AiSettingsResolver
{
    return app(AiSettingsResolver::class);
}

it('apply() overrides live config from the settings row without touching api keys', function () {
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
    $r->apply($r->resolve());

    expect(config('services.ai.max_tokens'))->toBe(1234)
        ->and(config('services.ai.ollama.model'))->toBe('my-model')
        ->and(config('services.ai.ollama.base_url'))->toBe('http://ollama.test/v1')
        // apply() never writes secrets — env stays authoritative.
        ->and(config('services.ai.anthropic.api_key'))->toBe('sk-should-survive');
});

it('chainKeys() keeps only enabled + configured providers, preserving order', function () {
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
    expect($r->chainKeys($settings))->toBe(['anthropic']);
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
