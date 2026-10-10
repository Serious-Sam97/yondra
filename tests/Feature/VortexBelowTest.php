<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function act($t, User $u, string $room, string $spot, string $verb, ?string $item = null)
{
    return $t->actingAs($u)->postJson('/api/mascot/below/act', array_filter(compact('room', 'spot', 'verb', 'item')))->assertOk()->json();
}

it('takes loose items once and rejects unknown rooms or verbs', function () {
    $u = User::factory()->create();
    $r = act($this, $u, 'cemiterio', 'gate', 'take');
    expect($r['give'])->toBe('flor-de-fita')->and($r['world']['inventory'])->toBe(['flor-de-fita']);
    $r = act($this, $u, 'cemiterio', 'gate', 'take');
    expect($r)->not->toHaveKey('give');
    $this->actingAs($u)->postJson('/api/mascot/below/act', ['room' => 'mars', 'spot' => 'x', 'verb' => 'take'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/below/act', ['room' => 'porao', 'spot' => 'x', 'verb' => 'eat'])->assertStatus(422);
});

it('the moth trades three forgotten words for the restricted shelf, which needs the diary key first', function () {
    $u = User::factory()->create();
    foreach (range(1, 3) as $i) {
        act($this, $u, 'cemiterio', 'graves', 'collect');
    }
    $r = act($this, $u, 'biblioteca', 'moth', 'talk');
    expect($r['world']['flags']['shelf_open'])->toBeTrue()->and($r['world']['inventory'])->toBe([]);

    $r = act($this, $u, 'biblioteca', 'restricted', 'read');
    expect($r)->not->toHaveKey('granted'); // F33 needs F29 first
    $r = act($this, $u, 'garagem', 'frame', 'take');
    expect($r['granted'])->toBe(['F29'])->and($r['give'])->toBe('chave-do-diario');
    foreach (['F33', 'F34', 'F35', 'F36'] as $f) {
        expect(act($this, $u, 'biblioteca', 'restricted', 'read')['granted'])->toBe([$f]);
    }
    expect(app(SoulService::class)->for($u)->state['diary_unlocked'])->toBeTrue();
});

it('the tea must reach the studio while hot', function () {
    $u = User::factory()->create();
    act($this, $u, 'garagem', 'chair', 'use');
    act($this, $u, 'garagem', 'tea', 'take');
    Carbon::setTestNow(now()->addSeconds(40));
    expect(act($this, $u, 'estudio', 'locutora', 'use'))->not->toHaveKey('granted');
    Carbon::setTestNow();
    act($this, $u, 'garagem', 'tea', 'take');
    expect(act($this, $u, 'estudio', 'locutora', 'use')['granted'])->toBe(['F38']);
});

it('the nameless stone shows the letters only at the dead hour, after a flower', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'chat']], 'tz' => 'America/Sao_Paulo']);
    act($this, $u, 'cemiterio', 'gate', 'take');
    act($this, $u, 'cemiterio', 'nameless', 'use', 'flor-de-fita');
    Carbon::setTestNow(Carbon::parse('2026-10-09 15:00', 'America/Sao_Paulo'));
    expect(act($this, $u, 'cemiterio', 'nameless', 'look'))->not->toHaveKey('granted');
    Carbon::setTestNow(Carbon::parse('2026-10-10 03:14', 'America/Sao_Paulo'));
    expect(act($this, $u, 'cemiterio', 'nameless', 'look')['granted'])->toBe(['F37']);
    Carbon::setTestNow();
});

it('pulls him back out of the mirror when the twin took his place', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['swapped'] = true;
    $soul->state = $s;
    $soul->save();
    act($this, $u, 'ladob', 'mirror', 'use');
    expect(app(SoulService::class)->for($u)->fresh()->state['swapped'])->toBeFalse();
});

it('builds the shortwave from five parts and tunes side c after the fourth head', function () {
    $u = User::factory()->create();
    foreach ([['clinica', 'jars'], ['fliperama', 'roof'], ['torre', 'gears'], ['estudio', 'desk']] as [$room, $spot]) {
        act($this, $u, $room, $spot, 'take');
    }
    $soul = app(SoulService::class)->for($u)->fresh();
    $s = $soul->state;
    $s['inventory'][] = 'cristal';
    $soul->state = $s;
    $soul->save();
    $r = act($this, $u, 'garagem', 'bench', 'use');
    expect($r['world']['flags']['shortwave'])->toBeTrue()->and($r['open'])->toBe('shortwave');
    expect(act($this, $u, 'garagem', 'shortwave', 'use'))->not->toHaveKey('granted');
    app(FragmentService::class)->grant(app(SoulService::class)->for($u)->fresh(), 'F51');
    expect(act($this, $u, 'garagem', 'shortwave', 'use')['granted'])->toBe(['F52']);
});

it('the graveyard and the tower read the user\'s real cards, nobody else\'s', function () {
    $u = User::factory()->create();
    $other = User::factory()->create();
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'To Do']);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'Dead one', 'description' => '', 'archived_at' => now()]);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'Late one', 'description' => '', 'due_date' => now()->subDays(3)]);
    $ob = Board::create(['user_id' => $other->id, 'name' => 'X', 'description' => '', 'type' => 'kanban']);
    $os = Section::create(['board_id' => $ob->id, 'name' => 'To Do']);
    Card::create(['board_id' => $ob->id, 'section_id' => $os->id, 'name' => 'Not yours', 'description' => '', 'archived_at' => now()]);

    $graves = $this->actingAs($u)->getJson('/api/mascot/below/graveyard')->assertOk()->json();
    expect(collect($graves)->pluck('name')->all())->toBe(['Dead one'])->and($graves[0]['epitaph'])->toContain('Dead one');
    $tower = $this->actingAs($u)->getJson('/api/mascot/below/tower')->assertOk()->json();
    expect(collect($tower)->pluck('name')->all())->toBe(['Late one'])->and($tower[0]['days'])->toBe(3);
});

it('purifies at the play altar for three rare things and makes him forget', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['corruption'] = 70;
    $s['inventory'] = ['fita-dourada', 'bilhete-1987', 'ficha'];
    $s['traits'] = ['night owl'];
    $soul->state = $s;
    $soul->save();
    // the client can't report a purification
    $this->actingAs($u)->postJson('/api/mascot/soul/events', ['events' => [['type' => 'purify']]])->assertStatus(422);
    // ficha isn't rare
    $this->actingAs($u)->postJson('/api/mascot/below/purify', ['items' => ['fita-dourada', 'bilhete-1987', 'ficha'], 'touches' => 0])->assertStatus(422);

    $s['inventory'] = ['fita-dourada', 'bilhete-1987', 'cristal', 'ficha'];
    $soul->state = $s;
    $soul->save();
    $r = $this->actingAs($u)->postJson('/api/mascot/below/purify', ['items' => ['fita-dourada', 'bilhete-1987', 'cristal'], 'touches' => 0])->assertOk()->json();
    expect($r['forgot'])->toHaveCount(1)->and($r['forgot'][0]['kind'])->toBe('trait')
        ->and($r['world']['inventory'])->toBe(['ficha', 'relic-purified']);
    $after = $soul->fresh()->state;
    expect($after['corruption'])->toEqual(0)->and($after['traits'])->toBe([]);
});

it('temporary rooms only exist on their day', function () {
    $u = User::factory()->create();
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['tz'] = 'UTC';
    $s['born'] = '2025-04-02T10:00:00Z';
    $soul->state = $s;
    $soul->save();
    Carbon::setTestNow('2026-10-31 12:00:00');
    $w = $this->actingAs($u)->getJson('/api/mascot/below')->json();
    expect($w['temporary'])->toBe(['feira']);
    expect($this->actingAs($u)->postJson('/api/mascot/below/visit', ['room' => 'baile'])->json('closed'))->toBeTrue();
    Carbon::setTestNow('2026-04-02 09:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/below')->json('temporary'))->toBe(['baile']);
    expect($this->actingAs($u)->postJson('/api/mascot/below/visit', ['room' => 'baile'])->json())->not->toHaveKey('closed');
    Carbon::setTestNow();
});

it('shows teammates down here (never strangers) and delivers waves', function () {
    $me = User::factory()->create(['name' => 'Ana Lima']);
    $mate = User::factory()->create(['name' => 'Bruno Costa']);
    $stranger = User::factory()->create();
    $b = Board::create(['user_id' => $me->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $b->id, 'user_id' => $mate->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($mate)->getJson('/api/mascot/below/presence?room=porao')->assertOk();
    $this->actingAs($stranger)->getJson('/api/mascot/below/presence?room=porao')->assertOk();
    $v = $this->actingAs($me)->getJson('/api/mascot/below/presence?room=porao')->json();
    expect($v['visitors'])->toBe([['id' => $mate->id, 'name' => 'Bruno']]);

    $this->actingAs($me)->postJson('/api/mascot/below/wave', ['to' => $stranger->id, 'room' => 'porao'])->assertJson(['ok' => false]);
    $this->actingAs($me)->postJson('/api/mascot/below/wave', ['to' => $mate->id, 'room' => 'porao'])->assertJson(['ok' => true]);
    expect($this->actingAs($mate)->getJson('/api/mascot/below/presence?room=porao')->json('waves'))->toBe(['Ana']);
});

it('cuts out a real forgotten word: archived-only, never one a live card still uses', function () {
    $u = User::factory()->create();
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'To Do']);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'Synergy roadmap', 'description' => '', 'archived_at' => now()]);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'roadmap review', 'description' => '']);
    $r = act($this, $u, 'cemiterio', 'graves', 'collect');
    expect($r['say'])->toContain('"synergy"')->not->toContain('roadmap');
});
