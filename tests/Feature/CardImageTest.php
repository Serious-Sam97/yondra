<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardImage;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function imageCard(User $user): array
{
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card    = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);
    return [$board, $card];
}

it('uploads an image to a card', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", [
            'image' => UploadedFile::fake()->image('photo.jpg', 400, 300),
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'card_id', 'path', 'url', 'position', 'uploader']);

    $image = CardImage::where('card_id', $card->id)->first();
    expect($image)->not->toBeNull();
    expect($image->position)->toBe(1);
    Storage::disk('public')->assertExists($image->path);
    expect($res->json('url'))->toContain('/storage/');
});

it('assigns increasing positions to multiple images', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $this->actingAs($user);

    $a = $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.jpg')])->json();
    $b = $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('b.jpg')])->json();

    expect($a['position'])->toBe(1);
    expect($b['position'])->toBe(2);
});

it('embeds card images with a url in the board payload', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.jpg')]);

    $payload = $this->getJson("/api/boards/{$board->id}")->assertOk()->json();
    $images = $payload['cards'][0]['images'];
    expect($images)->toHaveCount(1);
    expect($images[0]['url'])->toContain('/storage/');
});

it('deletes an image and removes the stored file', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $created = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.jpg')])
        ->json();
    $path = CardImage::find($created['id'])->path;
    Storage::disk('public')->assertExists($path);

    $this->deleteJson("/api/boards/{$board->id}/cards/{$card->id}/attachments/{$created['id']}")
        ->assertNoContent();

    expect(CardImage::find($created['id']))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects a non-image file and an oversized image', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $this->actingAs($user);

    $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", [
        'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['image']);

    $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", [
        'image' => UploadedFile::fake()->image('huge.jpg')->size(6000), // 6MB > 5MB
    ])->assertUnprocessable()->assertJsonValidationErrors(['image']);
});

it('forbids a read-only member from uploading', function () {
    $owner  = User::factory()->create();
    $viewer = User::factory()->create();
    [$board, $card] = imageCard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $this->actingAs($viewer)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])
        ->assertForbidden();
});
