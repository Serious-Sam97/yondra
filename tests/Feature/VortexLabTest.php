<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;

function labSoul(User $u, array $extra = [])
{
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', ...$extra];
    $soul->save();

    return $soul;
}

it('invents what annoys him, builds it over days, and parts speed it up', function () {
    config(['services.vortex.dev' => false]);
    $u = User::factory()->create();
    labSoul($u, ['counters' => ['scrolled' => 6], 'inventory' => ['part-capacitor', 'magnet']]);
    Carbon::setTestNow('2026-06-01 12:00:00');
    $v = $this->actingAs($u)->getJson('/api/mascot/lab')->assertOk()->json();
    expect($v['building']['id'])->toBe('telescope')->and($v['ready'])->toBe([]);

    $this->actingAs($u)->postJson('/api/mascot/lab/accelerate', ['item' => 'magnet'])->assertStatus(422);
    $before = $v['building']['ready_at'];
    $r = $this->actingAs($u)->postJson('/api/mascot/lab/accelerate', ['item' => 'part-capacitor'])->assertOk()->json();
    expect(Carbon::parse($r['lab']['building']['ready_at'])->diffInHours(Carbon::parse($before), true))->toEqual(12);

    Carbon::setTestNow('2026-06-05 12:00:00');
    $v = $this->actingAs($u)->getJson('/api/mascot/lab')->json();
    $last = $v['last'];
    expect($last['type'])->toBeIn(['ready', 'exploded']);
    if ($last['type'] === 'ready') {
        expect(collect($v['ready'])->pluck('id'))->toContain('telescope');
    }
    expect($v['building'])->not->toBeNull(); // the next thing is already on the bench
    Carbon::setTestNow();
});

it('gadgets only work once built, and the time machine rewinds the board from history', function () {
    $u = User::factory()->create();
    $soul = labSoul($u);
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $todo = Section::create(['board_id' => $b->id, 'name' => 'To Do', 'position' => 0]);
    $done = Section::create(['board_id' => $b->id, 'name' => 'Done', 'position' => 1]);
    Carbon::setTestNow('2026-06-01 10:00:00');
    $card = Card::create(['board_id' => $b->id, 'section_id' => $todo->id, 'name' => 'ship it', 'description' => '']);
    Carbon::setTestNow('2026-06-10 10:00:00');
    $card->update(['section_id' => $done->id]);
    Carbon::setTestNow('2026-06-12 10:00:00');

    $this->actingAs($u)->getJson("/api/mascot/lab/snapshot?board={$b->id}&days=5")->assertStatus(403);
    $s = $soul->fresh();
    $s->state = [...$s->state, 'lab' => ['building' => null, 'ready' => ['timemachine', 'compass', 'xray'], 'built' => 3, 'last' => null]];
    $s->save();

    $snap = $this->actingAs($u)->getJson("/api/mascot/lab/snapshot?board={$b->id}&days=5")->assertOk()->json();
    expect($snap['columns'][0]['cards'][0]['name'])->toBe('ship it')->and($snap['columns'][1]['cards'])->toBe([]);
    $now = $this->actingAs($u)->getJson("/api/mascot/lab/snapshot?board={$b->id}&days=1")->json();
    expect($now['columns'][1]['cards'][0]['name'])->toBe('ship it');
    expect($this->actingAs($u)->getJson("/api/mascot/lab/snapshot?board={$b->id}&days=40")->json('ghost.name'))->toBe('make it remember me');

    $other = User::factory()->create();
    labSoul($other, ['lab' => ['building' => null, 'ready' => ['timemachine'], 'built' => 1, 'last' => null]]);
    $this->actingAs($other)->getJson("/api/mascot/lab/snapshot?board={$b->id}&days=5")->assertStatus(404);

    $x = $this->actingAs($u)->getJson("/api/mascot/lab/xray?board={$b->id}")->assertOk()->json('cards');
    expect($x[0]['id'])->toBe($card->id);
    Carbon::setTestNow();
});

it('points the compass at the most urgent card', function () {
    $u = User::factory()->create();
    labSoul($u, ['lab' => ['building' => null, 'ready' => ['compass'], 'built' => 1, 'last' => null]]);
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'To Do']);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'someday', 'description' => '', 'due_date' => now()->addDays(20)]);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'on fire', 'description' => '', 'due_date' => now()->subDays(3), 'priority' => 'urgent', 'assigned_user_id' => $u->id]);
    $c = $this->actingAs($u)->getJson('/api/mascot/lab/compass')->assertOk()->json('card');
    expect($c['name'])->toBe('on fire')->and($c['why'])->toContain('3 days late');
});

it('excuses and distills without the model too', function () {
    $u = User::factory()->create();
    labSoul($u, ['lab' => ['building' => null, 'ready' => ['excuses', 'distiller'], 'built' => 2, 'last' => null]]);
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'refactor auth', 'description' => '']);
    $e = $this->actingAs($u)->postJson('/api/mascot/lab/excuses', ['card' => $card->id])->assertOk()->json();
    expect($e)->toHaveKeys(['plausible', 'creative', 'cosmic']);
    $d = $this->actingAs($u)->postJson('/api/mascot/lab/distill', ['text' => "we agreed to fix the login\nmaria will draft the pricing page\nnext sync friday"])->assertOk()->json();
    expect($d)->toHaveKeys(['cards', 'jab']);
});
