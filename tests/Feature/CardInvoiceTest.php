<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\CardInvoice;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\PaymentMilestone;
use App\Infrastructure\Models\PaymentMilestoneEvent;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Storage;

/**
 * A CRM board (Lead / Won stages) with a R$1000 deal for a contact. The generated
 * PDFs land on a faked local disk. Returns [board, sections[], card, contact].
 */
function invBoard(User $user, array $boardAttrs = []): array
{
    $board = Board::create(array_merge([
        'user_id' => $user->id, 'name' => 'Sales', 'description' => '',
        'type' => 'crm', 'currency' => 'BRL',
    ], $boardAttrs));
    $lead = Section::create(['board_id' => $board->id, 'name' => 'Lead', 'order' => 0]);
    $won = Section::create(['board_id' => $board->id, 'name' => 'Won', 'order' => 1]);

    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Acme Ltda', 'email' => 'buyer@acme.test', 'phone' => '5511999']);
    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $lead->id, 'contact_id' => $contact->id,
        'name' => 'Website deal', 'description' => '', 'value' => 1000,
    ]);

    return [$board, compact('lead', 'won'), $card, $contact];
}

function invMilestone(Board $board, int $pct, array $attrs = []): PaymentMilestone
{
    return PaymentMilestone::create(array_merge([
        'board_id' => $board->id, 'threshold_pct' => $pct, 'enabled' => true,
        'notify' => false, 'generate_invoice' => true,
    ], $attrs));
}

function invPay(User $user, Board $board, Card $card, float $amount): void
{
    test()->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/payments", ['amount' => $amount])
        ->assertStatus(201);
}

beforeEach(fn () => Storage::fake('local'));

it('generates a nota fiscal when the 100% milestone fires, attaching a PDF', function () {
    $user = User::factory()->create();
    [$board, , $card] = invBoard($user);
    invMilestone($board, 100);

    invPay($user, $board, $card, 1000);

    $invoice = CardInvoice::where('card_id', $card->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->number)->toBe(1);
    expect((float) $invoice->amount)->toBe(1000.0);
    expect($invoice->document_id)->not->toBeNull();

    $doc = CardDocument::find($invoice->document_id);
    expect($doc->mime_type)->toBe('application/pdf');
    expect($doc->user_id)->toBeNull(); // system-generated
    expect(Storage::disk('local')->exists($doc->path))->toBeTrue();

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->invoice_status)->toBe('issued');
    expect($event->invoice_number)->toBe(1);
});

it('also moves the card to Won when the milestone targets the won section', function () {
    $user = User::factory()->create();
    [$board, $s, $card] = invBoard($user);
    $board->update(['done_section_id' => $s['won']->id]);
    invMilestone($board, 100, ['move_to_section_id' => $s['won']->id]);

    invPay($user, $board, $card, 1000);

    $card->refresh();
    expect($card->section_id)->toBe($s['won']->id);
    expect($card->done_at)->not->toBeNull();
    expect(CardInvoice::where('card_id', $card->id)->exists())->toBeTrue();
});

it('still issues the invoice even when the stage move is blocked by the QA gate', function () {
    $user = User::factory()->create();
    [$board, $s, $card] = invBoard($user, ['qa_enabled' => true]);
    $board->update(['done_section_id' => $s['won']->id]);
    \App\Infrastructure\Models\TestCase::create(['card_id' => $card->id, 'board_id' => $board->id, 'title' => 'smoke']);
    invMilestone($board, 100, ['move_to_section_id' => $s['won']->id]);

    invPay($user, $board, $card, 1000);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->moved_to_section_id)->toBeNull();   // move blocked
    expect($event->invoice_status)->toBe('issued');    // invoice independent
    expect($card->fresh()->section_id)->toBe($s['lead']->id);
});

it('numbers invoices sequentially per board', function () {
    $user = User::factory()->create();
    [$board, , $cardA] = invBoard($user);
    $cardB = Card::create([
        'board_id' => $board->id, 'section_id' => $cardA->section_id,
        'name' => 'Second deal', 'description' => '', 'value' => 500,
    ]);
    invMilestone($board, 100);

    invPay($user, $board, $cardA, 1000);
    invPay($user, $board, $cardB, 500);

    expect(CardInvoice::where('card_id', $cardA->id)->value('number'))->toBe(1);
    expect(CardInvoice::where('card_id', $cardB->id)->value('number'))->toBe(2);
});

it('re-issues idempotently: same number, replaced document', function () {
    $user = User::factory()->create();
    [$board, , $card] = invBoard($user);
    $svc = app(InvoiceService::class);

    $first = $svc->issueForCard($card->fresh());
    $firstDocId = $first->document_id;

    $second = $svc->issueForCard($card->fresh());

    expect($second->id)->toBe($first->id);           // same ledger row
    expect($second->number)->toBe($first->number);   // stable number
    expect($second->document_id)->not->toBe($firstDocId); // fresh PDF
    expect(CardInvoice::where('card_id', $card->id)->count())->toBe(1);
    // Superseded document is gone (file + row).
    expect(CardDocument::find($firstDocId))->toBeNull();
});

it('snapshots the board issuer and card recipient onto the invoice', function () {
    $user = User::factory()->create();
    [$board, , $card, $contact] = invBoard($user, [
        'invoice_issuer' => ['name' => 'Studio Aurora', 'tax_id' => '12.345.678/0001-90'],
    ]);

    $invoice = app(InvoiceService::class)->issueForCard($card->fresh());

    expect($invoice->issuer['name'])->toBe('Studio Aurora');
    expect($invoice->issuer['tax_id'])->toBe('12.345.678/0001-90');
    expect($invoice->recipient['name'])->toBe($contact->name);
    expect($invoice->recipient['email'])->toBe($contact->email);
});

it('falls back to the board name as issuer when none is configured', function () {
    $user = User::factory()->create();
    [$board, , $card] = invBoard($user);

    $invoice = app(InvoiceService::class)->issueForCard($card->fresh());

    expect($invoice->issuer['name'])->toBe($board->name);
});

it('issues manually via the endpoint and returns the invoice in the payload', function () {
    $user = User::factory()->create();
    [$board, , $card] = invBoard($user);

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/invoice")
        ->assertStatus(201);

    expect($res->json('invoice.number'))->toBe(1);
    expect($res->json('invoice.document_id'))->not->toBeNull();
    expect(CardInvoice::where('card_id', $card->id)->exists())->toBeTrue();
});

it('bars a stranger from issuing an invoice', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, , $card] = invBoard($owner);

    $this->actingAs($stranger)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/invoice")
        ->assertStatus(403);
    expect(CardInvoice::where('card_id', $card->id)->exists())->toBeFalse();
});

it('persists the invoice issuer on the board via update', function () {
    $user = User::factory()->create();
    [$board] = invBoard($user);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}", [
            'invoice_issuer' => ['name' => 'Aurora', 'tax_id' => '999', 'email' => 'hi@aurora.dev'],
        ])
        ->assertOk();

    expect($board->fresh()->invoice_issuer['name'])->toBe('Aurora');
    expect($board->fresh()->invoice_issuer['email'])->toBe('hi@aurora.dev');
});
