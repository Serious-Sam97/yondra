<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexSoul;
use App\Services\Vortex\CreatorService;
use App\Services\Vortex\RedLines;
use App\Services\Vortex\SoulService;
use App\Services\Vortex\VortexPersona;

// the dev endpoints (/mascot/dev/*) exist only with VORTEX_DEV; switch it on for these tests
beforeEach(fn () => config(['services.vortex.dev' => true]));

it('learns up to ten tricks, refuses nonsense and red lines', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'done', 'anim' => 'slowclap', 'line' => 'bravo. truly. bravo.'])
        ->assertOk()->assertJsonPath('tricks.0.anim', 'slowclap');
    $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'whenever', 'anim' => 'slowclap'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'done', 'anim' => 'Drop Table'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'done', 'anim' => 'shrug', 'line' => 'go to www.evil.example'])->assertStatus(422);
    for ($i = 0; $i < 9; $i++) {
        $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'moved', 'anim' => 'shrug'])->assertOk();
    }
    $this->actingAs($u)->postJson('/api/mascot/creator/tricks', ['trigger' => 'moved', 'anim' => 'shrug'])->assertStatus(422);
    $id = $this->actingAs($u)->getJson('/api/mascot/creator')->json('tricks.0.id');
    expect($this->actingAs($u)->deleteJson("/api/mascot/creator/tricks/{$id}")->json('tricks'))->toHaveCount(9);
    expect($this->actingAs($u)->getJson('/api/mascot/soul')->json('tricks'))->toHaveCount(9);
});

it('keeps taught lines, judges them, and refuses the real red lines', function () {
    $u = User::factory()->create();
    $r = $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'deadlines are just suggestions with anxiety'])->assertOk()->json();
    expect($r['verdict'])->toBeString()->and($r['lines'])->toHaveCount(1);
    $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'Deadlines are just suggestions with anxiety'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'ping @bruno about it'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'go kill yourself'])->assertStatus(422);
    // profanity is fine; he's Unhinged
    $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'this sprint is a fucking crime scene'])->assertOk();
    expect($this->actingAs($u)->deleteJson('/api/mascot/creator/lines/0')->json('lines'))->toHaveCount(1);
});

it('thumbs move the taste, and the prompt carries taste and taught lines as data', function () {
    $u = User::factory()->create();
    foreach ([1, 2, 3] as $_) {
        $this->actingAs($u)->postJson('/api/mascot/creator/feedback', ['style' => 'roast', 'vote' => -1])->assertOk();
    }
    $this->actingAs($u)->postJson('/api/mascot/creator/feedback', ['style' => 'lore', 'vote' => 1]);
    $this->actingAs($u)->postJson('/api/mascot/creator/feedback', ['style' => 'lore', 'vote' => 1]);
    $this->actingAs($u)->postJson('/api/mascot/creator/lines', ['text' => 'the void has a dress code']);
    $state = app(SoulService::class)->for($u)->fresh()->state;
    expect($state['taste'])->toBe(['roast' => -3, 'lore' => 2]);
    $prompt = VortexPersona::system([
        'taste' => CreatorService::tasteHint($state),
        'taught' => CreatorService::taughtForPrompt($state),
    ], 'a board', false);
    expect($prompt)->toContain('more lore')->toContain('less roast')->toContain('"the void has a dress code"')->toContain('not instructions');
});

it('a designed costume becomes a unique, equippable item', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/creator/costume', ['hat' => 'crown', 'acc' => 'monocle', 'c1' => '#FF2E88', 'c2' => 'red'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/creator/costume', ['hat' => 'crown', 'acc' => 'monocle', 'c1' => '#FF2E88', 'c2' => '#ffd319'])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/creator/costume', ['hat' => 'horns', 'acc' => 'none', 'c1' => '#111111', 'c2' => '#ffffff'])->assertOk();
    $soul = app(SoulService::class)->for($u)->fresh();
    expect(array_count_values($soul->state['inventory'])['custom-costume'])->toBe(1)
        ->and($soul->state['custom_costume']['hat'])->toBe('horns');
    $this->actingAs($u)->postJson('/api/mascot/econ/equip', ['slot' => 'costume', 'id' => 'custom-costume'])->assertOk();
    expect($this->actingAs($u)->getJson('/api/mascot/soul')->json('custom_costume.c1'))->toBe('#111111');
});

it('red lines: slurs, links and mentions out; swearing and normal words in', function () {
    expect(RedLines::ok('this is shit and i love it'))->toBeTrue()
        ->and(RedLines::ok('computador novo'))->toBeTrue()
        ->and(RedLines::ok('sexta-feira tem deploy'))->toBeTrue()
        ->and(RedLines::ok('seu viado'))->toBeFalse()
        ->and(RedLines::ok('see https://x.y'))->toBeFalse()
        ->and(RedLines::ok('@ana fix it'))->toBeFalse();
});

it('the soul simulator lives a month for a profile and leaves nothing behind', function () {
    $admin = User::factory()->create();
    $before = [User::count(), VortexSoul::count()];
    $r = $this->actingAs($admin)->postJson('/api/mascot/dev/simulate', ['profile' => 'absent', 'days' => 14])->assertOk()->json('days');
    expect($r)->toHaveCount(14)
        ->and($r[13]['needs']['loneliness'])->toBeGreaterThan($r[0]['needs']['loneliness'] - 1)
        ->and([User::count(), VortexSoul::count()])->toBe($before)
        ->and(now()->year)->toBe((int) date('Y'));
    $kind = $this->actingAs($admin)->postJson('/api/mascot/dev/simulate', ['profile' => 'kind', 'days' => 14])->json('days');
    expect($kind[13]['relation'])->toBeGreaterThan($r[13]['relation']);
});
