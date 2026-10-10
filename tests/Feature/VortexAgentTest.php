<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Services\AiAssistService;
use App\Services\Vortex\SoulService;
use Carbon\Carbon;

it('parses a batch contract, keeps only whitelisted entries and caps it at twenty', function () {
    $line = 'ACTIONS:'.json_encode([
        ['kind' => 'move_card', 'board_id' => 7, 'card_id' => 42, 'card_name' => 'fix login', 'column' => 'Done'],
        ['kind' => 'set_due', 'board_id' => 7, 'card_id' => 43, 'due' => '2026-12-31'],
        ['kind' => 'set_due', 'board_id' => 7, 'card_id' => 44, 'due' => 'next tuesday'],
        ['kind' => 'add_comment', 'board_id' => 7, 'card_id' => 45, 'text' => 'any news?'],
        ['kind' => 'drop_database'],
        ['kind' => 'archive_card', 'board_id' => '7', 'card_id' => '46'],
    ]);
    [$clean, $actions, $faust] = AiAssistService::extractVortexActions("sign below, coward.\n".$line);
    expect($clean)->toBe('sign below, coward.')->and($faust)->toBeFalse()
        ->and(collect($actions)->pluck('kind')->all())->toBe(['move_card', 'set_due', 'add_comment']); // string ids are refused

    $many = 'ACTIONS:'.json_encode(array_fill(0, 30, ['kind' => 'archive_card', 'board_id' => 1, 'card_id' => 2]));
    expect(AiAssistService::extractVortexActions($many)[1])->toHaveCount(20);

    $devil = 'ACTIONS:'.json_encode(['faust' => true, 'actions' => [['kind' => 'rename_card', 'board_id' => 1, 'card_id' => 2, 'name' => 'better']]]);
    expect(AiAssistService::extractVortexActions($devil)[2])->toBeTrue();

    // the old single form still works
    [, $one] = AiAssistService::extractVortexAction('ok'."\n".'ACTION:{"kind":"add_column","board_id":3,"name":"QA"}');
    expect($one)->toBe(['kind' => 'add_column', 'board_id' => 3, 'name' => 'QA']);
});

it('delivers reminders once, with a comment on how long you took', function () {
    $u = User::factory()->create();
    Carbon::setTestNow('2026-06-01 09:00:00');
    $this->actingAs($u)->postJson('/api/mascot/reminders', ['text' => 'send the invoice', 'at' => '2026-05-01T10:00:00Z'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/mascot/reminders', ['text' => 'send the invoice', 'at' => '2026-06-02T08:00:00Z'])->assertOk();
    expect($this->actingAs($u)->getJson('/api/mascot/reminders/due')->json('due'))->toBe([]);
    Carbon::setTestNow('2026-06-02 08:05:00');
    $due = $this->actingAs($u)->getJson('/api/mascot/reminders/due')->json('due');
    expect($due)->toHaveCount(1)->and($due[0]['text'])->toBe('send the invoice')->and($due[0]['jab'])->toContain('reliable');
    expect($this->actingAs($u)->getJson('/api/mascot/reminders/due')->json('due'))->toBe([]);
    Carbon::setTestNow();
});

it('finds quietly blocked cards and checks a card before Done', function () {
    $u = User::factory()->create();
    $b = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $b->id, 'name' => 'Doing']);
    Carbon::setTestNow('2026-06-01 09:00:00');
    $stuck = Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'api keys', 'description' => '']);
    CardComment::create(['card_id' => $stuck->id, 'user_id' => $u->id, 'body' => 'waiting on @bruno for the keys']);
    Card::create(['board_id' => $b->id, 'section_id' => $s->id, 'name' => 'quiet one', 'description' => 'x']);
    Carbon::setTestNow('2026-06-08 09:00:00');
    $r = $this->actingAs($u)->getJson('/api/mascot/agent/blockers')->assertOk()->json('cards');
    expect($r)->toHaveCount(1)->and($r[0]['name'])->toBe('api keys')->and($r[0]['who'])->toBe('bruno')->and($r[0]['days'])->toBe(7);

    $c = $this->actingAs($u)->getJson("/api/mascot/agent/done-check?card={$stuck->id}")->assertOk()->json();
    expect($c['has_description'])->toBeFalse();
    $other = User::factory()->create();
    $this->actingAs($other)->getJson("/api/mascot/agent/done-check?card={$stuck->id}")->assertStatus(404);
    Carbon::setTestNow();
});

it('the devil takes a cosmetic price', function () {
    $u = User::factory()->create(['name' => 'Ana Lima']);
    $soul = app(SoulService::class)->for($u);
    $r = $this->actingAs($u)->postJson('/api/mascot/agent/faust')->assertOk()->json();
    expect($r['took'])->toBeIn(['nickname', 'color', 'item']);
    if ($r['took'] === 'nickname') {
        expect($this->actingAs($u)->getJson('/api/mascot/soul')->json('nickname'))->toBe('the signee');
    }
    expect((int) $soul->fresh()->relation)->toBe((int) $soul->relation);
});
