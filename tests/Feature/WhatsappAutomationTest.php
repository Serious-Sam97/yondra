<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use App\Infrastructure\Models\WhatsappStageAutomation;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Http;

/** A board with working WhatsApp creds, a "Leads" and a "Won" section. */
function autoBoard(User $user): array
{
    $board = Board::create([
        'user_id' => $user->id, 'name' => 'CRM', 'description' => '',
        'whatsapp_provider' => 'meta',
        'whatsapp_phone_number_id' => '111222333',
        'whatsapp_token' => 'tok',
        'whatsapp_app_secret' => 'shhh',
    ]);
    $leads = Section::create(['board_id' => $board->id, 'name' => 'Leads', 'order' => 0]);
    $won = Section::create(['board_id' => $board->id, 'name' => 'Won', 'order' => 1]);

    return [$board, $leads, $won];
}

function autoCard(Board $board, Section $section, bool $withConversation = true): Card
{
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Lead', 'description' => '']);
    if ($withConversation) {
        WhatsappConversation::create([
            'board_id' => $board->id, 'card_id' => $card->id, 'wa_phone' => '5511999',
            'service_window_expires_at' => now()->addDay(),
        ]);
    }

    return $card;
}

it('sends the stage template when a card enters an automated section', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);
    $user = User::factory()->create();
    [$board, $leads, $won] = autoBoard($user);
    $card = autoCard($board, $leads);
    WhatsappStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $won->id,
        'template_name' => 'quote_ready', 'language' => 'pt_BR', 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])
        ->assertOk();

    $out = WhatsappMessage::where('direction', 'out')->first();
    expect($out)->not->toBeNull();
    expect($out->template_name)->toBe('quote_ready');
    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'quote_ready'));
});

it('does not send when the card has no opted-in conversation', function () {
    Http::fake();
    $user = User::factory()->create();
    [$board, $leads, $won] = autoBoard($user);
    $card = autoCard($board, $leads, withConversation: false);
    WhatsappStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $won->id,
        'template_name' => 'quote_ready', 'enabled' => true,
    ]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])->assertOk();

    Http::assertNothingSent();
    expect(WhatsappMessage::count())->toBe(0);
});

it('skips the automation while quality is degraded', function () {
    Http::fake();
    $user = User::factory()->create();
    [$board, $leads, $won] = autoBoard($user);
    $card = autoCard($board, $leads);
    $card->whatsappConversations()->first()->update(['quality_state' => 'red']);
    WhatsappStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $won->id,
        'template_name' => 'quote_ready', 'enabled' => true,
    ]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])->assertOk();

    Http::assertNothingSent();
});

it('skips a disabled automation', function () {
    Http::fake();
    $user = User::factory()->create();
    [$board, $leads, $won] = autoBoard($user);
    $card = autoCard($board, $leads);
    WhatsappStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $won->id,
        'template_name' => 'quote_ready', 'enabled' => false,
    ]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])->assertOk();

    Http::assertNothingSent();
});

it('pauses automations and flags conversations when quality drops', function () {
    $user = User::factory()->create();
    [$board, $leads, $won] = autoBoard($user);
    autoCard($board, $leads);
    $automation = WhatsappStageAutomation::create([
        'board_id' => $board->id, 'section_id' => $won->id,
        'template_name' => 'quote_ready', 'enabled' => true,
    ]);

    // Simulate a phone-number-quality webhook flagging the number.
    $payload = [
        'entry' => [['changes' => [[
            'field' => 'phone_number_quality_update',
            'value' => ['event' => 'FLAGGED', 'current_limit' => 'TIER_250'],
        ]]]],
    ];
    resolve(WhatsappService::class)->handleInbound($board, $payload);

    expect($automation->fresh()->paused_at)->not->toBeNull();
    expect($automation->fresh()->isActive())->toBeFalse();
    expect(WhatsappConversation::where('board_id', $board->id)->first()->quality_state)->toBe('red');
});

it('connects a board to WhatsApp: hides secrets, auto-issues a verify token', function () {
    $owner = User::factory()->create();
    [$board] = autoBoard($owner);
    // Start from a clean board (autoBoard pre-fills creds); clear them first.
    $board->update(['whatsapp_token' => null, 'whatsapp_verify_token' => null, 'whatsapp_app_secret' => null]);

    $res = $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}", [
            'whatsapp_provider' => 'meta',
            'whatsapp_phone_number_id' => '999888',
            'whatsapp_token' => 'permanent-token',
            'whatsapp_app_secret' => 'app-secret',
        ])
        ->assertOk()
        ->assertJson(['whatsapp_connected' => true, 'whatsapp_phone_number_id' => '999888']);

    // Secrets never travel back to the client.
    expect($res->json('whatsapp_token'))->toBeNull();
    expect($res->json('whatsapp_app_secret'))->toBeNull();
    // A verify token was auto-issued for the Meta webhook handshake.
    expect($res->json('whatsapp_verify_token'))->not->toBeEmpty();

    // Blank token on a later save keeps the stored one (doesn't wipe it).
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}", ['whatsapp_phone_number_id' => '777'])
        ->assertOk()
        ->assertJson(['whatsapp_connected' => true]);
    expect($board->fresh()->whatsapp_token)->toBe('permanent-token');
});

it('lets the owner configure and resume a stage automation, but forbids non-owners', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, , $won] = autoBoard($owner);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/whatsapp/automations/{$won->id}", [
            'template_name' => 'quote_ready', 'language' => 'pt_BR',
        ])
        ->assertCreated()
        ->assertJson(['template_name' => 'quote_ready', 'enabled' => true]);

    $this->actingAs($stranger)
        ->putJson("/api/boards/{$board->id}/whatsapp/automations/{$won->id}", ['template_name' => 'x'])
        ->assertForbidden();

    // Resume clears a quality pause.
    WhatsappStageAutomation::where('board_id', $board->id)->update(['paused_at' => now()]);
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/whatsapp/automations/{$won->id}", [
            'template_name' => 'quote_ready', 'resume' => true,
        ])
        ->assertOk();
    expect(WhatsappStageAutomation::where('board_id', $board->id)->first()->paused_at)->toBeNull();
});
