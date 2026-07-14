<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\EmailStageAutomation;
use App\Infrastructure\Models\EmailStageSend;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Jobs\SendIntakeConfirmationJob;
use App\Mail\IntakeConfirmationMail;
use App\Mail\StageAutomationMail;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

/** Board with an intake token + at least one column; opt-in flag toggled per test. */
function optinBoard(bool $requireOptin): array
{
    $user = User::factory()->create();
    $board = Board::create([
        'user_id' => $user->id, 'name' => 'Intake', 'description' => '', 'type' => 'crm', 'currency' => 'BRL',
        'intake_token' => 'tok_'.str_repeat('c', 40),
        'require_optin_before_email' => $requireOptin,
    ]);
    Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 0]);

    return [$user, $board];
}

function submit(Board $board, array $answers): void
{
    test()->postJson("/api/webhooks/intake/{$board->intake_token}", [
        'submissionID' => '123',
        'rawRequest' => json_encode($answers),
    ])->assertCreated();
}

it('queues a confirmation email when the board requires opt-in', function () {
    Bus::fake();
    [, $board] = optinBoard(true);

    submit($board, ['q1_subject' => 'Quote please', 'q3_name' => ['first' => 'Ada'], 'q4_email' => 'ada@client.com']);

    Bus::assertDispatched(SendIntakeConfirmationJob::class);
    $contact = Contact::where('board_id', $board->id)->first();
    expect($contact->email)->toBe('ada@client.com');
});

it('does not send a confirmation email when opt-in is off (default)', function () {
    Bus::fake();
    [, $board] = optinBoard(false);

    submit($board, ['q1_subject' => 'Quote please', 'q4_email' => 'ada@client.com']);

    Bus::assertNotDispatched(SendIntakeConfirmationJob::class);
});

it('does not send a confirmation when the submission has no email', function () {
    Bus::fake();
    [, $board] = optinBoard(true);

    submit($board, ['q1_subject' => 'Anon', 'q5_phone' => '555']);

    Bus::assertNotDispatched(SendIntakeConfirmationJob::class);
});

it('the job mints a token and sends the confirmation mail', function () {
    Mail::fake();
    [, $board] = optinBoard(true);
    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com']);

    (new SendIntakeConfirmationJob($contact->id))->handle(app(App\Services\IntakeConfirmationService::class));

    $contact->refresh();
    expect($contact->confirm_token)->not->toBeNull();
    Mail::assertSent(IntakeConfirmationMail::class, fn (IntakeConfirmationMail $m) => $m->hasTo('ada@client.com')
        && str_contains($m->confirmUrl, $contact->confirm_token));
});

it('confirms the contact when the opt-in link is visited, and is idempotent', function () {
    [, $board] = optinBoard(true);
    $contact = Contact::create([
        'board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com',
        'confirm_token' => str_repeat('z', 40),
    ]);

    $this->get("/api/webhooks/intake/confirm/{$contact->confirm_token}")
        ->assertOk()
        ->assertSee("You're all set", false);

    $contact->refresh();
    expect($contact->confirmed_at)->not->toBeNull();
    $firstConfirmedAt = $contact->confirmed_at;

    // Re-click stays successful and doesn't move the timestamp.
    $this->get("/api/webhooks/intake/confirm/{$contact->confirm_token}")->assertOk();
    expect($contact->fresh()->confirmed_at->equalTo($firstConfirmedAt))->toBeTrue();
});

it('shows an invalid page for an unknown token', function () {
    optinBoard(true);

    $this->get('/api/webhooks/intake/confirm/'.str_repeat('x', 40))
        ->assertNotFound()
        ->assertSee('Link not valid', false);
});

it('skips the stage email for an unconfirmed contact when opt-in is required', function () {
    Mail::fake();
    [$user, $board] = optinBoard(true);
    $to = Section::create(['board_id' => $board->id, 'name' => 'Proposal', 'order' => 1]);
    EmailStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $to->id,
        'subject' => 'Your proposal', 'body' => 'Ready.', 'enabled' => true,
    ]);
    $from = Section::where('board_id', $board->id)->where('order', 0)->first();
    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com']); // unconfirmed
    $card = Card::create(['board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Deal', 'description' => '', 'contact_id' => $contact->id]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])->assertOk();

    Mail::assertNotSent(StageAutomationMail::class);
    expect(EmailStageSend::where('card_id', $card->id)->where('status', 'skipped')->count())->toBe(1);
});

it('sends the stage email once the contact is confirmed', function () {
    Mail::fake();
    [$user, $board] = optinBoard(true);
    $to = Section::create(['board_id' => $board->id, 'name' => 'Proposal', 'order' => 1]);
    EmailStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $to->id,
        'subject' => 'Your proposal', 'body' => 'Ready.', 'enabled' => true,
    ]);
    $from = Section::where('board_id', $board->id)->where('order', 0)->first();
    $contact = Contact::create([
        'board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com', 'confirmed_at' => now(),
    ]);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Deal', 'description' => '', 'contact_id' => $contact->id]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])->assertOk();

    Mail::assertSent(StageAutomationMail::class);
});
