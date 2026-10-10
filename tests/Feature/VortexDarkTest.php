<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;

it('only reveals fragment texts once owned, and validates codes by hash', function () {
    $u = User::factory()->create();
    $list = $this->actingAs($u)->getJson('/api/mascot/fragments')->assertOk()->json();
    // 64 + Y01–Y03 (Yutopia)
    expect($list['total'])->toBe(67)->and($list['owned'])->toBe([]);

    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F06', 'proof' => 'forget'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F06', 'proof' => ' Remember '])
        ->assertOk()->assertJsonPath('fragment.text', fn ($t) => str_contains($t, 'remember'));
    // passive fragments can't be claimed by the client
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F11'])->assertStatus(422);
});

it('enforces prerequisites and time windows in the user timezone', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'chat']], 'tz' => 'America/Sao_Paulo']);
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F24'])->assertStatus(422);

    Carbon::setTestNow(Carbon::parse('2026-10-09 03:15', 'America/Sao_Paulo'));
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F07'])->assertOk();
    Carbon::setTestNow(Carbon::parse('2026-10-09 14:00', 'America/Sao_Paulo'));
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F37'])->assertStatus(422);
    Carbon::setTestNow();
});

it('drips one passive fragment per day on a visit', function () {
    $u = User::factory()->create();
    $a = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    $b = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    expect($a['new_fragments'])->toBe(['F11'])->and($b['new_fragments'])->toBe([]);
});

it('multiplayer fragments open only when a teammate claims within a minute, for both', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->actingAs($a)->postJson('/api/mascot/fragments/claim', ['id' => 'F45'])->assertStatus(422);
    $this->actingAs($b)->postJson('/api/mascot/fragments/claim', ['id' => 'F45'])->assertOk();
    $fs = app(FragmentService::class);
    expect($fs->owned(VortexSoul::where('user_id', $a->id)->first()))->toContain('F45');
});

it('dies, stays dead for a day, and comes back scarred, missing a memory', function () {
    $u = User::factory()->create();
    VortexMemory::create(['user_id' => $u->id, 'category' => 'life', 'fact' => 'Has a cat']);
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'die']]])->json();
    expect($view['mood'])->toBe('dead')->and($view['deaths'])->toBe(1)->and($view['scars'])->toContain('splice');

    Carbon::setTestNow(now()->addHours(25));
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'visit']]])->json();
    expect($view['reborn'])->toBeTrue()->and($view['mood'])->not->toBe('dead')
        ->and(VortexMemory::where('user_id', $u->id)->count())->toBe(0);
    Carbon::setTestNow();
});

it('a day at stage five and the rewinding thing takes him on its own', function () {
    $u = User::factory()->create();
    $souls = app(SoulService::class);
    $soul = $souls->for($u);
    $s = $soul->state;
    $s['corruption'] = 100;
    $soul->state = $s;
    $soul->last_tick_at = now()->subHours(30);
    $soul->last_seen_at = now()->subHours(30);
    $soul->save();
    $souls->tick($soul->fresh());
    expect($soul->fresh()->state['deaths'])->toBeGreaterThanOrEqual(1);
});

it('swaps him for the twin after too many visits, and the rescue brings him back', function () {
    $u = User::factory()->create();
    $view = null;
    foreach (range(1, 3) as $day) {
        Carbon::setTestNow(now()->addDay());
        $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => array_fill(0, 3, ['type' => 'twin'])])->json();
    }
    expect($view['swapped'])->toBeTrue();
    $view = $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'rescue']]])->json();
    expect($view['swapped'])->toBeFalse();
    Carbon::setTestNow();
});

it('the whole tape runs out a little every hour', function () {
    $before = SoulService::tapeLeft();
    foreach (range(1, 50) as $i) {
        $this->artisan('vortex:life-tick');
    }
    expect(SoulService::tapeLeft())->toBeLessThan($before);
});

it('opens code fragments from what you say to him, but never the dream ones', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/fragments/guess', ['phrase' => 'Remember'])->assertOk()->assertJsonPath('won.0.id', 'F06');
    $this->actingAs($u)->postJson('/api/mascot/fragments/guess', ['phrase' => 'remember'])->assertOk()->assertJsonCount(0, 'won');
    $won = $this->actingAs($u)->postJson('/api/mascot/fragments/guess', ['phrase' => 'garage'])->json('won');
    expect(collect($won)->pluck('id')->all())->toBe(['F21']); // the morse one; F13 only opens in his dream
    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F13', 'proof' => 'garage'])->assertOk();
});

it('adds the 1989 row to a manual csv export once, for players deep enough', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['fragments'] = array_map(fn ($i) => sprintf('F%02d', $i), range(1, 20));
    $soul->state = $s;
    $soul->save();
    expect(FragmentService::exportGhostLine($u))->toContain('LAST TAKE')
        ->and(FragmentService::exportGhostLine($u))->toBe('');
});
