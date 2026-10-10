<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexDedication;
use App\Services\Vortex\RadioService;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function radioSoul(User $u, array $extra = []): void
{
    $soul = app(SoulService::class)->for($u);
    $soul->state = [...$soul->state, 'tz' => 'UTC', ...$extra];
    $soul->save();
}

function mates(User $a, User $b): void
{
    $board = Board::create(['user_id' => $a->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    DB::table('board_shares')->insert(['board_id' => $board->id, 'user_id' => $b->id, 'permission' => 'edit', 'created_at' => now(), 'updated_at' => now()]);
}

it('follows the schedule in the user timezone and hides a backwards song in overtime', function () {
    $u = User::factory()->create();
    radioSoul($u);
    Carbon::setTestNow('2026-06-02 19:00:00');
    $r = $this->actingAs($u)->getJson('/api/mascot/radio')->assertOk()->json();
    expect($r['program'])->toBe('overtime')->and($r['backwards'])->toBe(3)->and($r['playlist'])->toHaveCount(6);
    Carbon::setTestNow('2026-06-02 03:15:00');
    expect($this->actingAs($u)->getJson('/api/mascot/radio')->json('program'))->toBe('dead-air-live');
    Carbon::setTestNow();
});

it('grants the night interference and dead air only at the right hour, with the radio on', function () {
    $u = User::factory()->create();
    radioSoul($u);
    Carbon::setTestNow('2026-06-02 14:00:00');
    $this->actingAs($u)->postJson('/api/mascot/radio/on')->assertOk();
    Carbon::setTestNow('2026-06-02 14:10:00');
    expect($this->actingAs($u)->postJson('/api/mascot/radio/heard', ['what' => 'interference'])->json('granted'))->toBe([]);

    Carbon::setTestNow('2026-06-02 23:00:00');
    $this->actingAs($u)->postJson('/api/mascot/radio/on');
    Carbon::setTestNow('2026-06-02 23:01:00');
    expect($this->actingAs($u)->postJson('/api/mascot/radio/heard', ['what' => 'interference'])->json('granted'))->toBe([]); // too soon
    Carbon::setTestNow('2026-06-02 23:05:00');
    expect($this->actingAs($u)->postJson('/api/mascot/radio/heard', ['what' => 'interference'])->json('granted'))->toBe(['F22']);

    Carbon::setTestNow('2026-06-03 03:14:00');
    $this->actingAs($u)->postJson('/api/mascot/radio/on');
    Carbon::setTestNow('2026-06-03 03:16:00');
    expect($this->actingAs($u)->postJson('/api/mascot/radio/heard', ['what' => 'dead-air'])->json('granted'))->toBe(['F23']);
    Carbon::setTestNow();
});

it('only records the rare tape that is actually airing', function () {
    $u = User::factory()->create();
    radioSoul($u);
    $radio = app(RadioService::class);
    // find an hour where a rare tape airs for this user
    $airing = null;
    for ($h = 0; $h < 200 && ! $airing; $h++) {
        Carbon::setTestNow(Carbon::parse('2026-06-01 00:00:00')->addHours($h));
        $airing = $radio->now($u, app(SoulService::class)->for($u))['rare'];
    }
    expect($airing)->not->toBeNull();
    $other = collect(array_keys(RadioService::RARE))->first(fn ($id) => $id !== $airing['id']);
    expect($this->actingAs($u)->postJson('/api/mascot/radio/rec', ['id' => $other])->json('ok'))->toBeFalse();
    expect($this->actingAs($u)->postJson('/api/mascot/radio/rec', ['id' => $airing['id']])->json('ok'))->toBeTrue();
    expect(app(SoulService::class)->for($u)->fresh()->state['radio_tapes'])->toBe([$airing['id']]);
    Carbon::setTestNow();
});

it('sends dedications to teammates only, reads them on air once, and respects the off switch', function () {
    $a = User::factory()->create(['name' => 'Ana Lima']);
    $b = User::factory()->create();
    $stranger = User::factory()->create();
    mates($a, $b);
    radioSoul($b);
    $this->actingAs($a)->postJson('/api/mascot/radio/dedicate', ['to' => $stranger->id, 'text' => 'hello there'])->assertStatus(422);
    $this->actingAs($a)->postJson('/api/mascot/radio/dedicate', ['to' => $b->id, 'text' => 'you are a puta'])->assertStatus(422);
    $this->actingAs($a)->postJson('/api/mascot/radio/dedicate', ['to' => $b->id, 'text' => 'for closing 12 cards today'])->assertOk();
    expect($b->fresh()->notifications()->count())->toBe(1);

    $r = $this->actingAs($b)->getJson('/api/mascot/radio')->json('dedications');
    expect($r)->toBe([['from' => 'Ana', 'text' => 'for closing 12 cards today']]);
    expect($this->actingAs($b)->getJson('/api/mascot/radio')->json('dedications'))->toBe([]);

    $b->notification_preferences = ['radio' => ['in_app' => false]];
    $b->save();
    $this->actingAs($a)->postJson('/api/mascot/radio/dedicate', ['to' => $b->id, 'text' => 'another one'])->assertStatus(422);
    expect(VortexDedication::count())->toBe(1);
});

it('writes the void hour once a week and prints the gazette', function () {
    $u = User::factory()->create();
    radioSoul($u);
    $show = $this->actingAs($u)->getJson('/api/mascot/radio/void-hour')->assertOk()->json();
    expect($show['lines'])->not->toBeEmpty()->and($show['week'])->toBeString();
    expect($this->actingAs($u)->getJson('/api/mascot/radio/void-hour')->json())->toBe($show);

    $g = $this->actingAs($u)->getJson('/api/mascot/gazette')->assertOk()->json();
    expect($g['headlines'])->not->toBeEmpty()->and($g['classifieds'])->toHaveCount(4)->and($g['advice'])->toHaveKeys(['q', 'a']);
});
