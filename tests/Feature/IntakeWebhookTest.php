<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A board with an enabled intake token and ordered columns; returns [$board, $firstSection]. */
function intakeBoard(): array
{
    $user = User::factory()->create();
    $board = Board::create([
        'user_id' => $user->id,
        'name' => 'Intake Board',
        'description' => '',
        'intake_token' => 'tok_'.str_repeat('a', 40),
    ]);
    $first = Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 0]);
    Section::create(['board_id' => $board->id, 'name' => 'In Progress', 'order' => 1]);

    return [$board, $first];
}

/** Build a JotForm-style multipart payload with a rawRequest answer set. */
function jotformPayload(array $answers, array $extra = []): array
{
    return array_merge([
        'formID' => '2510001',
        'submissionID' => '99887766',
        'rawRequest' => json_encode($answers),
    ], $extra);
}

it('rejects an unknown intake token with 404', function () {
    intakeBoard();

    $this->postJson('/api/webhooks/intake/does-not-exist', jotformPayload([
        'q3_name' => ['first' => 'Jane', 'last' => 'Doe'],
    ]))->assertNotFound();

    expect(Card::count())->toBe(0);
});

it('creates a card in the first column from a form submission', function () {
    [$board, $first] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_projectTitle' => 'Kitchen remodel quote',
        'q3_name' => ['first' => 'Jane', 'last' => 'Doe'],
        'q4_email' => 'jane@example.com',
        'q5_phone' => '+1 555 8899',
        'q6_message' => 'Please quote a full kitchen remodel.',
    ]))->assertCreated()->assertJson(['ok' => true]);

    $card = Card::first();
    expect($card)->not->toBeNull();
    expect($card->section_id)->toBe($first->id);
    expect($card->name)->toBe('Kitchen remodel quote');
    expect($card->description)->toContain('Please quote a full kitchen remodel.');
    expect($card->created_by_user_id)->toBeNull();
    expect($card->ticket_number)->not->toBeNull();
});

it('maps and links the client as a board-scoped contact', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'New enquiry',
        'q3_name' => ['first' => 'Jane', 'last' => 'Doe'],
        'q4_email' => 'jane@example.com',
        'q5_phone' => '555-8899',
    ]))->assertCreated();

    $contact = Contact::where('board_id', $board->id)->first();
    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Jane Doe');
    expect($contact->email)->toBe('jane@example.com');
    expect($contact->phone)->toBe('555-8899');
    expect(Card::first()->contact_id)->toBe($contact->id);
});

it('appends unmapped fields to the description so nothing is lost', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Quote request',
        'q7_budgetRange' => '$5k–$10k',
        'q8_preferredContact' => 'Evenings',
    ]))->assertCreated();

    $desc = Card::first()->description;
    expect($desc)->toContain('Submission details');
    expect($desc)->toContain('Budget Range');
    expect($desc)->toContain('$5k–$10k');
    expect($desc)->toContain('Preferred Contact');
});

it('downloads attached files and stores them as card documents', function () {
    Storage::fake('local');
    [$board] = intakeBoard();

    Http::fake([
        '*' => Http::response('%PDF-1.4 fake pdf bytes', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Quote these drawings',
        'q9_upload' => ['https://www.jotform.com/uploads/acme/2510001/99887766/floorplan.pdf'],
    ]))->assertCreated();

    $card = Card::first();
    $doc = CardDocument::where('card_id', $card->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->original_name)->toBe('floorplan.pdf');
    expect($doc->user_id)->toBeNull();
    expect($doc->disk)->toBe('local');
    Storage::disk('local')->assertExists($doc->path);

    // The file URL is not echoed back into the description as text.
    expect($card->description)->not->toContain('jotform.com/uploads');
});

it('skips attachments whose type is not allowed', function () {
    Storage::fake('local');
    [$board] = intakeBoard();
    Http::fake(['*' => Http::response('bad', 200)]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Sketchy',
        'q9_upload' => ['https://www.jotform.com/uploads/acme/x/y/malware.exe'],
    ]))->assertCreated();

    expect(CardDocument::count())->toBe(0);
});

it('appends the JotForm api key when downloading gated uploads', function () {
    Storage::fake('local');
    config(['services.jotform.api_key' => 'SECRETKEY']);
    [$board] = intakeBoard();

    Http::fake(['*' => Http::response('%PDF- bytes', 200, ['Content-Type' => 'application/pdf'])]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Quote',
        'q9_upload' => ['https://www.jotform.com/uploads/acme/x/y/plan.pdf'],
    ]))->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'apiKey=SECRETKEY'));
});

it('still creates the card when the form carried no contact details', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Anonymous request',
    ]))->assertCreated();

    expect(Card::count())->toBe(1);
    expect(Contact::count())->toBe(0);
    expect(Card::first()->contact_id)->toBeNull();
});

it('falls back to a submission reference when no title field exists', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q7_budget' => '1000',
    ]))->assertCreated();

    expect(Card::first()->name)->toBe('Intake submission #99887766');
});

it('never leaks JotForm housekeeping fields into the card', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Clean card',
        'q7_budget' => '1000',
    ]))->assertCreated();

    $desc = Card::first()->description;
    // formID / submissionID / rawRequest / pretty must not surface anywhere.
    expect($desc)->not->toContain('99887766');
    expect($desc)->not->toContain('2510001');
    expect(strtolower($desc))->not->toContain('rawrequest');
    expect(strtolower($desc))->not->toContain('form id');
});

it('logs a system board activity for the intake', function () {
    [$board] = intakeBoard();

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Logged enquiry',
    ]))->assertCreated();

    $activity = BoardActivity::where('board_id', $board->id)->first();
    expect($activity)->not->toBeNull();
    expect($activity->user_id)->toBeNull();
    expect($activity->description)->toContain('Logged enquiry');
});

it('applies a configured field map to card value, tags, priority and due date', function () {
    [$board] = intakeBoard();
    $board->update(['intake_field_map' => [
        ['source' => 'project', 'target' => 'title'],
        ['source' => 'budget', 'target' => 'value'],
        ['source' => 'service', 'target' => 'tags'],
        ['source' => 'urgency', 'target' => 'priority'],
        ['source' => 'deadline', 'target' => 'due_date'],
    ]]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_project' => 'Loft conversion',
        'q2_budget' => 'R$25.000,00',
        'q3_service' => 'Design, Build',
        'q4_urgency' => 'Very urgent',
        'q5_deadline' => '2026-09-15',
        'q6_extra' => 'Keep this in details',
    ]))->assertCreated();

    $card = Card::with('tags')->first();
    expect($card->name)->toBe('Loft conversion');
    expect((float) $card->value)->toBe(25000.0);
    expect($card->priority)->toBe('high');
    expect($card->due_date->format('Y-m-d'))->toBe('2026-09-15');
    expect($card->tags->pluck('name')->sort()->values()->all())->toBe(['Build', 'Design']);
    // Auto-created tags are board-scoped.
    expect(App\Infrastructure\Models\Tag::where('board_id', $board->id)->count())->toBe(2);
    // Unmapped field still preserved.
    expect($card->description)->toContain('Keep this in details');
});

it('reuses an existing tag by name (case-insensitive) instead of duplicating', function () {
    [$board] = intakeBoard();
    App\Infrastructure\Models\Tag::create(['board_id' => $board->id, 'name' => 'Design', 'color' => '#123456']);
    $board->update(['intake_field_map' => [['source' => 'service', 'target' => 'tags']]]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Job', 'q2_service' => 'design',
    ]))->assertCreated();

    expect(App\Infrastructure\Models\Tag::where('board_id', $board->id)->count())->toBe(1);
    expect(Card::with('tags')->first()->tags->first()->name)->toBe('Design');
});

it('falls back to heuristics for targets the map does not cover', function () {
    [$board] = intakeBoard();
    $board->update(['intake_field_map' => [['source' => 'budget', 'target' => 'value']]]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'Heuristic title',
        'q2_budget' => '5000',
        'q3_name' => ['first' => 'Jo', 'last' => 'Lee'],
        'q4_email' => 'jo@example.com',
    ]))->assertCreated();

    $card = Card::with('contact')->first();
    expect($card->name)->toBe('Heuristic title');       // heuristic subject
    expect((float) $card->value)->toBe(5000.0);          // mapped
    expect($card->contact->name)->toBe('Jo Lee');        // heuristic contact
    expect($card->contact->email)->toBe('jo@example.com');
});

it('returns 422 when the board has no columns', function () {
    $user = User::factory()->create();
    $board = Board::create([
        'user_id' => $user->id,
        'name' => 'Empty',
        'description' => '',
        'intake_token' => 'tok_empty_'.str_repeat('b', 32),
    ]);

    $this->postJson("/api/webhooks/intake/{$board->intake_token}", jotformPayload([
        'q1_subject' => 'x',
    ]))->assertStatus(422);
});
