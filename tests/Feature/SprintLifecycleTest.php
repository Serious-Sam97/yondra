<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\User;

function scrumBoard(User $user): array
{
    $board = Board::create(['user_id' => $user->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done']);

    return [$board, $todo, $done];
}

it('starts a sprint and freezes committed points', function () {
    $user = User::factory()->create();
    [$board, $todo] = scrumBoard($user);
    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'Sprint 1', 'status' => 'future']);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'A', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 5]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'B', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 8]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$sprint->id}/start")
        ->assertOk()
        ->assertJsonFragment(['status' => 'active', 'committed_points' => 13, 'committed_count' => 2]);
});

it('blocks starting a second sprint while one is active', function () {
    $user = User::factory()->create();
    [$board] = scrumBoard($user);
    Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true]);
    $s2 = Sprint::create(['board_id' => $board->id, 'name' => 'S2', 'status' => 'future']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$s2->id}/start")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sprint']);
});

it('completes a sprint, freezes completed points and sends incomplete to backlog', function () {
    $user = User::factory()->create();
    [$board, $todo, $done] = scrumBoard($user);
    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true, 'committed_points' => 13, 'committed_count' => 2]);
    $doneCard = Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'name' => 'Done card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 5, 'done_at' => now()]);
    $openCard = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 8]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$sprint->id}/complete", ['move_to' => 'backlog'])
        ->assertOk()
        ->assertJsonFragment(['status' => 'completed', 'completed_points' => 5, 'completed_count' => 1]);

    // Done card stays frozen in the sprint; open card returns to the backlog.
    expect(Card::find($doneCard->id)->sprint_id)->toBe($sprint->id)
        ->and(Card::find($openCard->id)->sprint_id)->toBeNull();
});

it('moves incomplete tickets into a new sprint on completion', function () {
    $user = User::factory()->create();
    [$board, $todo] = scrumBoard($user);
    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true]);
    $open = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 3]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$sprint->id}/complete", ['move_to' => 'new', 'new_sprint_name' => 'Sprint 2'])
        ->assertOk();

    $newSprint = Sprint::where('board_id', $board->id)->where('name', 'Sprint 2')->first();
    expect($newSprint)->not->toBeNull()
        ->and($newSprint->status)->toBe('future')
        ->and(Card::find($open->id)->sprint_id)->toBe($newSprint->id);
});

it('returns a sprint report with completed split and a burndown series', function () {
    $user = User::factory()->create();
    [$board, $todo, $done] = scrumBoard($user);
    $sprint = Sprint::create([
        'board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true,
        'started_at' => now()->subDays(2), 'start_date' => now()->subDays(2)->toDateString(),
        'end_date' => now()->toDateString(), 'committed_points' => 13,
    ]);
    Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'name' => 'Done', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 5, 'done_at' => now()->subDay()]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 8]);

    $res = $this->actingAs($user)
        ->getJson("/api/boards/{$board->id}/sprints/{$sprint->id}/report")
        ->assertOk()
        ->assertJsonFragment(['committed_points' => 13, 'completed_points' => 5]);

    expect($res->json('completed'))->toHaveCount(1)
        ->and($res->json('not_completed'))->toHaveCount(1)
        ->and(count($res->json('burndown')))->toBeGreaterThanOrEqual(3);
});

it('stamps done_at when a card is dragged into the Done column', function () {
    $user = User::factory()->create();
    [$board, $todo, $done] = scrumBoard($user);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/reorder", ['ordered_ids' => [$card->id], 'section_id' => $done->id])
        ->assertOk();

    expect(Card::find($card->id)->done_at)->not->toBeNull();
});

it('counts cards in the Done column as done in the live report even without done_at', function () {
    $user = User::factory()->create();
    [$board, $todo, $done] = scrumBoard($user);
    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true, 'committed_points' => 8]);
    // A card sitting in Done but with no done_at (e.g. moved before tracking existed).
    Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'name' => 'Done card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 5, 'done_at' => null]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 3]);

    $res = $this->actingAs($user)
        ->getJson("/api/boards/{$board->id}/sprints/{$sprint->id}/report")
        ->assertOk()
        ->assertJsonFragment(['completed_points' => 5]);

    expect($res->json('completed'))->toHaveCount(1)
        ->and($res->json('not_completed'))->toHaveCount(1);
});

it('defaults a new sprint schedule to today for two weeks', function () {
    $user = User::factory()->create();
    [$board] = scrumBoard($user);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints", ['name' => 'Sprint 1'])
        ->assertCreated();

    expect($res->json('start_date'))->toBe(now()->toDateString())
        ->and($res->json('end_date'))->toBe(now()->addWeeks(2)->toDateString());
});

it('starts a second sprint the day after the first one ends', function () {
    $user = User::factory()->create();
    [$board] = scrumBoard($user);
    Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'future', 'start_date' => '2026-08-01', 'end_date' => '2026-08-14']);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints", ['name' => 'S2'])
        ->assertCreated();

    expect($res->json('start_date'))->toBe('2026-08-15');
});

it('cascades a later sprint forward when an earlier sprint end date overlaps it', function () {
    $user = User::factory()->create();
    [$board] = scrumBoard($user);
    $s1 = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'future', 'start_date' => '2026-08-01', 'end_date' => '2026-08-14']);
    $s2 = Sprint::create(['board_id' => $board->id, 'name' => 'S2', 'status' => 'future', 'start_date' => '2026-08-15', 'end_date' => '2026-08-28']); // 13-day sprint

    // Extend S1 to end 2026-08-20 — it now overlaps S2's start (08-15).
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/sprints/{$s1->id}", ['end_date' => '2026-08-20'])
        ->assertOk();

    $s2->refresh();
    // S2 pushed to start the day after S1's new end, keeping its 13-day duration.
    expect($s2->start_date->toDateString())->toBe('2026-08-21')
        ->and($s2->end_date->toDateString())->toBe('2026-09-03');
});

it('completes a sprint using the configured done column, not just a "Done"-named one', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'CRM', 'description' => '', 'type' => 'scrum']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $won = Section::create(['board_id' => $board->id, 'name' => 'Won']);
    $board->update(['done_section_id' => $won->id]);

    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true]);
    // Sitting in the configured done column but never stamped — completion must backfill it.
    $wonCard = Card::create(['board_id' => $board->id, 'section_id' => $won->id, 'name' => 'Won card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 5]);
    $openCard = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open card', 'description' => '', 'sprint_id' => $sprint->id, 'story_points' => 8]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$sprint->id}/complete", ['move_to' => 'backlog'])
        ->assertOk()
        ->assertJsonFragment(['status' => 'completed', 'completed_points' => 5, 'completed_count' => 1]);

    expect(Card::find($wonCard->id)->done_at)->not->toBeNull()
        ->and(Card::find($wonCard->id)->sprint_id)->toBe($sprint->id)
        ->and(Card::find($openCard->id)->sprint_id)->toBeNull();
});
