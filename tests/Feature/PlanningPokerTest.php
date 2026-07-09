<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\PlanningSession;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function scrumCard(User $owner): array
{
    $board   = Board::create(['user_id' => $owner->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card    = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Story', 'description' => '']);
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
    $mate  = User::factory()->create();
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
