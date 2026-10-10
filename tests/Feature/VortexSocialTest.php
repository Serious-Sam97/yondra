<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\User;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use Illuminate\Support\Facades\DB;

function teamOf(int $n): array
{
    $users = [];
    for ($i = 0; $i < $n; $i++) {
        $users[] = User::factory()->create(['name' => ['Ana Lima', 'Bruno Reis', 'Caio Melo', 'Duda Paz'][$i]]);
    }
    $board = Board::create(['user_id' => $users[0]->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    foreach (array_slice($users, 1) as $u) {
        DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $u->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$users, $board];
}

function social(User $u, bool $on = true, array $extra = []): void
{
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', 'social' => $on, ...$extra];
    $soul->save();
}

it('only shows ghosts of teammates who opted in, and gossips about ghosts only', function () {
    [[$a, $b, $c]] = teamOf(3);
    social($a);
    social($b, true, ['deaths' => 3]);
    social($c, false);
    $r = $this->actingAs($a)->getJson('/api/mascot/social')->assertOk()->json();
    expect(collect($r['ghosts'])->pluck('owner')->all())->toBe(['Bruno'])
        ->and($r['gossip'][0])->toContain("Bruno's ghost has died 3 times");
    social($a, false);
    expect($this->actingAs($a)->getJson('/api/mascot/social')->json('ghosts'))->toBe([]);
});

it('ghosts meet and drift into a relation; the oldest living one is the elder', function () {
    [[$a, $b]] = teamOf(2);
    social($a, true, ['born' => now()->subDays(2)->toIso8601String()]);
    social($b, true, ['born' => now()->subDays(40)->toIso8601String()]);
    $m = $this->actingAs($a)->postJson('/api/mascot/social/met', ['user' => $b->id])->assertOk()->json();
    expect($m['exchange'])->toHaveCount(3)->and($m['kind'])->toBeIn(['love', 'friends', 'rivals', 'contempt', 'strangers']);
    expect($this->actingAs($a)->getJson('/api/mascot/social')->json('elder.owner'))->toBe('Bruno');
});

it('a group prank fires only when a second teammate joins, then lands in the target inbox', function () {
    [[$a, $b, $c]] = teamOf(3);
    social($a);
    social($b);
    social($c);
    expect($this->actingAs($a)->postJson('/api/mascot/social/prank', ['target' => $c->id])->json('fired'))->toBeFalse();
    expect($this->actingAs($c)->getJson('/api/mascot/social')->json('inbox.attack'))->toBeNull();
    expect($this->actingAs($b)->postJson('/api/mascot/social/prank', ['target' => $c->id])->json('fired'))->toBeTrue();
    expect($this->actingAs($c)->getJson('/api/mascot/social')->json('inbox.attack'))->toBe(['Ana', 'Bruno']);
});

it('the static choir needs three voices at once', function () {
    [[$a, $b, $c], $board] = teamOf(3);
    foreach ([$a, $b] as $i => $u) {
        expect($this->actingAs($u)->postJson('/api/mascot/social/choir', ['board' => $board->id, 'note' => $i * 4])->json('chord'))->toBeFalse();
    }
    expect($this->actingAs($c)->postJson('/api/mascot/social/choir', ['board' => $board->id, 'note' => 7])->json('chord'))->toBeTrue();
});

it('plaques once a week, factions at level 20, the cult needs thirty fragments, admins moderate', function () {
    [[$a, $b]] = teamOf(2);
    social($a);
    social($b);
    $this->actingAs($a)->postJson('/api/mascot/social/plaque', ['to' => $b->id, 'reason' => 'closed the scary card'])->assertOk();
    $this->actingAs($a)->postJson('/api/mascot/social/plaque', ['to' => $b->id, 'reason' => 'again'])->assertStatus(422);
    expect($this->actingAs($b)->getJson('/api/mascot/social')->json('inbox.plaques.0.from_name'))->toBe('Ana');

    $this->actingAs($a)->postJson('/api/mascot/social/join', ['faction' => 'listeners'])->assertStatus(422);
    $this->actingAs($a)->postJson('/api/mascot/social/join', ['faction' => 'cult'])->assertStatus(422);

    $this->actingAs($a)->postJson('/api/mascot/admin/moderation', ['social_off' => true])->assertStatus(403);
    $a->is_admin = true;
    $a->save();
    $this->actingAs($a)->postJson('/api/mascot/admin/moderation', ['social_off' => true])->assertOk();
    expect($this->actingAs($b)->getJson('/api/mascot/social')->json('ghosts'))->toBe([]);
});

it('tells the team someone found something, without saying what', function () {
    [[$a, $b]] = teamOf(2);
    social($a);
    social($b);
    app(FragmentService::class)->grant(app(SoulService::class)->for($a), 'F01');
    $g = $this->actingAs($b)->getJson('/api/mascot/social')->json('gossip');
    expect(collect($g)->first(fn ($l) => str_contains($l, 'found something')))->not->toBeNull();
});
