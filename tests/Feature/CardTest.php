<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('creates a card on a board', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards", [
            'section_id'  => $section->id,
            'name'        => 'Fix bug #42',
            'description' => 'Details here',
        ])
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Fix bug #42', 'section_id' => $section->id]);

    expect(Card::where('board_id', $board->id)->where('name', 'Fix bug #42')->exists())->toBeTrue();
});

it('validates required fields when creating a card', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['section_id', 'name']);
});

it('updates a card name and description', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card    = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Old name', 'description' => '']);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", [
            'name'        => 'New name',
            'description' => 'Updated description',
        ])
        ->assertOk()
        ->assertJsonFragment(['name' => 'New name', 'description' => 'Updated description']);
});

it('moves a card to a different section', function () {
    $user     = User::factory()->create();
    $board    = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $sectionA = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $sectionB = Section::create(['board_id' => $board->id, 'name' => 'Done']);
    $card     = Card::create(['board_id' => $board->id, 'section_id' => $sectionA->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $sectionB->id])
        ->assertOk()
        ->assertJsonFragment(['section_id' => $sectionB->id]);
});

it('requires authentication to create a card', function () {
    $this->postJson('/api/boards/1/cards', ['name' => 'Test', 'section_id' => 1])->assertStatus(401);
});
