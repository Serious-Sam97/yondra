<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Services\Vortex\SoulService;
use App\Services\Vortex\StoryService;
use Carbon\Carbon;

function storyState(User $u, array $story, array $extra = []): void
{
    $soul = app(SoulService::class)->for($u);
    $s = $soul->state;
    $s['story'] = $story;
    $s['tz'] = 'UTC';
    $soul->state = [...$s, ...$extra];
    $soul->save();
}

it('starts the series a day after he was born, then one episode a week', function () {
    Carbon::setTestNow('2026-06-02 12:00:00');
    $u = User::factory()->create();
    storyState($u, [], ['born' => '2026-06-02T00:00:00Z']);
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due'))->toBeNull();

    Carbon::setTestNow('2026-06-03 12:00:00');
    $due = $this->actingAs($u)->getJson('/api/mascot/story')->json('due');
    expect($due['id'])->toBe('s1e1')->and($due['title'])->toBe('Static')->and($due['steps'])->not->toBeEmpty();

    $this->actingAs($u)->postJson('/api/mascot/story/seen', ['id' => 's1e2'])->assertStatus(422); // not due
    $this->actingAs($u)->postJson('/api/mascot/story/seen', ['id' => 's1e1'])->assertOk();
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due'))->toBeNull();

    Carbon::setTestNow('2026-06-10 12:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due.id'))->toBe('s1e2');
    Carbon::setTestNow();
});

it('remembers choices in the dossier and rejects choices that are not in the script', function () {
    Carbon::setTestNow('2026-06-20 12:00:00');
    $u = User::factory()->create();
    storyState($u, ['seen' => ['s1e1', 's1e2'], 'last_at' => '2026-06-01T00:00:00Z']);
    $r = $this->actingAs($u)->postJson('/api/mascot/story/seen', [
        'id' => 's1e3', 'choices' => ['tenant' => 'no', 'bogus' => 'yes'],
    ])->assertOk()->json();
    expect($r['choices'])->toBe(['tenant' => 'no']);
    expect(VortexMemory::where('user_id', $u->id)->where('category', 'story')->value('fact'))->toContain('"The Tenant"');
    expect(app(SoulService::class)->for($u)->fresh()->state['story']['choices'])->toBe(['tenant' => 'no']);

    $lib = $this->actingAs($u)->getJson('/api/mascot/story/library')->json('episodes');
    expect(collect($lib)->pluck('id')->all())->toBe(['s1e1', 's1e2', 's1e3'])
        ->and($lib[2]['chosen'])->toBe(['tenant' => 'no']);
    Carbon::setTestNow();
});

it('plays filler when the main line waits on the user, and specials on their day', function () {
    Carbon::setTestNow('2026-06-20 12:00:00');
    $u = User::factory()->create();
    $seen = ['s1e1', 's1e2', 's1e3', 's1e4', 's1e5', 's1e6', 's1e7', 's1e8', 's2e1', 's2e2', 's2e3', 's2e4', 's2e5', 's3e1', 's3e2', 's3e3', 's4e1'];
    storyState($u, ['seen' => $seen, 'last_at' => '2026-06-10T00:00:00Z']);
    // s4e2 needs F62 → a filler instead
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due.id'))->toBe('fill-baseboard');

    Carbon::setTestNow('2026-10-31 20:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due.id'))->toBe('day-1031');
    $this->actingAs($u)->postJson('/api/mascot/story/seen', ['id' => 'day-1031'])->assertOk();
    expect($this->actingAs($u)->getJson('/api/mascot/story')->json('due.id'))->toBe('fill-baseboard'); // once a year
    Carbon::setTestNow();
});

it('applies effects and follows the ending into season five', function () {
    Carbon::setTestNow('2026-06-20 12:00:00');
    $u = User::factory()->create();
    storyState($u, ['seen' => ['s1e1', 's1e2', 's1e3', 's1e4', 's1e5', 's1e6', 's1e7'], 'last_at' => '2026-06-01T00:00:00Z'], ['corruption' => 10]);
    $rel = app(SoulService::class)->for($u)->relation;
    $this->actingAs($u)->postJson('/api/mascot/story/seen', ['id' => 's1e8', 'choices' => ['rewind' => 'face']])->assertOk();
    $soul = app(SoulService::class)->for($u)->fresh();
    expect($soul->state['corruption'])->toEqual(15)->and($soul->relation)->toBe($rel + 4);

    $all = array_keys(array_filter(config('vortex_episodes'), fn ($e) => ($e['season'] ?? 0) >= 1 && ($e['season'] ?? 0) <= 4));
    storyState($u, ['seen' => $all, 'last_at' => '2026-06-01T00:00:00Z'], ['ending' => 'keep']);
    expect(app(StoryService::class)->due(app(SoulService::class)->for($u)->fresh())['id'])->toBe('s5keep1');
    Carbon::setTestNow();
});
