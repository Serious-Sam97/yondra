<?php

use Illuminate\Support\Facades\DB;

/**
 * Verifies the 2026_07_12_000004 recovery migration moves scrum tickets stuck in the
 * reserved "Backlog" section back into a real column with sprint_id = NULL, and leaves
 * non-scrum backlog cards untouched.
 */
function runBacklogRecoveryMigration(): void
{
    $migration = require database_path(
        'migrations/2026_07_12_000004_move_stranded_scrum_backlog_cards_to_sprint_backlog.php'
    );
    $migration->up();
}

function seedBoard(string $type): int
{
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => uniqid('owner_', true).'@example.com',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('boards')->insertGetId([
        'name' => "Board {$type}",
        'description' => '',
        'type' => $type,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedSection(int $boardId, string $name, int $order): int
{
    return (int) DB::table('sections')->insertGetId([
        'board_id' => $boardId,
        'name' => $name,
        'order' => $order,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedCard(int $boardId, int $sectionId, string $name, ?int $sprintId, int $position): int
{
    return (int) DB::table('cards')->insertGetId([
        'board_id' => $boardId,
        'section_id' => $sectionId,
        'name' => $name,
        'description' => '',
        'sprint_id' => $sprintId,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('moves stranded scrum backlog cards into the leftmost column with no sprint', function () {
    $board = seedBoard('scrum');
    $todo = seedSection($board, 'To Do', 0);
    seedSection($board, 'Doing', 1);
    $backlog = seedSection($board, 'Backlog', 2);

    // One card already living in To Do (so we can assert append order).
    seedCard($board, $todo, 'Existing', null, 0);

    $sprintId = (int) DB::table('sprints')->insertGetId([
        'board_id' => $board,
        'name' => 'Sprint 1',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Two stranded cards in the reserved Backlog section (one still tagged to a sprint).
    $s1 = seedCard($board, $backlog, 'Stranded A', $sprintId, 0);
    $s2 = seedCard($board, $backlog, 'Stranded B', null, 1);

    runBacklogRecoveryMigration();

    $a = DB::table('cards')->find($s1);
    $b = DB::table('cards')->find($s2);

    expect($a->section_id)->toBe($todo)
        ->and($a->sprint_id)->toBeNull()
        ->and($b->section_id)->toBe($todo)
        ->and($b->sprint_id)->toBeNull();

    // Appended after the existing card (position 0) → 1 and 2, and nothing left in Backlog.
    expect([$a->position, $b->position])->toBe([1, 2])
        ->and(DB::table('cards')->where('section_id', $backlog)->count())->toBe(0);
});

it('leaves non-scrum reserved-backlog cards untouched', function () {
    $board = seedBoard('kanban');
    seedSection($board, 'To Do', 0);
    $backlog = seedSection($board, 'Backlog', 1);
    $card = seedCard($board, $backlog, 'Parked', null, 0);

    runBacklogRecoveryMigration();

    $row = DB::table('cards')->find($card);
    expect($row->section_id)->toBe($backlog);
});
