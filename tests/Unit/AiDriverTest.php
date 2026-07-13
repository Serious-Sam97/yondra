<?php

use App\Services\Ai\AiDriver;
use App\Services\Ai\AnthropicDriver;
use App\Services\Ai\GroqDriver;
use App\Services\Ai\OllamaDriver;
use Illuminate\Support\Facades\Http;

// These exercise the driver layer only (no DB) — they need the Laravel app for
// config()/Http fakes, so bind TestCase without RefreshDatabase.
uses(Tests\TestCase::class);

// Assemble an Anthropic-style SSE body: interleaved event:/data: lines, text carried
// on content_block_delta / text_delta frames.
function anthropicStream(array $chunks): string
{
    $lines = ['event: message_start', 'data: {"type":"message_start","message":{}}', ''];
    foreach ($chunks as $text) {
        $lines[] = 'event: content_block_delta';
        $lines[] = 'data: '.json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
        $lines[] = '';
    }
    $lines[] = 'event: message_stop';
    $lines[] = 'data: {"type":"message_stop"}';
    $lines[] = '';

    return implode("\n", $lines);
}

// Assemble an OpenAI-compatible SSE body (Groq / Ollama): text on choices[].delta.content,
// closed with the `[DONE]` sentinel the base driver must skip.
function openAiStream(array $chunks): string
{
    $lines = ['data: {"choices":[{"delta":{"role":"assistant"}}]}', ''];
    foreach ($chunks as $text) {
        $lines[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $text]]]]);
        $lines[] = '';
    }
    $lines[] = 'data: [DONE]';
    $lines[] = '';

    return implode("\n", $lines);
}

it('AnthropicDriver: isAvailable follows the api key', function () {
    config(['services.ai.anthropic.api_key' => null]);
    expect(app(AnthropicDriver::class)->isAvailable())->toBeFalse();

    config(['services.ai.anthropic.api_key' => 'sk-test']);
    expect(app(AnthropicDriver::class)->isAvailable())->toBeTrue();
});

it('AnthropicDriver: streams text, invokes onDelta per chunk, sends the right request', function () {
    config([
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicStream(['Hello', ' world']), 200)]);

    $deltas = [];
    $full = app(AnthropicDriver::class)->streamChat(
        'SYSTEM',
        [['role' => 'user', 'content' => 'hi']],
        function (string $d) use (&$deltas) { $deltas[] = $d; },
        123,
    );

    expect($full)->toBe('Hello world')
        ->and($deltas)->toBe(['Hello', ' world']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-opus-4-8'
            && $request['max_tokens'] === 123
            && $request['system'] === 'SYSTEM'
            && $request['stream'] === true
            && $request['messages'][0]['content'] === 'hi';
    });
});

it('GroqDriver: OpenAI-compatible stream, Bearer auth, system-as-first-message', function () {
    config([
        'services.ai.groq.api_key' => 'gsk-test',
        'services.ai.groq.base_url' => 'https://api.groq.com/openai/v1',
        'services.ai.groq.model' => 'llama-3.3-70b-versatile',
    ]);
    Http::fake(['api.groq.com/*' => Http::response(openAiStream(['Groq', ' here']), 200)]);

    expect(app(GroqDriver::class)->isAvailable())->toBeTrue();

    $full = app(GroqDriver::class)->streamChat('SYS', [['role' => 'user', 'content' => 'q']], fn () => null, 50);

    expect($full)->toBe('Groq here');
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gsk-test')
            && $request['model'] === 'llama-3.3-70b-versatile'
            && $request['messages'][0] === ['role' => 'system', 'content' => 'SYS']
            && $request['messages'][1]['content'] === 'q'
            && $request['stream'] === true;
    });
});

it('GroqDriver: isAvailable is false without a key', function () {
    config(['services.ai.groq.api_key' => null]);
    expect(app(GroqDriver::class)->isAvailable())->toBeFalse();
});

it('OllamaDriver: OpenAI-compatible stream against localhost, no auth header', function () {
    config([
        'services.ai.ollama.base_url' => 'http://localhost:11434/v1',
        'services.ai.ollama.model' => 'llama3.1',
    ]);
    Http::fake(['localhost:11434/*' => Http::response(openAiStream(['Local', ' llama']), 200)]);

    expect(app(OllamaDriver::class)->isAvailable())->toBeTrue();

    $full = app(OllamaDriver::class)->streamChat('SYS', [['role' => 'user', 'content' => 'q']], fn () => null, 50);

    expect($full)->toBe('Local llama');
    Http::assertSent(function ($request) {
        return $request->url() === 'http://localhost:11434/v1/chat/completions'
            && ! $request->hasHeader('Authorization')
            && $request['model'] === 'llama3.1';
    });
});

it('AnthropicDriver: complete() returns non-streaming text and omits stream', function () {
    config([
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => '{"points":5}']],
    ], 200)]);

    $out = app(AnthropicDriver::class)->complete('SYS', [['role' => 'user', 'content' => 'q']], 400, true);

    expect($out)->toBe('{"points":5}');
    Http::assertSent(fn ($r) => ! isset($r['stream']) && $r['system'] === 'SYS');
});

it('GroqDriver: complete() sets OpenAI json mode and reads choices.message.content', function () {
    config([
        'services.ai.groq.api_key' => 'gsk-test',
        'services.ai.groq.base_url' => 'https://api.groq.com/openai/v1',
        'services.ai.groq.model' => 'llama-3.3-70b-versatile',
    ]);
    Http::fake(['api.groq.com/*' => Http::response([
        'choices' => [['message' => ['content' => '{"points":8}']]],
    ], 200)]);

    $out = app(GroqDriver::class)->complete('SYS', [['role' => 'user', 'content' => 'q']], 400, true);

    expect($out)->toBe('{"points":8}');
    Http::assertSent(fn ($r) => ($r['response_format']['type'] ?? null) === 'json_object' && ! isset($r['stream']));
});

it('streamChat throws when the provider is unconfigured', function () {
    config(['services.ai.groq.api_key' => null]);
    app(GroqDriver::class)->streamChat('s', [], fn () => null);
})->throws(RuntimeException::class);

it('streamChat throws on a non-2xx response', function () {
    config(['services.ai.groq.api_key' => 'gsk-test']);
    Http::fake(['api.groq.com/*' => Http::response('nope', 500)]);
    app(GroqDriver::class)->streamChat('s', [], fn () => null);
})->throws(RuntimeException::class);

it('the AiDriver binding is selected by config', function () {
    config(['services.ai.driver' => 'anthropic']);
    expect(app(AiDriver::class))->toBeInstanceOf(AnthropicDriver::class);

    app()->forgetInstance(AiDriver::class);
    config(['services.ai.driver' => 'groq']);
    expect(app(AiDriver::class))->toBeInstanceOf(GroqDriver::class);

    app()->forgetInstance(AiDriver::class);
    config(['services.ai.driver' => 'ollama']);
    expect(app(AiDriver::class))->toBeInstanceOf(OllamaDriver::class);

    app()->forgetInstance(AiDriver::class);
    config(['services.ai.driver' => 'something-unknown']);
    expect(app(AiDriver::class))->toBeInstanceOf(AnthropicDriver::class);
});
