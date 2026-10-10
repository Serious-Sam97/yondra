<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexReminder;
use App\Services\Vortex\Push\PushTransport;
use App\Services\Vortex\PushService;
use App\Services\Vortex\SoulService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Q-03 · real push, with a fake transport that records what would be sent
class FakePushTransport implements PushTransport
{
    public array $sent = [];

    public array $gone = [];

    public function send(array $subscriptions, string $payload): array
    {
        $this->sent[] = ['to' => array_column($subscriptions, 'endpoint'), 'payload' => json_decode($payload, true)];

        return $this->gone;
    }
}

beforeEach(function () {
    config(['vortex_mk5.push.public_key' => 'BPub', 'vortex_mk5.push.private_key' => 'priv']);
    $this->fake = new FakePushTransport;
    app()->instance(PushTransport::class, $this->fake);
});

function subscribeUser(User $u, string $endpoint = 'https://push.example.test/abc', array $extra = []): void
{
    test()->actingAs($u)->postJson('/api/mascot/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth1'], ...$extra])->assertOk();
}

it('is off without VAPID keys, and only takes https push endpoints', function () {
    $u = User::factory()->create();
    config(['vortex_mk5.push.public_key' => '']);
    expect($this->actingAs($u)->getJson('/api/mascot/push')->json('enabled'))->toBeFalse();
    $this->actingAs($u)->postJson('/api/mascot/push/subscribe', ['endpoint' => 'https://push.example.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertNotFound();
    config(['vortex_mk5.push.public_key' => 'BPub']);
    $this->actingAs($u)->postJson('/api/mascot/push/subscribe', ['endpoint' => 'http://evil.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);
    subscribeUser($u);
    subscribeUser($u); // the same browser twice is one subscription
    expect($this->actingAs($u)->getJson('/api/mascot/push')->json())->toMatchArray(['enabled' => true, 'public_key' => 'BPub', 'subscribed' => 1]);
});

it('sends at most one push a day, and forgets browsers the push service says are gone', function () {
    $u = User::factory()->create();
    subscribeUser($u);
    $push = app(PushService::class);
    expect($push->send($u, 'letter', 'mail'))->toBeTrue()
        ->and($push->send($u, 'reminder', 'again'))->toBeFalse();
    expect($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['payload'])->toMatchArray(['title' => 'vortex', 'body' => 'mail']);

    $v = User::factory()->create();
    subscribeUser($v, 'https://push.example.test/dead');
    $this->fake->gone = ['https://push.example.test/dead'];
    $push->send($v, 'letter', 'mail');
    expect(DB::table('vortex_push_subscriptions')->where('user_id', $v->id)->count())->toBe(0);
});

it('pushes a due reminder once, without stealing it from the in-app delivery', function () {
    $u = User::factory()->create();
    subscribeUser($u);
    $r = VortexReminder::create(['user_id' => $u->id, 'body' => 'call the bank', 'remind_at' => now()->subMinute()]);
    app(PushService::class)->tick();
    app(PushService::class)->tick();
    expect($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['payload']['body'])->toContain('call the bank')
        ->and($r->fresh()->delivered_at)->toBeNull()
        ->and($r->fresh()->pushed_at)->not->toBeNull();
});

it('says "you up?" at 03:13 local time only to those who asked for it', function () {
    $night = User::factory()->create();
    $quiet = User::factory()->create();
    subscribeUser($night, 'https://push.example.test/n', ['night' => true]);
    subscribeUser($quiet, 'https://push.example.test/q');
    foreach ([$night, $quiet] as $u) {
        $soul = app(SoulService::class)->for($u);
        $s = $soul->state;
        $s['tz'] = 'America/Sao_Paulo';
        $soul->state = $s;
        $soul->save();
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-10 03:13:20', 'America/Sao_Paulo'));
    app(PushService::class)->tick();
    expect($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['to'])->toBe(['https://push.example.test/n'])
        ->and($this->fake->sent[0]['payload']['body'])->toBe('you up?');
    $this->travelTo(CarbonImmutable::parse('2026-10-10 03:14:00', 'America/Sao_Paulo'));
    app(PushService::class)->tick();
    expect($this->fake->sent)->toHaveCount(1);
});
