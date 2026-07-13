<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** Owner + board with To Do / Done columns + one top-level "epic" card in To Do. */
function epicSetup(): array
{
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'next_ticket_number' => 5]);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 1]);
    $epic = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Epic', 'description' => '']);

    return compact('owner', 'board', 'todo', 'done', 'epic');
}

it('creates a subtask as a real card: ticket number, inherited column, optional assignee + due', function () {
    ['owner' => $owner, 'board' => $board, 'todo' => $todo, 'epic' => $epic] = epicSetup();

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$epic->id}/subtasks", [
            'name' => 'Do a thing',
            'assigned_user_id' => $owner->id,
            'due_date' => '2026-08-01',
        ])
        ->assertCreated()
        ->assertJsonPath('ticket_key', '#5')
        ->assertJsonPath('parent_card_id', $epic->id);

    $sub = Card::where('parent_card_id', $epic->id)->firstOrFail();
    expect($sub->ticket_number)->toBe(5);
    expect($sub->section_id)->toBe($todo->id);          // inherits the epic's column
    expect($sub->assigned_user_id)->toBe($owner->id);
    expect($sub->due_date->format('Y-m-d'))->toBe('2026-08-01');
    expect($sub->section_entered_at)->not->toBeNull();  // SLA stamp from the create path
    expect($board->fresh()->next_ticket_number)->toBe(6);
});

it('rejects a subtask under a subtask — subtasks are one level deep', function () {
    ['owner' => $owner, 'board' => $board, 'epic' => $epic] = epicSetup();

    $child = Card::create([
        'board_id' => $board->id, 'section_id' => $epic->section_id,
        'parent_card_id' => $epic->id, 'name' => 'child', 'description' => '',
    ]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$child->id}/subtasks", ['name' => 'grandchild'])
        ->assertStatus(422);

    expect(Card::where('parent_card_id', $child->id)->count())->toBe(0);
});

it('board show excludes subtasks by default and carries epic rollup counts', function () {
    ['owner' => $owner, 'board' => $board, 'todo' => $todo, 'done' => $done, 'epic' => $epic] = epicSetup();
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'parent_card_id' => $epic->id, 'name' => 'open sub', 'description' => '']);
    Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'parent_card_id' => $epic->id, 'name' => 'done sub', 'description' => '', 'done_at' => now()]);

    $res = $this->actingAs($owner)->getJson("/api/boards/{$board->id}")->assertOk();

    $cards = collect($res->json('cards'));
    expect($cards->pluck('parent_card_id')->filter()->count())->toBe(0); // no subtasks in the board payload
    $epicPayload = $cards->firstWhere('id', $epic->id);
    expect($epicPayload['subtasks_count'])->toBe(2);
    expect($epicPayload['done_subtasks_count'])->toBe(1);
});

it('board show includes subtasks as full cards when include_subtasks=1', function () {
    ['owner' => $owner, 'board' => $board, 'todo' => $todo, 'epic' => $epic] = epicSetup();
    $sub = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'parent_card_id' => $epic->id, 'name' => 'open sub', 'description' => '']);

    $cards = collect(
        $this->actingAs($owner)->getJson("/api/boards/{$board->id}?include_subtasks=1")->assertOk()->json('cards')
    );

    expect($cards->firstWhere('id', $sub->id))->not->toBeNull();
    expect($cards->firstWhere('id', $sub->id)['parent_card_id'])->toBe($epic->id);
});

it('moving a subtask into the done column stamps done_at and bumps the epic rollup', function () {
    ['owner' => $owner, 'board' => $board, 'todo' => $todo, 'done' => $done, 'epic' => $epic] = epicSetup();
    $sub = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'parent_card_id' => $epic->id, 'name' => 'sub', 'description' => '']);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$sub->id}", ['section_id' => $done->id])
        ->assertOk();

    expect($sub->fresh()->done_at)->not->toBeNull();

    $epicPayload = collect($this->actingAs($owner)->getJson("/api/boards/{$board->id}")->json('cards'))
        ->firstWhere('id', $epic->id);
    expect($epicPayload['done_subtasks_count'])->toBe(1);

    // Moving back out of done clears done_at and decrements the rollup.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$sub->id}", ['section_id' => $todo->id])
        ->assertOk();
    expect($sub->fresh()->done_at)->toBeNull();
});
