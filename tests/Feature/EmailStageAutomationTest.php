<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\EmailStageAutomation;
use App\Infrastructure\Models\EmailStageSend;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Mail\StageAutomationMail;
use Illuminate\Support\Facades\Mail;

/** Board with two funnel stages; card starts in $from. */
function crmBoardWithStages(User $user): array
{
    $board = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'BRL']);
    $from = Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 1]);
    $to = Section::create(['board_id' => $board->id, 'name' => 'Follow-up', 'order' => 2]);

    return [$board, $from, $to];
}

it('sends the stage email to the card contact when the card enters a configured stage', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, $from, $to] = crmBoardWithStages($user);

    EmailStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $to->id,
        'subject' => 'Hi {{contact_name}} — {{stage}}',
        'body' => "Your deal {{card_name}} worth {{deal_value}} needs a decision by {{deadline}}.",
        'enabled' => true,
    ]);

    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com']);
    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Big Deal', 'description' => '',
        'contact_id' => $contact->id, 'value' => 12000.50, 'due_date' => '2026-08-01',
    ]);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])
        ->assertOk();

    Mail::assertSent(StageAutomationMail::class, function (StageAutomationMail $mail) {
        return $mail->hasTo('ada@client.com')
            && $mail->subjectLine === 'Hi Ada — Follow-up'
            && str_contains($mail->bodyHtml, 'R$12,000.50')
            && str_contains($mail->bodyHtml, 'August 1, 2026');
    });

    expect(EmailStageSend::where('card_id', $card->id)->where('status', 'sent')->count())->toBe(1);
});

it('does not send when the automation is disabled', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, $from, $to] = crmBoardWithStages($user);

    EmailStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $to->id,
        'subject' => 'x', 'body' => 'y', 'enabled' => false,
    ]);

    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Deal', 'description' => '', 'contact_id' => $contact->id]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])->assertOk();

    Mail::assertNothingSent();
});

it('does not send when the automation is paused', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, $from, $to] = crmBoardWithStages($user);

    EmailStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $to->id,
        'subject' => 'x', 'body' => 'y', 'enabled' => true, 'paused_at' => now(),
    ]);

    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Ada', 'email' => 'ada@client.com']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Deal', 'description' => '', 'contact_id' => $contact->id]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])->assertOk();

    Mail::assertNothingSent();
});

it('does not send when the card has no contact email', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$board, $from, $to] = crmBoardWithStages($user);

    EmailStageAutomation::create(['board_id' => $board->id, 'section_id' => $to->id, 'subject' => 'x', 'body' => 'y', 'enabled' => true]);

    // Contact with no email.
    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Anon']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $from->id, 'name' => 'Deal', 'description' => '', 'contact_id' => $contact->id]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $to->id])->assertOk();

    Mail::assertNothingSent();
    expect(EmailStageSend::count())->toBe(0);
});

it('upserts and links a contact from the nested card payload', function () {
    $user = User::factory()->create();
    [$board, $from] = crmBoardWithStages($user);

    $res = $this->actingAs($user)->postJson("/api/boards/{$board->id}/cards", [
        'section_id' => $from->id,
        'name' => 'Lead',
        'contact' => ['name' => 'Grace', 'email' => 'grace@client.com', 'phone' => '+15550001'],
    ])->assertCreated();

    $cardId = $res->json('id');
    $card = Card::find($cardId);
    expect($card->contact_id)->not->toBeNull();

    $contact = Contact::find($card->contact_id);
    expect($contact->board_id)->toBe($board->id)
        ->and($contact->email)->toBe('grace@client.com');

    // Editing the same card updates the SAME contact row, not a new one.
    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$cardId}", [
        'contact' => ['name' => 'Grace H', 'email' => 'grace.h@client.com', 'phone' => ''],
    ])->assertOk();

    expect(Contact::where('board_id', $board->id)->count())->toBe(1)
        ->and(Contact::find($card->contact_id)->email)->toBe('grace.h@client.com');
});
