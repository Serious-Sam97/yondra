<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexLedger;
use App\Services\Vortex\EconomyService;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function econSoul(User $u, array $extra = [])
{
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', ...$extra];
    $soul->save();

    return $soul;
}

function coins(User $u, string $cur, int $n, string $reason = 'test'): void
{
    VortexLedger::create(['user_id' => $u->id, 'currency' => $cur, 'amount' => $n, 'reason' => $reason]);
}

it('caps earnings per day and pays echoes for fragments', function () {
    $u = User::factory()->create();
    $soul = econSoul($u);
    $econ = app(EconomyService::class);
    expect($econ->earn($soul, 'caught_lie', 4))->toBe(4)
        ->and($econ->earn($soul, 'caught_lie', 4))->toBe(2)
        ->and($econ->earn($soul, 'caught_lie', 4))->toBe(0);
    app(FragmentService::class)->grant($soul, 'F01');
    expect($econ->balance($u->id))->toBe(['tokens' => 6, 'minutes' => 0, 'echoes' => 3]);
});

it('pays active minutes at most once every 50 seconds', function () {
    $u = User::factory()->create();
    econSoul($u);
    Carbon::setTestNow('2026-06-01 10:00:00');
    expect($this->actingAs($u)->postJson('/api/mascot/econ/tick')->json('credited'))->toBe(1);
    Carbon::setTestNow('2026-06-01 10:00:20');
    expect($this->actingAs($u)->postJson('/api/mascot/econ/tick')->json('credited'))->toBe(0);
    Carbon::setTestNow('2026-06-01 10:01:05');
    expect($this->actingAs($u)->postJson('/api/mascot/econ/tick')->json('credited'))->toBe(1);
    Carbon::setTestNow();
});

it('pays finished cards once each, and only if they lived an hour', function () {
    $u = User::factory()->create();
    econSoul($u);
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'Done']);
    $old = Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'real', 'description' => '', 'done_at' => now()]);
    $old->created_at = now()->subHours(3);
    $old->save();
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'instant', 'description' => '', 'done_at' => now()]);

    expect($this->actingAs($u)->getJson('/api/mascot/econ')->json('balance.minutes'))->toBe(5);
    expect($this->actingAs($u)->getJson('/api/mascot/econ')->json('balance.minutes'))->toBe(5); // never twice
});

it('sells from the shops, equips what you own, and opens the black market only at the dead hour', function () {
    $u = User::factory()->create();
    econSoul($u);
    coins($u, 'minutes', 200);
    $this->actingAs($u)->postJson('/api/mascot/shop/buy', ['shop' => 'splicer', 'id' => 'eye-red'])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/shop/buy', ['shop' => 'splicer', 'id' => 'eye-red'])->assertStatus(422); // already owned
    expect(app(EconomyService::class)->balance($u->id)['minutes'])->toBe(140);
    $this->actingAs($u)->postJson('/api/mascot/econ/equip', ['slot' => 'eye', 'id' => 'eye-red'])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/econ/equip', ['slot' => 'eye', 'id' => 'eye-cyan'])->assertStatus(422);

    Carbon::setTestNow('2026-06-01 15:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/shop?shop=black')->json('stock'))->toBe([]);
    Carbon::setTestNow('2026-06-01 03:20:00');
    expect(collect($this->actingAs($u)->getJson('/api/mascot/shop?shop=black')->json('stock'))->pluck('id'))->toContain('forbidden-voice-jar');
    coins($u, 'echoes', 10);
    $this->actingAs($u)->postJson('/api/mascot/shop/buy', ['shop' => 'black', 'id' => 'forbidden-voice-jar'])->assertOk();
    expect(app(SoulService::class)->for($u)->fresh()->state['corruption'])->toBeGreaterThanOrEqual(10);
    Carbon::setTestNow();
});

it('crafts, takes one offering a day, and levels up into skills', function () {
    $u = User::factory()->create();
    econSoul($u, ['inventory' => ['magnet', 'broken-pencil', 'bulb', 'blank-tape'], 'proximity' => 50]);
    $r = $this->actingAs($u)->postJson('/api/mascot/econ/craft', ['recipe' => 'amulet'])->assertOk()->json();
    expect($r['made'])->toBe('craft-amulet');
    $s = app(SoulService::class)->for($u)->fresh()->state;
    expect($s['proximity'])->toEqual(30)->and($s['amulet_until'])->not->toBeNull();
    $this->actingAs($u)->postJson('/api/mascot/econ/craft', ['recipe' => 'amulet'])->assertStatus(422);

    $this->actingAs($u)->postJson('/api/mascot/econ/offer', ['id' => 'bulb'])->assertOk();
    $this->actingAs($u)->postJson('/api/mascot/econ/offer', ['id' => 'blank-tape'])->assertStatus(422);

    $this->actingAs($u)->postJson('/api/mascot/econ/learn', ['branch' => 'genius'])->assertStatus(422); // level 1
    coins($u, 'minutes', 2000, 'active');
    $lv = $this->actingAs($u)->getJson('/api/mascot/econ')->json('level');
    expect($lv['level'])->toBeGreaterThanOrEqual(10)->and($lv['points'])->toBeGreaterThanOrEqual(2);
    $this->actingAs($u)->postJson('/api/mascot/econ/learn', ['branch' => 'genius'])->assertOk();
    expect(app(SoulService::class)->for($u)->fresh()->state['skills'])->toBe(['genius:1']);
});

it('trades between teammates only, re-checking at accept time', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $stranger = User::factory()->create();
    $board = Board::create(['user_id' => $a->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $b->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
    econSoul($a, ['inventory' => ['magnet', 'relic-first-death']]);
    econSoul($b, ['inventory' => ['bulb']]);
    coins($b, 'tokens', 5);

    $this->actingAs($a)->postJson('/api/mascot/trades', ['to' => $stranger->id, 'give' => 'magnet'])->assertStatus(422);
    $this->actingAs($a)->postJson('/api/mascot/trades', ['to' => $b->id, 'give' => 'relic-first-death'])->assertStatus(422); // unique
    $id = $this->actingAs($a)->postJson('/api/mascot/trades', ['to' => $b->id, 'give' => 'magnet', 'want' => 'bulb', 'want_tokens' => 3])->assertOk()->json('id');
    $this->actingAs($b)->postJson('/api/mascot/trades/respond', ['id' => $id, 'accept' => true])->assertOk();

    expect(app(SoulService::class)->for($a)->fresh()->state['inventory'])->toBe(['relic-first-death', 'bulb'])
        ->and(app(SoulService::class)->for($b)->fresh()->state['inventory'])->toBe(['magnet'])
        ->and(app(EconomyService::class)->balance($a->id)['tokens'])->toBe(3)
        ->and(app(EconomyService::class)->balance($b->id)['tokens'])->toBe(2);
});

it('only pays plausible arcade scores, settles bets and crowns a champion', function () {
    $a = User::factory()->create(['name' => 'Ana Lima']);
    $b = User::factory()->create();
    $board = Board::create(['user_id' => $a->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $b->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
    econSoul($a);
    coins($a, 'tokens', 10);

    Carbon::setTestNow('2026-06-03 10:00:00');
    $s = $this->actingAs($a)->postJson('/api/mascot/arcade/start', ['game' => 'whack', 'bet' => ['stake' => 5, 'target' => 30, 'twin' => 'wow']])->assertOk()->json('session');
    Carbon::setTestNow('2026-06-03 10:00:05');
    $this->actingAs($a)->postJson('/api/mascot/arcade/finish', ['session' => $s, 'score' => 40])->assertStatus(422); // too fast

    $s = $this->actingAs($a)->postJson('/api/mascot/arcade/start', ['game' => 'whack', 'bet' => ['stake' => 5, 'target' => 30, 'twin' => 'wow']])->assertOk()->json('session');
    Carbon::setTestNow('2026-06-03 10:00:20');
    $r = $this->actingAs($a)->postJson('/api/mascot/arcade/finish', ['session' => $s, 'score' => 999999])->assertOk()->json();
    expect($r['score'])->toBeLessThan(300)->and($r['tokens'])->toBe(10)->and($r['bet'])->toBe(['won' => true, 'pay' => 10, 'twin' => 'wow']);

    expect($this->actingAs($b)->getJson('/api/mascot/arcade/board?game=whack')->json('board.0.name'))->toBe('Ana');
    expect($this->actingAs($b)->getJson('/api/mascot/arcade')->json('champion.name'))->toBe('Ana Lima');
    $this->actingAs($a)->postJson('/api/mascot/arcade/start', ['game' => 'forbidden'])->assertStatus(422);
    Carbon::setTestNow();
});

it('draws one tarot card a day, spins the roulette once, and the golden tape goes to the first teammate', function () {
    $a = User::factory()->create(['name' => 'Ana Lima']);
    $b = User::factory()->create();
    $board = Board::create(['user_id' => $a->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $b->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
    econSoul($a);
    econSoul($b);

    $t1 = $this->actingAs($a)->postJson('/api/mascot/arcade/tarot', ['overdue' => 3])->assertOk()->json();
    expect($t1['id'])->toStartWith('tarot-')->and($t1['reading'])->not->toBe('');
    expect($this->actingAs($a)->postJson('/api/mascot/arcade/tarot')->json('id'))->toBe($t1['id']);
    expect(app(SoulService::class)->for($a)->fresh()->state['inventory'])->toContain($t1['id']);

    $this->actingAs($a)->postJson('/api/mascot/arcade/roulette')->assertOk();
    $this->actingAs($a)->postJson('/api/mascot/arcade/roulette')->assertStatus(422);

    $g = $this->actingAs($a)->getJson('/api/mascot/arcade/golden')->json();
    expect($g['found_by'])->toBeNull();
    $this->actingAs($b)->postJson('/api/mascot/arcade/golden', ['week' => $g['week']])->assertOk();
    $this->actingAs($a)->postJson('/api/mascot/arcade/golden', ['week' => $g['week']])->assertStatus(422);
    expect($this->actingAs($a)->getJson('/api/mascot/arcade/golden')->json('found_by'))->not->toBeNull();

    $h = $this->actingAs($a)->postJson('/api/mascot/arcade/hide')->assertOk()->json();
    expect($h['page'])->toStartWith('/')->and($h['session'])->toHaveLength(24);
});

it('computes 120 achievements from server facts, hides lore ones, and shares the album only by choice', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $board = Board::create(['user_id' => $a->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $b->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
    econSoul($a, ['counters' => ['done' => 12], 'fragments' => ['F01']]);
    $album = $this->actingAs($a)->getJson('/api/mascot/album')->assertOk()->json();
    $labels = collect($album['albums'])->flatMap(fn ($al) => $al['labels']);
    expect($labels)->toHaveCount(120);
    expect($labels->firstWhere('id', 'done-10')['got'])->toBeTrue()
        ->and($labels->firstWhere('id', 'done-25')['got'])->toBeFalse()
        ->and($labels->firstWhere('id', 'fragments-1')['got'])->toBeTrue()
        ->and($labels->firstWhere('id', 'deaths-1')['title'])->toBe('? ? ?');

    $this->actingAs($b)->getJson("/api/mascot/album/{$a->id}")->assertStatus(404);
    $this->actingAs($a)->postJson('/api/mascot/album/public', ['on' => true])->assertOk();
    $shared = $this->actingAs($b)->getJson("/api/mascot/album/{$a->id}")->assertOk()->json();
    expect(collect($shared['albums'])->flatMap(fn ($al) => $al['labels'])->every(fn ($l) => $l['got']))->toBeTrue();
});
