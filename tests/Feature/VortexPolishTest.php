<?php

use App\Events\UserEvent;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexLedger;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use App\Services\AiAssistService;
use App\Services\Vortex\AiBudget;
use App\Services\Vortex\EconomyService;
use App\Services\Vortex\OutOfBudget;
use App\Services\Vortex\SoulService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

// the dev endpoints (/mascot/dev/*) exist only with VORTEX_DEV; switch it on for these tests
beforeEach(fn () => config(['services.vortex.dev' => true]));

it('a switched-off side answers 404 and the flags endpoint says so', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->getJson('/api/mascot/creator')->assertOk();
    config(['vortex_mk5.lados.criador' => false]);
    $this->actingAs($u)->getJson('/api/mascot/creator')->assertNotFound();
    expect($this->actingAs($u)->getJson('/api/mascot/flags')->json('lados.criador'))->toBeFalse();
    // sides without a flag keep working
    $this->actingAs($u)->getJson('/api/mascot/soul')->assertOk();
});

it('forget everything wipes him, guarded by the password, and can keep achievements', function () {
    $u = User::factory()->create(['password' => Hash::make('secret-pass')]);
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['counters']['done'] = 60;
    $s['taught'] = [['text' => 'hi', 'verdict' => 'ok', 'at' => '2026-01-01']];
    $soul->state = $s;
    $soul->save();
    app(EconomyService::class)->earn($soul, 'active', 5);
    DB::table('vortex_push_subscriptions')->insert(['user_id' => $u->id, 'endpoint_hash' => str_repeat('a', 64), 'endpoint' => 'https://push.example.test/x', 'p256dh' => 'k', 'auth' => 'a', 'created_at' => now(), 'updated_at' => now()]);
    VortexMemory::create(['user_id' => $u->id, 'category' => 'work', 'fact' => 'likes lists', 'source' => 'chat']);

    $this->actingAs($u)->postJson('/api/mascot/forget', ['password' => 'nope'])->assertStatus(422);
    $r = $this->actingAs($u)->postJson('/api/mascot/forget', ['password' => 'secret-pass', 'keep_achievements' => true])->assertOk()->json();
    expect($r['kept'])->toBeGreaterThan(0)
        ->and(VortexMemory::where('user_id', $u->id)->count())->toBe(0)
        ->and(VortexLedger::where('user_id', $u->id)->count())->toBe(0)
        ->and(DB::table('vortex_push_subscriptions')->where('user_id', $u->id)->count())->toBe(0);
    $fresh = VortexSoul::where('user_id', $u->id)->first();
    expect($fresh->state['taught'] ?? [])->toBe([])
        ->and($fresh->state['kept_achievements'])->toContain('done-1');
    $album = $this->actingAs($u)->getJson('/api/mascot/album')->json('albums.0.got');
    expect($album)->toBeGreaterThan(0);
});

it('past the AI budget he answers from templates, but a crisis turn always goes through', function () {
    Event::fake([UserEvent::class]);
    $u = User::factory()->create();
    config(['vortex_mk5.ai.features.chat.cap' => 0]);
    app(AiAssistService::class)->streamWorkspaceChat($u->id, 'r1', [['role' => 'user', 'content' => 'how is my board?']]);
    Event::assertDispatched(UserEvent::class, fn ($e) => ($e->payload['text'] ?? null) === AiBudget::LINE);

    Event::fake([UserEvent::class]);
    app(AiAssistService::class)->streamWorkspaceChat($u->id, 'r2', [['role' => 'user', 'content' => 'i want to kill myself']]);
    Event::assertNotDispatched(UserEvent::class, fn ($e) => ($e->payload['text'] ?? null) === AiBudget::LINE);
});

it('the global budget caps every feature', function () {
    $u = User::factory()->create();
    config(['vortex_mk5.ai.global_daily' => 2]);
    AiBudget::spend($u->id, 'npc');
    AiBudget::spend($u->id, 'tarot');
    expect(fn () => AiBudget::spend($u->id, 'chat'))->toThrow(OutOfBudget::class);
});

it('telemetry is aggregate, once a day per user, and drops bad event names', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->actingAs($a)->postJson('/api/mascot/telemetry', ['counts' => ['bubble:line' => ['shown' => 3, 'fast' => 1], 'DROP TABLE' => ['shown' => 9]]])->assertOk();
    $this->actingAs($a)->postJson('/api/mascot/telemetry', ['counts' => ['bubble:line' => ['shown' => 50]]])->assertJson(['dropped' => true]);
    $this->actingAs($b)->postJson('/api/mascot/telemetry', ['counts' => ['bubble:line' => ['shown' => 2, 'clicked' => 1]]])->assertOk();
    $day = $this->actingAs($a)->getJson('/api/mascot/dev/telemetry')->json('days.'.now()->toDateString());
    expect($day)->toBe(['bubble:line' => ['shown' => 5, 'fast' => 1, 'clicked' => 1]]);
});

it('MK-IV progress migrates to the server without losses, and comes back on a new device', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/mk4', ['achievements' => ['night-owl', 'sweet'], 'costumes' => ['witch'], 'streak' => 9, 'escape' => 4, 'birthday' => '03-13'])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/mk4', ['achievements' => ['sweet', 'seeker'], 'streak' => 2])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/mk4', ['achievements' => ['made-up']])->assertStatus(422);
    $m = $this->actingAs($u)->getJson('/api/mascot/mk4')->json();
    expect($m['achievements'])->toBe(['night-owl', 'sweet', 'seeker'])
        ->and($m['streak'])->toBe(9)->and($m['escape'])->toBe(4)->and($m['birthday'])->toBe('03-13');
});
