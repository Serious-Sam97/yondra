<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;

function waBoard(User $user): Board
{
    $board = Board::create([
        'user_id' => $user->id, 'name' => 'CRM', 'description' => '',
        'whatsapp_provider' => 'meta',
        'whatsapp_phone_number_id' => '111222333',
        'whatsapp_token' => 'tok',
        'whatsapp_app_secret' => 'shhh',
        'whatsapp_verify_token' => 'verify-me',
    ]);
    Section::create(['board_id' => $board->id, 'name' => 'Leads', 'order' => 0]);

    return $board;
}

/** Cloud-API inbound-message payload from a customer. */
function inboundPayload(string $from, string $text, string $name = 'Maria'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => '111222333'],
                    'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
                    'messages' => [[
                        'from' => $from, 'id' => 'wamid.IN1', 'timestamp' => '1700000000',
                        'type' => 'text', 'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

function signed(array $payload): array
{
    $raw = json_encode($payload);

    return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'shhh')];
}

it('verifies the webhook subscription handshake', function () {
    $board = waBoard(User::factory()->create());

    $this->get("/api/webhooks/whatsapp/{$board->id}?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=42")
        ->assertOk()
        ->assertSee('42');

    $this->get("/api/webhooks/whatsapp/{$board->id}?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42")
        ->assertForbidden();
});

it('rejects an inbound webhook with a bad signature', function () {
    $board = waBoard(User::factory()->create());
    $payload = inboundPayload('5511999', 'Oi');

    $this->withHeaders(['X-Hub-Signature-256' => 'sha256=nope'])
        ->postJson("/api/webhooks/whatsapp/{$board->id}", $payload)
        ->assertStatus(401);

    expect(WhatsappMessage::count())->toBe(0);
});

it('stores an inbound message, spawns a named lead card, and opens the window', function () {
    $board = waBoard(User::factory()->create());
    $payload = inboundPayload('5511999', 'Quero um orçamento', 'Rodrigo');

    $this->withHeaders(signed($payload))
        ->postJson("/api/webhooks/whatsapp/{$board->id}", $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'stored' => 1]);

    $conversation = WhatsappConversation::first();
    expect($conversation)->not->toBeNull();
    expect($conversation->contact_name)->toBe('Rodrigo');
    expect($conversation->windowOpen())->toBeTrue();

    // A lead card was created, named after the contact, carrying the message.
    $card = Card::find($conversation->card_id);
    expect($card)->not->toBeNull();
    expect($card->name)->toBe('Rodrigo');

    $message = WhatsappMessage::first();
    expect($message->direction)->toBe('in');
    expect($message->body)->toBe('Quero um orçamento');
    expect($message->status)->toBe('received');
});

it('reuses the conversation + card on a second message from the same number', function () {
    $board = waBoard(User::factory()->create());

    foreach (['first', 'second'] as $i => $text) {
        $payload = inboundPayload('5511999', $text);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = "wamid.IN{$i}";
        $this->withHeaders(signed($payload))->postJson("/api/webhooks/whatsapp/{$board->id}", $payload)->assertOk();
    }

    expect(WhatsappConversation::count())->toBe(1);
    expect(Card::count())->toBe(1);
    expect(WhatsappMessage::count())->toBe(2);
});

it('replies from the card inside the 24h window', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    $user = User::factory()->create();
    $board = waBoard($user);
    $payload = inboundPayload('5511999', 'Oi');
    $this->withHeaders(signed($payload))->postJson("/api/webhooks/whatsapp/{$board->id}", $payload)->assertOk();
    $card = Card::first();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/whatsapp", ['body' => 'Claro, segue o orçamento'])
        ->assertCreated()
        ->assertJson(['direction' => 'out', 'status' => 'sent', 'wa_message_id' => 'wamid.OUT']);

    Http::assertSent(fn ($req) => str_contains($req->url(), '111222333/messages'));
});

it('blocks a free-form reply once the window has closed', function () {
    $user = User::factory()->create();
    $board = waBoard($user);
    $card = Card::create(['board_id' => $board->id, 'section_id' => Section::first()->id, 'name' => 'Lead', 'description' => '']);
    $conversation = WhatsappConversation::create([
        'board_id' => $board->id, 'card_id' => $card->id, 'wa_phone' => '5511999',
        'service_window_expires_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/whatsapp", ['body' => 'late'])
        ->assertStatus(422);

    expect($conversation->messages()->count())->toBe(0);
});

it('advances message status from a status webhook without regressing', function () {
    $board = waBoard(User::factory()->create());
    $conversation = WhatsappConversation::create([
        'board_id' => $board->id, 'wa_phone' => '5511999', 'service_window_expires_at' => now()->addDay(),
    ]);
    $message = WhatsappMessage::create([
        'conversation_id' => $conversation->id, 'direction' => 'out',
        'wa_message_id' => 'wamid.OUT', 'type' => 'text', 'body' => 'hi', 'status' => 'sent',
    ]);

    $statusPayload = fn (string $state) => [
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => 'wamid.OUT', 'status' => $state, 'timestamp' => '1700000000', 'recipient_id' => '5511999']],
        ]]]]],
    ];

    $p = $statusPayload('read');
    $this->withHeaders(signed($p))->postJson("/api/webhooks/whatsapp/{$board->id}", $p)->assertOk();
    expect($message->fresh()->status)->toBe('read');

    // A late 'delivered' must not overwrite 'read'.
    $p2 = $statusPayload('delivered');
    $this->withHeaders(signed($p2))->postJson("/api/webhooks/whatsapp/{$board->id}", $p2)->assertOk();
    expect($message->fresh()->status)->toBe('read');
});
