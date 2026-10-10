<?php

use App\Infrastructure\Models\User;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;

it('finds dimensions by what you really did, and only lets you return from those', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', 'fragments' => ['F01'], 'below' => ['visited' => ['porao', 'garagem', 'ladob'], 'taken' => [], 'flags' => []]];
    $soul->save();
    $v = $this->actingAs($u)->getJson('/api/mascot/dimensions')->assertOk()->json();
    $found = collect($v['dimensions'])->where('found', true)->pluck('id')->all();
    expect($found)->toContain('y1985', 'underwater', 'inverted')->not->toContain('vex');
    expect(collect($v['dimensions'])->firstWhere('id', 'vex')['name'])->toBe('???');

    expect($this->actingAs($u)->postJson('/api/mascot/dimensions/return', ['dim' => 'vex', 'seconds' => 100])->json('ok'))->toBeFalse();
    expect($this->actingAs($u)->postJson('/api/mascot/dimensions/return', ['dim' => 'y1985', 'seconds' => 100])->json('ok'))->toBeTrue();
});

it('becomes unstable after two hours away in a day, until the next morning', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', 'fragments' => ['F01']];
    $soul->save();
    Carbon::setTestNow('2026-06-01 10:00:00');
    for ($i = 0; $i < 2; $i++) {
        $r = $this->actingAs($u)->postJson('/api/mascot/dimensions/return', ['dim' => 'y1985', 'seconds' => 3600])->json();
    }
    expect($r['unstable'])->toBeTrue();
    Carbon::setTestNow('2026-06-02 08:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/dimensions')->json('unstable'))->toBeFalse();
    Carbon::setTestNow();
});

it('dimension zero: N opens side c early, at a price', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/dimensions/zero', ['answer' => 'Y'])->assertJson(['side' => 'A']);
    $this->actingAs($u)->postJson('/api/mascot/dimensions/zero', ['answer' => 'n'])->assertJson(['side' => 'C']);
    $s = app(SoulService::class)->for($u)->fresh()->state;
    expect($s['fragments'])->toContain('F63')->and($s['corruption'])->toBeGreaterThanOrEqual(80);
    expect($this->actingAs($u)->getJson('/api/mascot/side-c')->json('access'))->toBeTrue();
});
