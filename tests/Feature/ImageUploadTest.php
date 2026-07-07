<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('uploads a board-scoped inline image and returns a url', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->image('inline.png', 320, 200)])
        ->assertCreated()
        ->assertJsonStructure(['url']);

    expect($res->json('url'))->toContain('/storage/boards/');
    // The file physically landed on the public disk under boards/{id}.
    expect(Storage::disk('public')->allFiles("boards/{$board->id}"))->toHaveCount(1);
});

it('rejects a non-image upload', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);
});

it('forbids a read-only member from uploading', function () {
    $owner  = User::factory()->create();
    $viewer = User::factory()->create();
    $board  = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $this->actingAs($viewer)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->image('x.png')])
        ->assertForbidden();
});
