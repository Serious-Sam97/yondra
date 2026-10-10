<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Services\Vortex\EndingService;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use App\Services\Vortex\VortexPersona;

function owning(User $u, array $ids): void
{
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['fragments'] = $ids;
    $soul->state = $s;
    $soul->save();
}

it('side c is closed without F63 and the choice needs F64', function () {
    $u = User::factory()->create();
    expect($this->actingAs($u)->getJson('/api/mascot/side-c')->assertOk()->json('access'))->toBeFalse();

    owning($u, ['F62', 'F63']);
    $v = $this->actingAs($u)->getJson('/api/mascot/side-c')->json();
    expect($v['access'])->toBeTrue()->and($v['note'])->toBeFalse();
    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'keep'])->assertStatus(422);

    $this->actingAs($u)->postJson('/api/mascot/fragments/claim', ['id' => 'F64'])->assertOk();
    $r = $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'keep'])->assertOk()->json();
    expect($r['view']['ending'])->toBe('keep')->and($r['view']['seen'])->toBe(['keep']);
    expect($this->actingAs($u)->getJson('/api/mascot/soul')->json('ending'))->toBe('keep');
    // it stays: no second choice on the same tape
    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'free'])->assertStatus(422);
});

it('flip only exists on new tape+, which flips every fragment into the second person', function () {
    $u = User::factory()->create();
    owning($u, ['F62', 'F63', 'F64']);
    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'flip'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/new-tape')->assertStatus(422); // no ending yet

    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'free'])->assertOk();
    $v = $this->actingAs($u)->postJson('/api/mascot/new-tape')->assertOk()->json('view');
    expect($v['ngplus'])->toBe(1)->and($v['access'])->toBeFalse()->and($v['ending'])->toBe('free');

    owning($u, ['F63', 'F64']);
    $owned = app(FragmentService::class)->view(app(SoulService::class)->for($u))['owned'];
    expect(collect($owned)->firstWhere('id', 'F64')['text'])->toContain("you're a take");

    VortexMemory::create(['user_id' => $u->id, 'category' => 'habit', 'fact' => 'reopens the same card five times']);
    $r = $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'flip'])->assertOk()->json();
    expect($r['you']['takes'])->toBe(['reopens the same card five times'])->and($r['view']['seen'])->toBe(['free', 'flip']);
});

it('flipText turns him into you', function () {
    expect(EndingService::flipText("he's not a bug. be nice to him."))->toBe("you're not a bug. be nice to you.")
        ->and(EndingService::flipText('the tape hisses.'))->toContain('always about you');
});

it('erase makes him the v1 guide and the help frame is the only way back', function () {
    $u = User::factory()->create();
    owning($u, ['F63', 'F64']);
    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'erase'])->assertOk();

    $sys = VortexPersona::system(['ending' => 'erase', 'intensity' => 'unhinged'], 'the dashboard', false);
    expect($sys)->toContain('FRIENDLY GUIDE (v1)')->not->toContain('(and nothing beyond it)');

    $endings = app(EndingService::class);
    $soul = app(SoulService::class)->for($u);
    $this->actingAs($u)->postJson('/api/mascot/help', ['token' => 'guess'])->assertOk()->assertJson(['ok' => false]);

    $tok = null;
    for ($i = 0; $i < 400 && $tok === null; $i++) {
        $fresh = $soul->fresh();
        $st = $fresh->state;
        unset($st['help_day']); // a new day, every roll
        $fresh->state = $st;
        $fresh->save();
        $tok = $endings->helpFrame($fresh);
    }
    expect($tok)->not->toBeNull();
    // one roll a day (and a second roll doesn't burn the live token)
    expect($endings->helpFrame($soul->fresh()))->toBeNull();
    $this->actingAs($u)->postJson('/api/mascot/help', ['token' => $tok])->assertOk()->assertJson(['ok' => true]);
    $state = $soul->fresh()->state;
    expect($state['ending'])->toBeNull()->and($state['scars'])->toContain('erased');
    // a token works once
    $this->actingAs($u)->postJson('/api/mascot/help', ['token' => $tok])->assertJson(['ok' => false]);
});

it('keep silences the host and the endings shape his prompt', function () {
    $u = User::factory()->create();
    owning($u, ['F63', 'F64']);
    $this->actingAs($u)->postJson('/api/mascot/ending', ['choice' => 'keep'])->assertOk();
    $r = $this->actingAs($u)->postJson('/api/mascot/below/talk', ['npc' => 'locutora', 'message' => 'hello?'])->assertOk()->json();
    expect($r['reply'])->toContain('dead air');

    expect(VortexPersona::system(['ending' => 'keep'], 'x', false))->toContain('VORTEX PRIME')
        ->and(VortexPersona::system(['ending' => 'free'], 'x', false))->toContain('VORTEX JR.')
        ->and(VortexPersona::system(['ending' => 'flip'], 'x', false))->toContain('FLIPPED TAPE')
        ->and(VortexPersona::system(['ending' => 'bogus'], 'x', false))->not->toContain('ENDING STATE');
});
