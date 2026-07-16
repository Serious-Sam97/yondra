<?php

use App\Services\Ai\AiDriver;
use App\Services\Ai\AnthropicDriver;
use App\Services\Ai\FallbackAiDriver;
use App\Services\Ai\GroqDriver;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

// Two real drivers wired to fakeable endpoints, so failover exercises the true transport.
function configuredGroq(): GroqDriver
{
    config([
        'services.ai.groq.api_key' => 'gsk-test',
        'services.ai.groq.base_url' => 'https://api.groq.com/openai/v1',
        'services.ai.groq.model' => 'llama-3.3-70b-versatile',
    ]);

    return app(GroqDriver::class);
}

function configuredAnthropic(): AnthropicDriver
{
    config([
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
        'services.ai.anthropic.version' => '2023-06-01',
    ]);

    return app(AnthropicDriver::class);
}

it('complete() falls through to the next provider on failure', function () {
    Http::fake([
        'api.groq.com/*' => Http::response('boom', 500),
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'from-anthropic']],
        ], 200),
    ]);

    $fb = new FallbackAiDriver([configuredGroq(), configuredAnthropic()]);

    expect($fb->complete('sys', [['role' => 'user', 'content' => 'q']]))->toBe('from-anthropic');
    Http::assertSentCount(2);
});

it('complete() throws when every provider fails', function () {
    Http::fake([
        'api.groq.com/*' => Http::response('boom', 500),
        'api.anthropic.com/*' => Http::response('boom', 500),
    ]);

    $fb = new FallbackAiDriver([configuredGroq(), configuredAnthropic()]);

    expect(fn () => $fb->complete('sys', [['role' => 'user', 'content' => 'q']]))
        ->toThrow(RuntimeException::class);
});

it('isAvailable() is true when any member is available', function () {
    config(['services.ai.groq.api_key' => null]);
    $unavailable = app(GroqDriver::class);

    expect((new FallbackAiDriver([$unavailable]))->isAvailable())->toBeFalse();
    expect((new FallbackAiDriver([$unavailable, configuredAnthropic()]))->isAvailable())->toBeTrue();
});

it('streamChat() fails over before the first delta', function () {
    Http::fake([
        'api.groq.com/*' => Http::response('boom', 500),
        // openAiStream()/anthropicStream() are declared in AiDriverTest and shared across the suite.
        'api.anthropic.com/*' => Http::response(anthropicStream(['Hel', 'lo']), 200),
    ]);

    $fb = new FallbackAiDriver([configuredGroq(), configuredAnthropic()]);

    $seen = '';
    $out = $fb->streamChat('sys', [['role' => 'user', 'content' => 'q']], function (string $d) use (&$seen) {
        $seen .= $d;
    });

    expect($out)->toBe('Hello')->and($seen)->toBe('Hello');
});

it('streamChat() rethrows after a delta and does not try the next provider', function () {
    // A provider that emits one token then dies cannot be cleanly restarted elsewhere.
    $first = new class implements AiDriver
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
        {
            $onDelta('partial');
            throw new RuntimeException('dropped mid-stream');
        }

        public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
        {
            return '';
        }
    };

    $second = new class implements AiDriver
    {
        public bool $called = false;

        public function isAvailable(): bool
        {
            return true;
        }

        public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
        {
            $this->called = true;

            return 'second';
        }

        public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
        {
            return '';
        }
    };

    $fb = new FallbackAiDriver([$first, $second]);

    expect(fn () => $fb->streamChat('sys', [], fn () => null))
        ->toThrow(RuntimeException::class, 'dropped mid-stream');
    expect($second->called)->toBeFalse();
});

// Records the balance deadline visible to each attempt via config, and can be told to fail.
function recordingDriver(bool $fail = false): AiDriver
{
    return new class($fail) implements AiDriver
    {
        public array $seen = [];

        public function __construct(public bool $fail) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
        {
            $this->seen[] = config('services.ai.attempt_deadline');
            if ($this->fail) {
                throw new RuntimeException('too slow');
            }
            $onDelta('ok');

            return 'ok';
        }

        public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
        {
            $this->seen[] = config('services.ai.attempt_deadline');
            if ($this->fail) {
                throw new RuntimeException('too slow');
            }

            return 'ok';
        }
    };
}

it('with balancing on, non-final members get the deadline and the last runs unbounded', function () {
    $a = recordingDriver(fail: true);   // "too slow" → fall through
    $b = recordingDriver();

    (new FallbackAiDriver([$a, $b], 5))->complete('s', []);

    expect($a->seen)->toBe([5])   // first member bounded by the 5s budget
        ->and($b->seen)->toBe([null]); // final member unbounded — nothing left to fall to
    // Deadline is always restored afterwards.
    expect(config('services.ai.attempt_deadline'))->toBeNull();
});

it('with balancing off, no deadline is ever imposed', function () {
    $a = recordingDriver(fail: true);
    $b = recordingDriver();

    (new FallbackAiDriver([$a, $b], null))->complete('s', []);

    expect($a->seen)->toBe([null])->and($b->seen)->toBe([null]);
});

it('an empty chain is unavailable and throws on use', function () {
    $fb = new FallbackAiDriver([]);

    expect($fb->isAvailable())->toBeFalse();
    expect(fn () => $fb->complete('sys', []))->toThrow(RuntimeException::class);
    expect(fn () => $fb->streamChat('sys', [], fn () => null))->toThrow(RuntimeException::class);
});
