<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\PaymentMilestone;
use App\Infrastructure\Models\PaymentMilestoneEvent;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase as QaTestCase;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use App\Mail\StageAutomationMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * A CRM board with Lead / Half / Invoice stages and a R$1000 deal for a contact
 * with an email. Returns [board, sections[], card, contact].
 */
function payBoard(User $user, array $boardAttrs = []): array
{
    $board = Board::create(array_merge([
        'user_id' => $user->id, 'name' => 'Sales', 'description' => '',
        'type' => 'crm', 'currency' => 'BRL',
    ], $boardAttrs));
    $lead = Section::create(['board_id' => $board->id, 'name' => 'Lead', 'order' => 0]);
    $half = Section::create(['board_id' => $board->id, 'name' => 'Half paid', 'order' => 1]);
    $invoice = Section::create(['board_id' => $board->id, 'name' => 'Invoice', 'order' => 2]);

    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Acme', 'email' => 'buyer@acme.test']);
    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $lead->id, 'contact_id' => $contact->id,
        'name' => 'Website deal', 'description' => '', 'value' => 1000,
    ]);

    return [$board, compact('lead', 'half', 'invoice'), $card, $contact];
}

function milestone(Board $board, int $pct, array $attrs = []): PaymentMilestone
{
    return PaymentMilestone::create(array_merge([
        'board_id' => $board->id, 'threshold_pct' => $pct, 'enabled' => true,
        'notify' => true, 'channel' => 'email',
        'email_subject' => 'Payment {{payment_pct}}', 'email_body' => 'Paid {{amount_paid}}, {{amount_remaining}} left.',
    ], $attrs));
}

function pay(User $user, Board $board, Card $card, float $amount): void
{
    test()->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/payments", ['amount' => $amount])
        ->assertStatus(201);
}

it('requires authentication', function () {
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);

    $this->postJson("/api/boards/{$board->id}/cards/{$card->id}/payments", ['amount' => 100])
        ->assertStatus(401);
});

it('accumulates payments into the cached total and summary', function () {
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);

    pay($user, $board, $card, 250);
    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/payments", ['amount' => 150])
        ->assertStatus(201);

    expect((float) $res->json('summary.amount_paid'))->toBe(400.0);
    expect((float) $res->json('summary.amount_remaining'))->toBe(600.0);
    expect((float) $res->json('summary.payment_pct'))->toBe(40.0);
    expect((float) $card->fresh()->amount_paid)->toBe(400.0);
    expect($res->json('payments'))->toHaveCount(2);
});

it('lowers the total when a payment is removed', function () {
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);

    pay($user, $board, $card, 400);
    $paymentId = $this->actingAs($user)
        ->getJson("/api/boards/{$board->id}/cards/{$card->id}/payments")
        ->json('payments.0.id');

    $res = $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/cards/{$card->id}/payments/{$paymentId}")
        ->assertOk();

    expect((float) $res->json('summary.amount_paid'))->toBe(0.0);
    expect((float) $card->fresh()->amount_paid)->toBe(0.0);
});

it('fires the 50% milestone once, emailing the contact', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);
    milestone($board, 50);
    milestone($board, 100);

    pay($user, $board, $card, 500); // exactly 50%

    $events = PaymentMilestoneEvent::where('card_id', $card->id)->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->threshold_pct)->toBe(50);
    expect($events->first()->message_status)->toBe('sent');
    expect($events->first()->message_channel)->toBe('email');
    Mail::assertSent(StageAutomationMail::class, 1);
});

it('does not re-fire a milestone on further payments', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);
    milestone($board, 50);

    pay($user, $board, $card, 500); // 50%
    pay($user, $board, $card, 100); // 60% — still past 50, must not re-fire

    expect(PaymentMilestoneEvent::where('card_id', $card->id)->where('threshold_pct', 50)->count())->toBe(1);
    Mail::assertSent(StageAutomationMail::class, 1);
});

it('fires both milestones in order and moves the card to invoice at 100%', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, $s, $card] = payBoard($user);
    milestone($board, 50);
    milestone($board, 100, ['move_to_section_id' => $s['invoice']->id]);

    pay($user, $board, $card, 1000); // jump straight to 100%

    $events = PaymentMilestoneEvent::where('card_id', $card->id)->orderBy('threshold_pct')->get();
    expect($events->pluck('threshold_pct')->all())->toBe([50, 100]);
    expect($card->fresh()->section_id)->toBe($s['invoice']->id);
    expect($events->last()->moved_to_section_id)->toBe($s['invoice']->id);
    Mail::assertSent(StageAutomationMail::class, 2);
});

it('auto channel falls back to email when there is no WhatsApp conversation', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);
    milestone($board, 50, ['channel' => 'auto', 'whatsapp_template_name' => 'pay_rest']);

    pay($user, $board, $card, 500);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->message_channel)->toBe('email');
    expect($event->message_status)->toBe('sent');
    Mail::assertSent(StageAutomationMail::class, 1);
});

it('sends via WhatsApp when a conversation exists', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.PAY']]], 200)]);
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user, [
        'whatsapp_provider' => 'meta', 'whatsapp_phone_number_id' => '111', 'whatsapp_token' => 'tok',
    ]);
    WhatsappConversation::create([
        'board_id' => $board->id, 'card_id' => $card->id, 'wa_phone' => '5511999',
        'service_window_expires_at' => now()->addDay(),
    ]);
    milestone($board, 50, ['channel' => 'whatsapp', 'whatsapp_template_name' => 'pay_rest', 'language' => 'pt_BR']);

    pay($user, $board, $card, 500);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->message_channel)->toBe('whatsapp');
    expect($event->message_status)->toBe('sent');
    expect(WhatsappMessage::where('direction', 'out')->where('template_name', 'pay_rest')->count())->toBe(1);
});

it('skips a WhatsApp-only milestone when the card has no conversation', function () {
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);
    milestone($board, 50, ['channel' => 'whatsapp', 'whatsapp_template_name' => 'pay_rest']);

    pay($user, $board, $card, 500);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->message_status)->toBe('skipped');
});

it('skips the email when the board requires opt-in and the contact is unconfirmed', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user, ['require_optin_before_email' => true]);
    milestone($board, 50); // email channel

    pay($user, $board, $card, 500);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->message_status)->toBe('skipped');
    Mail::assertNothingSent();
});

it('does not fire any milestone when the deal has no value', function () {
    $user = User::factory()->create();
    [$board, , $card] = payBoard($user);
    $card->update(['value' => null]);
    milestone($board, 50);

    pay($user, $board, $card, 500);

    expect(PaymentMilestoneEvent::where('card_id', $card->id)->count())->toBe(0);
});

it('records a blocked move when the quality gate rejects the invoice stage', function () {
    $user = User::factory()->create();
    // Invoice is the done column and QA is on; a never-run test case blocks entry.
    [$board, $s, $card] = payBoard($user, ['qa_enabled' => true]);
    $board->update(['done_section_id' => $s['invoice']->id]);
    QaTestCase::create(['card_id' => $card->id, 'board_id' => $board->id, 'title' => 'smoke']);
    milestone($board, 100, ['notify' => false, 'move_to_section_id' => $s['invoice']->id]);

    pay($user, $board, $card, 1000);

    $event = PaymentMilestoneEvent::where('card_id', $card->id)->first();
    expect($event->moved_to_section_id)->toBeNull();
    expect($event->error)->toContain('quality gate');
    expect($card->fresh()->section_id)->toBe($s['lead']->id); // stayed put
});

it('gates payment writes and milestone config by permission', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, , $card] = payBoard($owner);

    // A stranger can neither add a payment nor read milestone config.
    $this->actingAs($stranger)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/payments", ['amount' => 100])
        ->assertStatus(403);
    $this->actingAs($stranger)
        ->getJson("/api/boards/{$board->id}/payment-milestones")
        ->assertStatus(403);

    // The owner can configure milestones.
    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/payment-milestones", ['threshold_pct' => 50, 'notify' => false])
        ->assertStatus(201);
});
