<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** A project with one board per name, each with a To Do + Done column. */
function deckProject(User $owner, array $boardNames): array
{
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Deck', 'color' => '#1976D2']);
    $project->members()->attach($owner->id, ['role' => 'owner']);

    $boards = [];
    foreach ($boardNames as $i => $name) {
        $board = Board::create([
            'user_id' => $owner->id, 'project_id' => $project->id, 'position' => $i,
            'name' => $name, 'description' => '',
        ]);
        Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
        Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 1]);
        $boards[] = $board;
    }

    return [$project, $boards];
}

function doneCard(Board $board, $doneAt): Card
{
    $section = Section::where('board_id', $board->id)->where('name', 'Done')->first();

    return Card::create([
        'board_id' => $board->id, 'section_id' => $section->id,
        'name' => 'Shipped', 'description' => '', 'done_at' => $doneAt,
    ]);
}

it('returns a 14-day throughput series on project show', function () {
    $owner = User::factory()->create();
    [$project, [$board]] = deckProject($owner, ['Web']);

    doneCard($board, now());
    doneCard($board, now());
    doneCard($board, now()->subDays(3));
    doneCard($board, now()->subDays(20)); // outside the window

    $series = $this->actingAs($owner)->getJson("/api/projects/{$project->id}")
        ->assertOk()
        ->json('throughput');

    expect($series)->toHaveCount(14);
    expect($series[13])->toBe(2);
    expect($series[10])->toBe(1);
    expect(array_sum($series))->toBe(3);
});

it('returns an all-zero throughput for a project without boards', function () {
    $owner = User::factory()->create();
    [$project] = deckProject($owner, []);

    $series = $this->actingAs($owner)->getJson("/api/projects/{$project->id}")->json('throughput');

    expect($series)->toBe(array_fill(0, 14, 0));
});

it('exposes the newest card activity per board', function () {
    $owner = User::factory()->create();
    [$project, [$busy, $empty]] = deckProject($owner, ['Busy', 'Empty']);

    $this->travelTo(now()->subHours(5));
    doneCard($busy, now());
    $this->travelBack();
    $recent = doneCard($busy, now());

    $boards = collect(
        $this->actingAs($owner)->getJson("/api/projects/{$project->id}")->json('boards')
    )->keyBy('name');

    expect($boards['Busy']['last_activity_at'])->not->toBeNull();
    expect(abs(strtotime($boards['Busy']['last_activity_at']) - $recent->updated_at->getTimestamp()))->toBeLessThanOrEqual(1);
    expect($boards['Empty']['last_activity_at'])->toBeNull();
});

it('includes last activity on the project index used by the rail', function () {
    $owner = User::factory()->create();
    [, [$board]] = deckProject($owner, ['Web']);
    doneCard($board, now());

    $owned = $this->actingAs($owner)->getJson('/api/projects')->json('owned');

    expect($owned[0]['boards'][0]['last_activity_at'])->not->toBeNull();
});

it('scopes throughput to the boards a member can see', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    [$project, [$shared, $private]] = deckProject($owner, ['Shared', 'Private']);
    $project->members()->attach($member->id, ['role' => 'member']);
    $shared->sharedWith()->attach($member->id, ['permission' => 'write']);

    doneCard($shared, now());
    doneCard($private, now());
    doneCard($private, now());

    $ownerSeries = $this->actingAs($owner)->getJson("/api/projects/{$project->id}")->json('throughput');
    $memberSeries = $this->actingAs($member)->getJson("/api/projects/{$project->id}")->json('throughput');

    expect(array_sum($ownerSeries))->toBe(3);
    expect(array_sum($memberSeries))->toBe(1);
});
