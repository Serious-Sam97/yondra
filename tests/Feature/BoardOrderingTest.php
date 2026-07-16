<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;

/** A project owned by $owner holding $count boards, ordered 0..n by position. */
function orderedProject(User $owner, int $count = 3): array
{
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Proj', 'color' => '#1976D2']);
    $project->members()->attach($owner->id, ['role' => 'owner']);

    $boards = [];
    for ($i = 0; $i < $count; $i++) {
        $boards[] = Board::create([
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'position' => $i,
            'name' => "Board {$i}",
            'description' => '',
        ]);
    }

    return [$project, $boards];
}

it('persists a manual board order within a project', function () {
    $owner = User::factory()->create();
    [$project, $boards] = orderedProject($owner, 3);

    // New order: reverse the boards.
    $newOrder = [$boards[2]->id, $boards[0]->id, $boards[1]->id];

    $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/boards/reorder", ['board_ids' => $newOrder])
        ->assertNoContent();

    expect($boards[2]->fresh()->position)->toBe(0);
    expect($boards[0]->fresh()->position)->toBe(1);
    expect($boards[1]->fresh()->position)->toBe(2);

    // The project relation now reads back in the persisted order.
    expect($project->fresh()->boards->pluck('id')->all())->toBe($newOrder);
});

it('ignores board ids that belong to another project when reordering', function () {
    $owner = User::factory()->create();
    [$project, $boards] = orderedProject($owner, 2);
    [$otherProject, $otherBoards] = orderedProject($owner, 1);
    $foreign = $otherBoards[0];
    $foreignPosBefore = $foreign->position;

    // Sneak the foreign board id into the reorder payload.
    $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/boards/reorder", [
            'board_ids' => [$boards[1]->id, $foreign->id, $boards[0]->id],
        ])
        ->assertNoContent();

    // The foreign board is untouched: same project, same position.
    $foreign->refresh();
    expect($foreign->project_id)->toBe($otherProject->id);
    expect($foreign->position)->toBe($foreignPosBefore);
});

it('forbids reordering for a non-owner project member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    [$project, $boards] = orderedProject($owner, 2);
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)
        ->postJson("/api/projects/{$project->id}/boards/reorder", [
            'board_ids' => [$boards[1]->id, $boards[0]->id],
        ])
        ->assertForbidden();

    // Order is unchanged.
    expect($boards[0]->fresh()->position)->toBe(0);
});

it('appends a board to the end of the destination project when moved', function () {
    $owner = User::factory()->create();
    [$source, $sourceBoards] = orderedProject($owner, 2);
    [$dest, $destBoards] = orderedProject($owner, 3); // positions 0,1,2

    $moved = $sourceBoards[0];

    $this->actingAs($owner)
        ->putJson("/api/boards/{$moved->id}", ['project_id' => $dest->id])
        ->assertOk();

    $moved->refresh();
    expect($moved->project_id)->toBe($dest->id);
    // Appended after the destination's existing max position (2) → 3.
    expect($moved->position)->toBe(3);

    // It now sorts last in the destination's ordered list.
    expect($dest->fresh()->boards->last()->id)->toBe($moved->id);
});

it('forbids moving a board the user does not own', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$source, $sourceBoards] = orderedProject($owner, 1);
    [$dest] = orderedProject($stranger, 1);

    // Stranger owns neither the board nor may write to it.
    $this->actingAs($stranger)
        ->putJson("/api/boards/{$sourceBoards[0]->id}", ['project_id' => $dest->id])
        ->assertForbidden();
});
