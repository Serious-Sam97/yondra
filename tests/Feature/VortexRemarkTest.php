<?php

use App\Infrastructure\Models\User;
use App\Services\Ai\AiDriver;
use Illuminate\Support\Facades\Cache;

function fakeVortexDriver(string $reply, bool $available = true): object
{
    $driver = new class($reply, $available) implements AiDriver
    {
        public int $calls = 0;

        public ?string $lastUser = null;

        public function __construct(private string $reply, private bool $available) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
        {
            return '';
        }

        public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
        {
            $this->calls++;
            $this->lastUser = $messages[0]['content'] ?? null;

            return $this->reply;
        }
    };
    app()->instance(AiDriver::class, $driver);

    return $driver;
}

beforeEach(fn () => Cache::flush());

it('requires authentication', function () {
    $this->postJson('/api/ai/vortex-remark')->assertStatus(401);
});

it('returns one lowercase line and caches it for the day', function () {
    $driver = fakeVortexDriver("\"Your Review Column Is A Waiting Room.\"\nsecond line");
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/ai/vortex-remark')
        ->assertOk()
        ->assertJson(['text' => 'your review column is a waiting room.']);
    $this->actingAs($user)->postJson('/api/ai/vortex-remark')->assertOk();

    expect($driver->calls)->toBe(1);
    expect($driver->lastUser)->toContain('<workspace>');
});

it('is unavailable without an AI provider', function () {
    fakeVortexDriver('x', false);
    $this->actingAs(User::factory()->create())->postJson('/api/ai/vortex-remark')->assertStatus(503);
});
