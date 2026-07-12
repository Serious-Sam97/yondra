<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

// FIX: assigned_user_id used to accept ANY user id (exists:users,id), letting a
// board member leak the board id + card name to a stranger via the assignment
// email. Assignment is now restricted to users who can see the board.

function assignmentBoard(User $owner): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    return [$board, $section, $card];
}

it('rejects assigning a card to a stranger on create', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, $section] = assignmentBoard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards", [
            'section_id' => $section->id,
            'name' => 'New card',
            'assigned_user_id' => $stranger->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_user_id']);
});

it('rejects assigning a card to a stranger on update', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, , $card] = assignmentBoard($owner);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", [
            'assigned_user_id' => $stranger->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_user_id']);

    expect($card->fresh()->assigned_user_id)->toBeNull();
});

it('allows assigning the board owner and a shared collaborator', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    [$board, $section, $card] = assignmentBoard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $collaborator->id, 'permission' => 'read']);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $collaborator->id])
        ->assertOk()
        ->assertJsonFragment(['assigned_user_id' => $collaborator->id]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards", [
            'section_id' => $section->id,
            'name' => 'Owned card',
            'assigned_user_id' => $owner->id,
        ])
        ->assertCreated();
});

it('allows assigning a project member on a project board', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Project']);
    $project->members()->attach($member->id, ['role' => 'member']);

    $board = Board::create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk()
        ->assertJsonFragment(['assigned_user_id' => $member->id]);
});

it('allows clearing the assignee', function () {
    $owner = User::factory()->create();
    [$board, , $card] = assignmentBoard($owner);
    $card->update(['assigned_user_id' => $owner->id]);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => null])
        ->assertOk();

    expect($card->fresh()->assigned_user_id)->toBeNull();
});
