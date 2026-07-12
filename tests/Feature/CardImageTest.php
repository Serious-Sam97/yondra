<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardImage;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function imageCard(User $user): array
{
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    return [$board, $card];
}

/** Strip the app origin so a signed URL can be replayed through the test client. */
function relativeUrl(string $url): string
{
    return Str::after($url, rtrim(config('app.url'), '/'));
}

it('uploads an image to a card privately and returns a signed url', function () {
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
    // The blob is private: on the local disk, NOT web-reachable via /storage.
    Storage::disk('local')->assertExists($image->path);
    Storage::disk('public')->assertMissing($image->path);
    // The url field is still a plain string an <img src> can use — now signed.
    expect($res->json('url'))->toBeString()
        ->toContain('/api/card-images/')
        ->toContain('signature=');
});

it('streams a private image through its signed url as a guest', function () {
    // Decision: the time-limited signature alone gates access (capability URL) —
    // <img> tags cannot send Bearer tokens, so no session/token is required.
    $user = User::factory()->create();
    [, $card] = imageCard($user);
    $path = Storage::disk('local')->putFileAs("cards/{$card->id}", UploadedFile::fake()->image('a.png'), 'a.png');
    $image = CardImage::create([
        'card_id' => $card->id, 'user_id' => $user->id, 'disk' => 'local', 'path' => $path, 'position' => 1,
    ]);

    $this->get(relativeUrl($image->url))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('rejects a tampered or unsigned image url', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $created = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.png')])
        ->json();

    $relative = relativeUrl($created['url']);

    // Flip the signature.
    $this->get(preg_replace('/signature=\w{10}/', 'signature=0000000000', $relative))->assertForbidden();
    // Strip the signature entirely.
    $this->get(strtok($relative, '?'))->assertForbidden();
    // Point the signed query at a different image id.
    $other = CardImage::create(['card_id' => $card->id, 'disk' => 'local', 'path' => 'cards/x.png', 'position' => 9]);
    $this->get(str_replace("/api/card-images/{$created['id']}", "/api/card-images/{$other->id}", $relative))
        ->assertForbidden();
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

it('embeds card images with a signed url in the board payload', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.jpg')]);

    $payload = $this->getJson("/api/boards/{$board->id}")->assertOk()->json();
    $images = $payload['cards'][0]['images'];
    expect($images)->toHaveCount(1);
    expect($images[0]['url'])->toContain('/api/card-images/')->toContain('signature=');
});

it('keeps serving legacy public-disk images from /storage', function () {
    // Rows uploaded before privatization stay on the public disk until the
    // `yondra:privatize-images` command moves them; their url is unchanged.
    $user = User::factory()->create();
    [, $card] = imageCard($user);
    Storage::disk('public')->put("cards/{$card->id}/legacy.png", 'png-bytes');
    $legacy = CardImage::create([
        'card_id' => $card->id, 'user_id' => $user->id, 'disk' => 'public',
        'path' => "cards/{$card->id}/legacy.png", 'position' => 1,
    ]);

    expect($legacy->url)->toContain('/storage/')->not->toContain('signature=');
});

it('moves legacy public images to private storage via yondra:privatize-images', function () {
    $user = User::factory()->create();
    [, $card] = imageCard($user);
    $path = "cards/{$card->id}/legacy.png";
    Storage::disk('public')->put($path, 'png-bytes');
    $legacy = CardImage::create([
        'card_id' => $card->id, 'user_id' => $user->id, 'disk' => 'public', 'path' => $path, 'position' => 1,
    ]);

    $this->artisan('yondra:privatize-images')
        ->expectsOutputToContain('Moved 1 card image(s)')
        ->assertSuccessful();

    Storage::disk('public')->assertMissing($path);
    Storage::disk('local')->assertExists($path);
    $legacy->refresh();
    expect($legacy->url)->toContain('/api/card-images/')->toContain('signature=');
});

it('deletes an image and removes the stored file', function () {
    $user = User::factory()->create();
    [$board, $card] = imageCard($user);
    $created = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", ['image' => UploadedFile::fake()->image('a.jpg')])
        ->json();
    $path = CardImage::find($created['id'])->path;
    Storage::disk('local')->assertExists($path);

    $this->deleteJson("/api/boards/{$board->id}/cards/{$card->id}/attachments/{$created['id']}")
        ->assertNoContent();

    expect(CardImage::find($created['id']))->toBeNull();
    Storage::disk('local')->assertMissing($path);
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
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    [$board, $card] = imageCard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $this->actingAs($viewer)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/attachments", [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])
        ->assertForbidden();
});
