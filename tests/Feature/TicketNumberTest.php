<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function makeBoardWithSection(User $user, ?string $prefix = null): array
{
    $board = Board::create([
        'user_id' => $user->id,
        'name' => 'Board',
        'description' => '',
        'ticket_prefix' => $prefix,
    ]);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);

    return [$board, $section];
}

it('assigns fresh per-board ticket numbers starting at 1', function () {
    $user = User::factory()->create();
    [$board, $section] = makeBoardWithSection($user);

    $this->actingAs($user);

    $first = $this->postJson("/api/boards/{$board->id}/cards", ['section_id' => $section->id, 'name' => 'One'])->json();
    $second = $this->postJson("/api/boards/{$board->id}/cards", ['section_id' => $section->id, 'name' => 'Two'])->json();

    expect($first['ticket_number'])->toBe(1);
    expect($second['ticket_number'])->toBe(2);
    // No prefix set -> key falls back to "#N".
    expect($first['ticket_key'])->toBe('#1');
    expect($second['ticket_key'])->toBe('#2');
});

it('restarts the sequence on a different board', function () {
    $user = User::factory()->create();
    [$boardA, $sectionA] = makeBoardWithSection($user);
    [$boardB, $sectionB] = makeBoardWithSection($user);

    $this->actingAs($user);

    $this->postJson("/api/boards/{$boardA->id}/cards", ['section_id' => $sectionA->id, 'name' => 'A1']);
    $this->postJson("/api/boards/{$boardA->id}/cards", ['section_id' => $sectionA->id, 'name' => 'A2']);
    $b1 = $this->postJson("/api/boards/{$boardB->id}/cards", ['section_id' => $sectionB->id, 'name' => 'B1'])->json();

    // Board B's very first card is #1 regardless of board A having used 1 and 2.
    expect($b1['ticket_number'])->toBe(1);
});

it('renders the board prefix in the ticket key', function () {
    $user = User::factory()->create();
    [$board, $section] = makeBoardWithSection($user, 'YON');

    $this->actingAs($user);

    $created = $this->postJson("/api/boards/{$board->id}/cards", ['section_id' => $section->id, 'name' => 'One'])->json();
    expect($created['ticket_key'])->toBe('YON-1');

    // The board-load path composes the same key onto each embedded card.
    $board = $this->getJson("/api/boards/{$board->id}")->json();
    expect($board['cards'][0]['ticket_key'])->toBe('YON-1');
});

it('normalizes a prefix set via the board update endpoint', function () {
    $user = User::factory()->create();
    [$board, $section] = makeBoardWithSection($user);

    $this->actingAs($user);
    $this->postJson("/api/boards/{$board->id}/cards", ['section_id' => $section->id, 'name' => 'One']);

    $this->putJson("/api/boards/{$board->id}", ['ticket_prefix' => '  yon '])->assertOk();
    expect(Board::find($board->id)->ticket_prefix)->toBe('YON');

    $reloaded = $this->getJson("/api/boards/{$board->id}")->json();
    expect($reloaded['cards'][0]['ticket_key'])->toBe('YON-1');

    // Clearing the prefix falls back to "#N".
    $this->putJson("/api/boards/{$board->id}", ['ticket_prefix' => ''])->assertOk();
    expect(Board::find($board->id)->ticket_prefix)->toBeNull();
});

it('backfills existing cards by creation order per board', function () {
    $user = User::factory()->create();
    [$board, $section] = makeBoardWithSection($user);

    // Simulate legacy cards inserted before the feature (no ticket_number yet),
    // out of id order relative to created_at to prove ordering is by created_at.
    $older = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Older', 'description' => '']);
    $newer = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Newer', 'description' => '']);
    $older->forceFill(['ticket_number' => null, 'created_at' => now()->subDay()])->save();
    $newer->forceFill(['ticket_number' => null, 'created_at' => now()])->save();
    Board::whereKey($board->id)->update(['next_ticket_number' => 1]);

    // Re-run the backfill migration against this now-populated table.
    (require database_path('migrations/2026_07_07_000003_backfill_card_ticket_numbers.php'))->up();

    expect($older->fresh()->ticket_number)->toBe(1);
    expect($newer->fresh()->ticket_number)->toBe(2);
    expect(Board::find($board->id)->next_ticket_number)->toBe(3);
});
