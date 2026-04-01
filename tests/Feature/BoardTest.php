<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('requires authentication to list boards', function () {
    $this->getJson('/api/boards')->assertStatus(401);
});

it('returns only the authenticated users boards', function () {
    $user  = User::factory()->create();
    $other = User::factory()->create();

    Board::create(['user_id' => $user->id,  'name' => 'My Board',    'description' => '']);
    Board::create(['user_id' => $other->id, 'name' => 'Other Board', 'description' => '']);

    $this->actingAs($user)
        ->getJson('/api/boards')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'My Board']);
});

it('requires authentication to create a board', function () {
    $this->postJson('/api/boards', ['name' => 'Test'])->assertStatus(401);
});

it('creates a board with default sections', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/boards', ['name' => 'Sprint 1', 'description' => 'First sprint'])
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Sprint 1']);

    $boardId = $response->json('id');

    expect(Section::where('board_id', $boardId)->pluck('name')->toArray())
        ->toBe(['To Do', 'In Progress', 'Done']);
});

it('validates name is required when creating a board', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/boards', ['description' => 'No name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('returns a board with its sections and cards', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board A', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task 1', 'description' => '']);

    $this->actingAs($user)
        ->getJson("/api/boards/{$board->id}")
        ->assertOk()
        ->assertJsonFragment(['name' => 'Board A'])
        ->assertJsonFragment(['name' => 'To Do'])
        ->assertJsonFragment(['name' => 'Task 1']);
});

it('deletes a board and cascades to sections and cards', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Doomed Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}")
        ->assertNoContent();

    expect(Board::find($board->id))->toBeNull();
    expect(Section::where('board_id', $board->id)->count())->toBe(0);
    expect(Card::where('board_id', $board->id)->count())->toBe(0);
});
