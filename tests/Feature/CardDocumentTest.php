<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function docCard(User $user): array
{
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card    = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    return [$board, $card];
}

it('uploads a document to a card and stores it on the private disk', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", [
            'file' => UploadedFile::fake()->create('spec.pdf', 200, 'application/pdf'),
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'card_id', 'path', 'original_name', 'mime_type', 'size', 'position', 'uploader']);

    $doc = CardDocument::where('card_id', $card->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->position)->toBe(1);
    expect($doc->disk)->toBe('local');
    expect($res->json('original_name'))->toBe('spec.pdf');
    Storage::disk('local')->assertExists($doc->path);
    // Never leaks a public URL.
    expect($res->json())->not->toHaveKey('url');
});

it('assigns increasing positions to multiple documents', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);
    $this->actingAs($user);

    $a = $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json();
    $b = $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('b.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])->json();

    expect($a['position'])->toBe(1);
    expect($b['position'])->toBe(2);
});

it('embeds card documents in the board payload without a url', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);
    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

    $payload = $this->getJson("/api/boards/{$board->id}")->assertOk()->json();
    $documents = $payload['cards'][0]['documents'];
    expect($documents)->toHaveCount(1);
    expect($documents[0]['original_name'])->toBe('a.pdf');
    expect($documents[0])->not->toHaveKey('url');
});

it('downloads a document through the auth-gated route', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);
    $created = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')])
        ->json();

    $this->get("/api/boards/{$board->id}/cards/{$card->id}/documents/{$created['id']}/download")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=report.pdf');
});

it('lets a read-only member download but not upload', function () {
    $owner  = User::factory()->create();
    $viewer = User::factory()->create();
    [$board, $card] = docCard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $created = $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('shared.pdf', 10, 'application/pdf')])
        ->json();

    $this->actingAs($viewer)
        ->get("/api/boards/{$board->id}/cards/{$card->id}/documents/{$created['id']}/download")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('nope.pdf', 10, 'application/pdf')])
        ->assertForbidden();
});

it('deletes a document and removes the stored file', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);
    $created = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->json();
    $path = CardDocument::find($created['id'])->path;
    Storage::disk('local')->assertExists($path);

    $this->deleteJson("/api/boards/{$board->id}/cards/{$card->id}/documents/{$created['id']}")
        ->assertNoContent();

    expect(CardDocument::find($created['id']))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('rejects a disallowed file type and an oversized file', function () {
    $user = User::factory()->create();
    [$board, $card] = docCard($user);
    $this->actingAs($user);

    // Executable — not in the allowlist.
    $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", [
        'file' => UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

    // 21MB > 20MB cap.
    $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/documents", [
        'file' => UploadedFile::fake()->create('big.pdf', 21000, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});
