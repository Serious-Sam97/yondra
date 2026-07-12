<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('uploads a board-scoped inline image privately and returns a signed host-less url', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->image('inline.png', 320, 200)])
        ->assertCreated()
        ->assertJsonStructure(['url']);

    // Still a host-less path the client joins to NEXT_PUBLIC_API — now signed.
    $url = $res->json('url');
    expect($url)->toBeString()
        ->toStartWith('/api/inline-images')
        ->toContain('signature=');
    // The file landed on the PRIVATE disk under boards/{id}; nothing on public.
    expect(Storage::disk('local')->allFiles("boards/{$board->id}"))->toHaveCount(1);
    expect(Storage::disk('public')->allFiles())->toHaveCount(0);
});

it('streams an inline image through its signed url as a guest', function () {
    // The non-expiring signature alone gates access — the URL is embedded in
    // stored HTML, so it must keep working like a capability link, and <img>
    // tags cannot send Bearer tokens anyway.
    $path = Storage::disk('local')->putFileAs('boards/1', UploadedFile::fake()->image('inline.png'), 'inline.png');
    $url = URL::signedRoute('inline-images.show', ['path' => $path], absolute: false);

    $this->get($url)->assertOk()->assertHeader('content-type', 'image/png');
});

it('rejects a tampered inline image url', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $url = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->image('inline.png')])
        ->json('url');

    // Point the signed query at a different path.
    $this->get(preg_replace('/path=[^&]+/', 'path='.urlencode('boards/999/other.png'), $url))
        ->assertForbidden();
    // Strip the signature entirely.
    $this->get(strtok($url, '?').'?path='.urlencode("boards/{$board->id}/x.png"))->assertForbidden();
});

it('rejects a non-image upload', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);
});

it('forbids a read-only member from uploading', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $this->actingAs($viewer)
        ->postJson("/api/boards/{$board->id}/uploads", ['image' => UploadedFile::fake()->image('x.png')])
        ->assertForbidden();
});
