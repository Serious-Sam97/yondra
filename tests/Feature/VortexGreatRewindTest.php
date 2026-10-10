<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexWorldState;
use App\Services\Vortex\GreatRewindService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function doneCards(User $u, int $n, string $at): void
{
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'Done']);
    foreach (range(1, $n) as $i) {
        Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => "c{$i}", 'description' => '', 'done_at' => $at]);
    }
}

it('is announced two weeks ahead, counts real completions while live, and settles once', function () {
    $u = User::factory()->create();
    $rewind = app(GreatRewindService::class);
    Carbon::setTestNow('2026-10-20 12:00:00');
    expect($this->actingAs($u)->getJson('/api/mascot/great-rewind')->json('phase'))->toBe('none');

    $this->artisan('vortex:great-rewind', ['start' => '2026-10-31 21:00', '--minutes' => 60, '--goal' => 3])->assertSuccessful();
    expect($this->actingAs($u)->getJson('/api/mascot/great-rewind')->json('phase'))->toBe('announced');

    doneCards($u, 2, '2026-10-31 20:30:00'); // before the hour: doesn't count
    Carbon::setTestNow('2026-10-31 21:10:00');
    doneCards($u, 2, '2026-10-31 21:05:00');
    Cache::flush();
    $v = $this->actingAs($u)->getJson('/api/mascot/great-rewind')->json();
    expect($v['phase'])->toBe('live')->and($v['saved'])->toBe(2)->and($v['goal'])->toBe(3);

    Carbon::setTestNow('2026-10-31 22:30:00');
    $hours = (int) (VortexWorldState::find('tape')?->value['hours'] ?? 0);
    expect($rewind->resolve())->toBe('rewound');
    expect((int) VortexWorldState::find('tape')->value['hours'])->toBe($hours + 1095);
    expect($rewind->resolve())->toBe('rewound'); // once
    expect((int) VortexWorldState::find('tape')->value['hours'])->toBe($hours + 1095);
    expect($this->actingAs($u)->getJson('/api/mascot/great-rewind')->json('result'))->toBe('rewound');
    Carbon::setTestNow();
});

it('holds when the community hits the goal', function () {
    $u = User::factory()->create();
    $rewind = app(GreatRewindService::class);
    $rewind->schedule(CarbonImmutable::parse('2026-03-13 13:00'), 60, 2);
    doneCards($u, 2, '2026-03-13 13:30:00');
    Carbon::setTestNow('2026-03-13 15:00:00');
    expect($rewind->resolve())->toBe('held');
    Carbon::setTestNow();
});
