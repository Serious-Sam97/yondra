<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('creates a section on a board', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sections", ['name' => 'Backlog'])
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Backlog', 'board_id' => $board->id]);

    expect(Section::where('board_id', $board->id)->where('name', 'Backlog')->exists())->toBeTrue();
});

it('validates name is required when creating a section', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sections", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('deletes a section and its cards', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/sections/{$section->id}")
        ->assertNoContent();

    expect(Section::find($section->id))->toBeNull();
    expect(Card::where('section_id', $section->id)->count())->toBe(0);
});

it('requires authentication to create a section', function () {
    $this->postJson('/api/boards/1/sections', ['name' => 'Test'])->assertStatus(401);
});

// FIX: section delete used to hard-delete its cards — the only irreversible
// delete in the app. It now archives them (section_id detached) so they show
// up in the archived list and can be restored like any other archived card.
it('archives (not deletes) cards when their section is deleted', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/sections/{$section->id}")
        ->assertNoContent();

    $card->refresh();
    expect($card->archived_at)->not->toBeNull();
    expect($card->section_id)->toBeNull();

    // The card is visible in the board's archived listing.
    $this->getJson("/api/boards/{$board->id}/cards/archived")
        ->assertOk()
        ->assertJsonFragment(['id' => $card->id]);
});

it('re-homes a section-orphaned card into the first section on restore', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $keep = Section::create(['board_id' => $board->id, 'name' => 'Keep', 'order' => 0]);
    $doomed = Section::create(['board_id' => $board->id, 'name' => 'Doomed', 'order' => 1]);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $doomed->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/sections/{$doomed->id}")
        ->assertNoContent();

    $this->putJson("/api/boards/{$board->id}/cards/{$card->id}/restore")
        ->assertNoContent();

    $card->refresh();
    expect($card->archived_at)->toBeNull();
    expect($card->section_id)->toBe($keep->id);
});

it('keeps the original section when restoring a normally archived card', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/cards/{$card->id}")
        ->assertNoContent();
    $this->putJson("/api/boards/{$board->id}/cards/{$card->id}/restore")
        ->assertNoContent();

    expect($card->refresh()->section_id)->toBe($section->id);
});
