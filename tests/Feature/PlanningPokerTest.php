<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\PlanningSession;
use App\Infrastructure\Models\PlanningVote;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function scrumCard(User $owner): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Story', 'description' => '']);

    return [$board, $card];
}

it('join adds the user as a participant with no vote yet', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);

    $res = $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/planning/join")
        ->assertOk();

    expect($res->json('participants'))->toHaveCount(1)
        ->and($res->json('participants.0.user_id'))->toBe($owner->id)
        ->and($res->json('participants.0.has_voted'))->toBeFalse()
        ->and($res->json('participants.0.value'))->toBeNull()
        ->and($res->json('revealed'))->toBeFalse();
    expect(PlanningSession::where('card_id', $card->id)->exists())->toBeTrue();
});

it('hides vote values until the round is revealed', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $res = $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '5'])->assertOk();

    // Voted, but the value must NOT leak before reveal.
    expect($res->json('participants.0.has_voted'))->toBeTrue()
        ->and($res->json('participants.0.value'))->toBeNull();

    $revealed = $this->actingAs($owner)->postJson("{$base}/reveal")->assertOk();
    expect($revealed->json('revealed'))->toBeTrue()
        ->and($revealed->json('participants.0.value'))->toBe('5');
});

it('rejects voting once the round is revealed', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/reveal")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '8'])->assertStatus(422);
});

it('reset bumps the round, keeps participants, and clears votes', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '3'])->assertOk();
    $this->actingAs($owner)->postJson("{$base}/reveal")->assertOk();

    $res = $this->actingAs($owner)->postJson("{$base}/reset")->assertOk();
    expect($res->json('round'))->toBe(2)
        ->and($res->json('revealed'))->toBeFalse()
        ->and($res->json('participants'))->toHaveCount(1)
        ->and($res->json('participants.0.has_voted'))->toBeFalse();
});

it('applies the chosen estimate to the card story points', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/apply", ['value' => 8])->assertOk();

    expect(Card::find($card->id)->story_points)->toBe(8);
});

it('tracks multiple participants and their votes', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    // A read-only collaborator can still join and vote (participation is open to
    // anyone with board access).
    $board->sharedWith()->attach($mate->id, ['permission' => 'read']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($mate)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '5'])->assertOk();
    $res = $this->actingAs($mate)->postJson("{$base}/vote", ['value' => '13'])->assertOk();

    expect($res->json('participants'))->toHaveCount(2);
    // One has voted, snapshot still hides values pre-reveal.
    expect(collect($res->json('participants'))->every(fn ($p) => $p['value'] === null))->toBeTrue();
});

it('is unavailable on non-scrum boards', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Kanban', 'description' => '', 'type' => 'kanban']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/planning/join")
        ->assertStatus(422);
});

it('returns my own vote pre-reveal (my_value) while participants stay hidden', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $res = $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '5'])->assertOk();

    expect($res->json('my_value'))->toBe('5')
        ->and($res->json('participants.0.value'))->toBeNull();

    // The GET snapshot also carries it, so a reloaded client recovers its hand.
    $show = $this->actingAs($owner)->getJson($base)->assertOk();
    expect($show->json('my_value'))->toBe('5');
});

it('forbids reveal and reset for non-facilitator read-only participants', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'read']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    // Owner starts the session — owner is the facilitator.
    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($mate)->postJson("{$base}/join")->assertOk();

    $this->actingAs($mate)->postJson("{$base}/reveal")->assertStatus(403);
    $this->actingAs($mate)->postJson("{$base}/reset")->assertStatus(403);
    $this->actingAs($owner)->postJson("{$base}/reveal")->assertOk();
});

it('lets a read-only facilitator moderate the session they started', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'read']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    // Mate starts the session, so the mate holds the chair despite read-only access.
    $res = $this->actingAs($mate)->postJson("{$base}/join")->assertOk();
    expect($res->json('facilitator_id'))->toBe($mate->id);

    $this->actingAs($mate)->postJson("{$base}/reveal")->assertOk();
});

it('passes the facilitator chair on leave', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'write']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($mate)->postJson("{$base}/join")->assertOk();

    $res = $this->actingAs($owner)->postJson("{$base}/leave")->assertOk();
    expect($res->json('facilitator_id'))->toBe($mate->id);
});

it('sweeps silent non-voters but keeps cast votes', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'write']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '5'])->assertOk();
    $this->actingAs($mate)->postJson("{$base}/join")->assertOk();

    // Both go silent past the presence TTL; only the non-voter is swept.
    PlanningVote::query()->update([
        'last_seen_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $res = $this->actingAs($owner)->getJson($base)->assertOk();
    expect($res->json('participants'))->toHaveCount(1)
        ->and($res->json('participants.0.user_id'))->toBe($owner->id);
});

it('closes the session when the sweep empties the round', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    PlanningVote::query()->update([
        'last_seen_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($owner)->getJson($base)->assertNoContent();
    expect(PlanningSession::where('card_id', $card->id)->exists())->toBeFalse();
});

it('refreshes presence via ping', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    PlanningVote::query()->update([
        'last_seen_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    // The ping refreshes my seat BEFORE the sweep runs, so I survive it.
    $res = $this->actingAs($owner)->postJson("{$base}/ping")->assertOk();
    expect($res->json('participants'))->toHaveCount(1);
});

it('exposes closed rounds as history after a reset', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '3'])->assertOk();
    $this->actingAs($owner)->postJson("{$base}/reveal")->assertOk();
    $res = $this->actingAs($owner)->postJson("{$base}/reset")->assertOk();

    expect($res->json('history'))->toHaveCount(1)
        ->and($res->json('history.0.round'))->toBe(1)
        ->and($res->json('history.0.votes.0.value'))->toBe('3');
});

it('stamps applied_at when an estimate is committed', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $res = $this->actingAs($owner)->postJson("{$base}/apply", ['value' => 8])->assertOk();

    expect($res->json('applied_value'))->toBe(8)
        ->and($res->json('applied_at'))->not->toBeNull();
});

it('deals the deck chosen at session creation and rejects off-deck cards', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $res = $this->actingAs($owner)->postJson("{$base}/join", ['deck' => 'fib-x'])->assertOk();
    expect($res->json('deck'))->toBe('fib-x')
        ->and($res->json('hand'))->toContain('0.5', '☕', '100');

    // '0.5' is a legal card in this deck; 'XL' belongs to another deck.
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '0.5'])->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => 'XL'])->assertStatus(422);
});

it('keeps the live deck when a later joiner asks for another', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'write']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join", ['deck' => 'tshirt'])->assertOk();
    $res = $this->actingAs($mate)->postJson("{$base}/join", ['deck' => 'fib'])->assertOk();

    expect($res->json('deck'))->toBe('tshirt');
});

it('blocks apply for decks that do not map to story points', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join", ['deck' => 'tshirt'])->assertOk();
    $this->actingAs($owner)->postJson("{$base}/apply", ['value' => 3])->assertStatus(422);

    // The extended deck applies its whole numbers (incl. 0 and 100) but never ½.
    PlanningSession::where('card_id', $card->id)->update(['deck' => 'fib-x']);
    $this->actingAs($owner)->postJson("{$base}/apply", ['value' => 100])->assertOk();
    expect(Card::find($card->id)->story_points)->toBe(100);
});

it('seats spectators without a hand and out of the vote count', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $board->sharedWith()->attach($mate->id, ['permission' => 'read']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $res = $this->actingAs($mate)->postJson("{$base}/join", ['spectator' => true])->assertOk();

    $spectator = collect($res->json('participants'))->firstWhere('user_id', $mate->id);
    expect($spectator['is_spectator'])->toBeTrue();

    // Spectators can't vote — but can grab a hand and then vote.
    $this->actingAs($mate)->postJson("{$base}/vote", ['value' => '5'])->assertStatus(422);
    $this->actingAs($mate)->postJson("{$base}/join", ['spectator' => false])->assertOk();
    $this->actingAs($mate)->postJson("{$base}/vote", ['value' => '5'])->assertOk();
});

it('lets the facilitator set a timer and auto-reveals once it lapses', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/vote", ['value' => '5'])->assertOk();

    $res = $this->actingAs($owner)->postJson("{$base}/timer", ['seconds' => 60])->assertOk();
    expect($res->json('timer_ends_at'))->not->toBeNull();

    // Deadline passes → the next heartbeat settles the round as revealed.
    PlanningSession::where('card_id', $card->id)->update(['timer_ends_at' => now()->subSecond()]);
    $show = $this->actingAs($owner)->getJson($base)->assertOk();
    expect($show->json('revealed'))->toBeTrue()
        ->and($show->json('timer_ends_at'))->toBeNull()
        ->and($show->json('participants.0.value'))->toBe('5');
});

it('clears a lapsed timer without revealing when nothing was cast', function () {
    $owner = User::factory()->create();
    [$board, $card] = scrumCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/planning";

    $this->actingAs($owner)->postJson("{$base}/join")->assertOk();
    $this->actingAs($owner)->postJson("{$base}/timer", ['seconds' => 30])->assertOk();
    PlanningSession::where('card_id', $card->id)->update(['timer_ends_at' => now()->subSecond()]);

    $show = $this->actingAs($owner)->getJson($base)->assertOk();
    expect($show->json('revealed'))->toBeFalse()
        ->and($show->json('timer_ends_at'))->toBeNull();
});
