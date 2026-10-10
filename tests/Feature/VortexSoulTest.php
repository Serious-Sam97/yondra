<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexJournal;
use App\Infrastructure\Models\VortexSoul;
use App\Jobs\WriteVortexLetterJob;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('creates a soul on first read with stable per-user likes', function () {
    $u = User::factory()->create();
    $a = $this->actingAs($u)->getJson('/api/mascot/soul')->assertOk()->json();
    $b = $this->actingAs($u)->getJson('/api/mascot/soul')->assertOk()->json();

    expect($a['likes'])->toBe($b['likes'])
        ->and($a['needs'])->toHaveKeys(SoulService::NEEDS)
        ->and($a['mood'])->toBeString()
        ->and($a['cause'])->toBeString()
        ->and(VortexSoul::where('user_id', $u->id)->count())->toBe(1);
});

it('applies whitelisted events with daily caps and rejects unknown ones', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'hack_relation']]])->assertStatus(422);

    $events = array_fill(0, 30, ['type' => 'chat']);
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => $events, 'tz' => 'America/Sao_Paulo'])
        ->assertOk()->json();

    // chat gives +1 relation, capped at 6 per day
    expect($view['relation'])->toBe(6)->and($view['tz'])->toBe('America/Sao_Paulo');
});

it('feeding lowers hunger; being ignored four times offends him', function () {
    $u = User::factory()->create();
    $before = $this->actingAs($u)->getJson('/api/mascot/soul')->json('needs.hunger');
    $after = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'fed']]])->json('needs.hunger');
    expect($after)->toBeLessThan($before);

    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => array_fill(0, 4, ['type' => 'ignored'])])->json();
    expect($view['offended'])->not->toBeNull()->and($view['mood'])->toBe('sulking');

    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'apology']]])->json();
    expect($view['offended'])->toBeNull();
});

it('keeps living while you are away and tells you what he did on your next visit', function () {
    $u = User::factory()->create();
    $souls = app(SoulService::class);
    $soul = $souls->for($u);
    $soul->last_seen_at = now()->subHours(20);
    $soul->last_tick_at = now()->subHours(20);
    $soul->save();

    $souls->tick($soul->fresh());
    $soul = $soul->fresh();
    expect($soul->state['needs']['hunger'])->toBeGreaterThan(35)
        ->and(count($soul->state['away']))->toBeGreaterThan(0);

    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    expect($view['away'])->not->toBeEmpty()
        ->and($view['away'][0])->toHaveKeys(['at', 'kind', 'text']);

    // the log is handed over once
    $again = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    expect($again['away'])->toBeEmpty();
});

it('gets sick after a week alone and is healed by care', function () {
    $u = User::factory()->create();
    $souls = app(SoulService::class);
    $soul = $souls->for($u);
    $soul->last_seen_at = now()->subDays(8);
    $soul->last_tick_at = now()->subDays(8);
    $soul->save();

    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    expect($view['sick'])->toBeTrue()->and($view['mood'])->toBe('dizzy');

    $care = [['type' => 'care', 'data' => ['kind' => 'stay']], ['type' => 'care', 'data' => ['kind' => 'chat']]];
    for ($i = 0; $i < 3; $i++) {
        $care[] = ['type' => 'care', 'data' => ['kind' => 'moved']];
    }
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => $care])->json();
    expect($view['sick'])->toBeFalse();
});

it('sleeps at night in the user timezone and explains why', function () {
    $state = app(SoulService::class)->defaults(User::factory()->create());
    [$mood, $cause] = SoulService::mood($state, 0, CarbonImmutable::parse('2026-10-09 04:10', 'America/Sao_Paulo'));
    expect($mood)->toBe('asleep')->and($cause)->toContain('04:10');

    [$mood] = SoulService::mood($state, 0, CarbonImmutable::parse('2026-10-09 03:13', 'America/Sao_Paulo'));
    expect($mood)->toBe('paranoid');
});

it('derives traits and corruption stages', function () {
    expect(SoulService::traits(['kind' => 31, 'night' => 8]))->toBe(['spoiled', 'nocturnal'])
        ->and(SoulService::stage(0))->toBe(0)
        ->and(SoulService::stage(40))->toBe(2)
        ->and(SoulService::stage(95))->toBe(5);
});

it('the life tick command only touches souls seen this week', function () {
    $active = User::factory()->create();
    $gone = User::factory()->create();
    $souls = app(SoulService::class);
    foreach ([[$active, 2], [$gone, 30]] as [$u, $days]) {
        $s = $souls->for($u);
        $s->last_seen_at = now()->subDays($days);
        $s->last_tick_at = now()->subHours(5);
        $s->save();
    }
    $this->artisan('vortex:life-tick')->assertSuccessful();

    expect(VortexSoul::where('user_id', $active->id)->first()->last_tick_at->gt(now()->subMinute()))->toBeTrue()
        ->and(VortexSoul::where('user_id', $gone->id)->first()->last_tick_at->lt(now()->subHours(4)))->toBeTrue();
});

it('keeps the diary locked until the key is found', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->getJson('/api/mascot/diary')->assertOk()->assertJson(['locked' => true, 'entries' => []]);
});

it('writes yesterday\'s diary once, with another hand in it', function () {
    config([
        'services.ai.driver' => 'anthropic',
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => "they moved nine cards.\n«the tea is still warm, m.»\ni didn't miss them."]],
    ], 200)]);
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $soul->state = array_merge($soul->state, ['diary_unlocked' => true]);
    $soul->save();

    $a = $this->actingAs($u)->getJson('/api/mascot/diary')->assertOk()->json();
    $b = $this->actingAs($u)->getJson('/api/mascot/diary')->assertOk()->json();
    expect($a['locked'])->toBeFalse()
        ->and($a['entries'])->toHaveCount(1)
        ->and($a['entries'][0]['body'])->toContain('«the tea')
        ->and($b['entries'])->toHaveCount(1);
    Http::assertSentCount(1);
});

it('hides the dev soul endpoint outside local', function () {
    config(['services.vortex.dev' => false]);
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/dev/soul', ['relation' => 90])->assertNotFound();
});

it('earns you a nickname of your own and forgets you after a month switched off', function () {
    $state = app(SoulService::class)->defaults(User::factory()->create());
    $state['counters']['night'] = 9;
    expect(SoulService::nickname($state, 10, 'Sam'))->toBe('night gremlin');
    $state['counters']['ignored'] = 25;
    expect(SoulService::nickname($state, 90, 'Sam'))->toBe('the ghoster');

    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => array_fill(0, 5, ['type' => 'chat'])]);
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'returned', 'data' => ['days' => 45]]]])->json();
    expect($view['relation'])->toBe(0)->and($view['forgot_you'])->toBeTrue();
});

it('queues a monthly letter on a visit when due and serves it once as new', function () {
    Bus::fake([WriteVortexLetterJob::class]);
    $u = User::factory()->create();
    Carbon::setTestNow(now()->endOfMonth()->setTime(12, 0));
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->assertOk();
    Bus::assertDispatched(WriteVortexLetterJob::class);

    VortexJournal::create(['user_id' => $u->id, 'kind' => 'letter', 'day' => now()->toDateString(), 'body' => 'dear tenant.']);
    $this->actingAs($u)->getJson('/api/mascot/letters')->assertOk()->assertJsonPath('0.new', true);
    $this->actingAs($u)->getJson('/api/mascot/letters')->assertOk()->assertJsonPath('0.new', false);
    Carbon::setTestNow();
});
