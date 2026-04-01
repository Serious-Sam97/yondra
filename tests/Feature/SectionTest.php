<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('creates a section on a board', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sections", ['name' => 'Backlog'])
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Backlog', 'board_id' => $board->id]);

    expect(Section::where('board_id', $board->id)->where('name', 'Backlog')->exists())->toBeTrue();
});

it('validates name is required when creating a section', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sections", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('deletes a section and its cards', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
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
