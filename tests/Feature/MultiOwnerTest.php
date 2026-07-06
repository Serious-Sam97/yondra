<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/**
 * Card #291 — N owners on Projects and Boards, plus sharing a board with people
 * already on its parent project.
 */
function projectBoardSetup(): array
{
    $owner = User::factory()->create();
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Proj', 'description' => '']);
    $project->members()->attach($owner->id, ['role' => 'owner']);

    $board = Board::create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);

    return compact('owner', 'project', 'board', 'section');
}

// --- Board co-owners ---------------------------------------------------------

it('lets a board co-owner share the board with someone else', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    BoardShare::create(['board_id' => $board->id, 'user_id' => $coOwner->id, 'permission' => 'owner']);
    $invitee = User::factory()->create();

    $this->actingAs($coOwner)
        ->postJson("/api/boards/{$board->id}/share", ['user_id' => $invitee->id, 'permission' => 'write'])
        ->assertCreated();

    expect(BoardShare::where('board_id', $board->id)->where('user_id', $invitee->id)->exists())->toBeTrue();
});

it('does not let a write-only collaborator manage sharing', function () {
    extract(projectBoardSetup());
    $writer = User::factory()->create();
    BoardShare::create(['board_id' => $board->id, 'user_id' => $writer->id, 'permission' => 'write']);
    $invitee = User::factory()->create();

    $this->actingAs($writer)
        ->postJson("/api/boards/{$board->id}/share", ['user_id' => $invitee->id])
        ->assertForbidden();
});

it('treats a board_share owner permission as writable', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    BoardShare::create(['board_id' => $board->id, 'user_id' => $coOwner->id, 'permission' => 'owner']);

    expect($board->fresh()->isOwnedBy($coOwner->id))->toBeTrue();
    expect($board->fresh()->isWritableBy($coOwner->id))->toBeTrue();
});

// --- Sharing from the project roster ----------------------------------------

it('lists project members as share candidates, annotated with share status', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);
    $shared = User::factory()->create();
    $project->members()->attach($shared->id, ['role' => 'member']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $shared->id, 'permission' => 'write']);

    $res = $this->actingAs($owner)->getJson("/api/boards/{$board->id}/share/candidates")->assertOk()->json();

    // Owner is excluded; the two members are present.
    $ids = collect($res)->pluck('id');
    expect($ids)->not->toContain($owner->id);
    expect($ids)->toContain($member->id, $shared->id);
    expect(collect($res)->firstWhere('id', $shared->id)['shared'])->toBeTrue();
    expect(collect($res)->firstWhere('id', $member->id)['shared'])->toBeFalse();
});

it('shares a board by user_id', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/share", ['user_id' => $member->id, 'permission' => 'read'])
        ->assertCreated()
        ->assertJsonPath('user.permission', 'read');
});

// --- Project co-owners -------------------------------------------------------

it('lets a project co-owner add a member', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);
    $newbie = User::factory()->create();

    $this->actingAs($coOwner)
        ->postJson("/api/projects/{$project->id}/members", ['email' => $newbie->email, 'role' => 'member'])
        ->assertSuccessful();

    expect($project->fresh()->isOwnedBy($coOwner->id))->toBeTrue();
});

it('lets a project co-owner write to a board in that project', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($coOwner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['name' => 'Edited'])
        ->assertSuccessful();

    expect($card->fresh()->name)->toBe('Edited');
});

it('refuses to demote the primary project owner', function () {
    extract(projectBoardSetup());

    $this->actingAs($owner)
        ->putJson("/api/projects/{$project->id}/members/{$owner->id}", ['role' => 'member'])
        ->assertStatus(422);

    expect($project->fresh()->members()->where('users.id', $owner->id)->first()->pivot->role)->toBe('owner');
});

// --- Board access is board-level, not project-level -------------------------

it('hides a project board from a plain member who is not shared onto it', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);

    // Direct open is forbidden...
    $this->actingAs($member)->getJson("/api/boards/{$board->id}")->assertForbidden();

    // ...and the board never appears in the project payload.
    $res = $this->actingAs($member)->getJson("/api/projects/{$project->id}")->assertOk()->json();
    expect(collect($res['boards'])->pluck('id'))->not->toContain($board->id);
    expect($res['boards_count'])->toBe(0);
});

it('hides a project board from a viewer who is not shared onto it', function () {
    extract(projectBoardSetup());
    $viewer = User::factory()->create();
    $project->members()->attach($viewer->id, ['role' => 'viewer']);

    $this->actingAs($viewer)->getJson("/api/boards/{$board->id}")->assertForbidden();
});

it('shows a project board to a member once it is shared with them', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $member->id, 'permission' => 'read']);

    $this->actingAs($member)->getJson("/api/boards/{$board->id}")->assertOk();
    $res = $this->actingAs($member)->getJson("/api/projects/{$project->id}")->assertOk()->json();
    expect(collect($res['boards'])->pluck('id'))->toContain($board->id);
});

it('lets a project owner see every board in the project without a share', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);

    $this->actingAs($coOwner)->getJson("/api/boards/{$board->id}")->assertOk();
    $res = $this->actingAs($coOwner)->getJson("/api/projects/{$project->id}")->assertOk()->json();
    expect(collect($res['boards'])->pluck('id'))->toContain($board->id);
});

it('denies a project member write access to an unshared board', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($member)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['name' => 'Hacked'])->assertForbidden();
    expect($card->fresh()->name)->toBe('Task');
});

// --- Co-owned projects are valid board-move targets -------------------------

it('lists a co-owned project under "owned" so it is a valid board-move target', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);

    $res = $this->actingAs($coOwner)->getJson('/api/projects')->assertOk()->json();

    expect(collect($res['owned'])->pluck('id'))->toContain($project->id);
    expect(collect($res['member'] ?? [])->pluck('id'))->not->toContain($project->id);
});

it('lets a co-owner move a board into that co-owned project', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);
    // A board the co-owner owns elsewhere, moving into the shared project.
    $their = Board::create(['user_id' => $coOwner->id, 'name' => 'Theirs', 'description' => '']);

    $this->actingAs($coOwner)
        ->putJson("/api/boards/{$their->id}", ['project_id' => $project->id])
        ->assertSuccessful();

    expect($their->fresh()->project_id)->toBe($project->id);
});

// --- The primary owner can never be removed by a co-owner --------------------

it('forbids a co-owner from removing the primary project owner', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);

    $this->actingAs($coOwner)
        ->deleteJson("/api/projects/{$project->id}/members/{$owner->id}")
        ->assertStatus(422);

    expect($project->fresh()->members()->where('users.id', $owner->id)->exists())->toBeTrue();
});

it('lets a co-owner remove a regular member', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();
    $project->members()->attach($coOwner->id, ['role' => 'owner']);
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($coOwner)
        ->deleteJson("/api/projects/{$project->id}/members/{$member->id}")
        ->assertNoContent();

    expect($project->fresh()->members()->where('users.id', $member->id)->exists())->toBeFalse();
});

// --- Only owners manage members; promotion/direct-owner-add work ------------

it('forbids a plain project member from managing members', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);
    $victim = User::factory()->create();
    $project->members()->attach($victim->id, ['role' => 'member']);
    $target = User::factory()->create();

    $this->actingAs($member)
        ->postJson("/api/projects/{$project->id}/members", ['email' => $target->email, 'role' => 'member'])
        ->assertForbidden();
    $this->actingAs($member)
        ->putJson("/api/projects/{$project->id}/members/{$victim->id}", ['role' => 'owner'])
        ->assertForbidden();
    $this->actingAs($member)
        ->deleteJson("/api/projects/{$project->id}/members/{$victim->id}")
        ->assertForbidden();
});

it('promotes a member to owner, and the new owner can then manage members', function () {
    extract(projectBoardSetup());
    $member = User::factory()->create();
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($owner)
        ->putJson("/api/projects/{$project->id}/members/{$member->id}", ['role' => 'owner'])
        ->assertSuccessful();
    expect($project->fresh()->members()->where('users.id', $member->id)->first()->pivot->role)->toBe('owner');

    $newbie = User::factory()->create();
    $this->actingAs($member)
        ->postJson("/api/projects/{$project->id}/members", ['email' => $newbie->email, 'role' => 'member'])
        ->assertSuccessful();
});

it('adds a member directly as a co-owner', function () {
    extract(projectBoardSetup());
    $coOwner = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/members", ['email' => $coOwner->email, 'role' => 'owner'])
        ->assertSuccessful();

    expect($project->fresh()->members()->where('users.id', $coOwner->id)->first()->pivot->role)->toBe('owner');
});
