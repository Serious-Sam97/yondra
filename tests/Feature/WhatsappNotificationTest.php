<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\User;
use App\Notifications\CardAssignedNotification;
use App\Services\Notifier;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // A generic approved alert template is configured; board falls back to these creds.
    config([
        'services.whatsapp.notification_template' => 'yondra_alert',
        'services.whatsapp.meta.phone_number_id' => '111222333',
        'services.whatsapp.meta.token' => 'tok',
    ]);
});

function notifyBoard(User $owner): Board
{
    return Board::create(['user_id' => $owner->id, 'name' => 'CRM', 'description' => '']);
}

function assignNotification(User $actor, Board $board): CardAssignedNotification
{
    return new CardAssignedNotification(
        actorId: (int) $actor->id,
        actorName: $actor->name,
        boardId: (int) $board->id,
        cardId: 1,
        cardName: 'Fix the sink',
    );
}

it('delivers a notification over WhatsApp when the user opted in', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.N']]], 200)]);
    $actor = User::factory()->create();
    $recipient = User::factory()->create([
        'whatsapp_number' => '5511888',
        'notification_preferences' => ['assignment' => ['whatsapp' => true]],
    ]);
    $board = notifyBoard($actor);

    resolve(Notifier::class)->send($recipient, assignNotification($actor, $board));

    Http::assertSent(function ($req) {
        $body = json_encode($req->data());
        return str_contains($req->url(), '111222333/messages')
            && str_contains($body, 'yondra_alert')
            && str_contains($body, '5511888');
    });
});

it('does not use WhatsApp when the user has not opted in', function () {
    Http::fake();
    $actor = User::factory()->create();
    $recipient = User::factory()->create([
        'whatsapp_number' => '5511888',
        // assignment.whatsapp defaults to false.
    ]);

    resolve(Notifier::class)->send($recipient, assignNotification($actor, notifyBoard($actor)));

    Http::assertNothingSent();
});

it('does not use WhatsApp when the user has no number on file', function () {
    Http::fake();
    $actor = User::factory()->create();
    $recipient = User::factory()->create([
        'notification_preferences' => ['assignment' => ['whatsapp' => true]],
    ]);

    resolve(Notifier::class)->send($recipient, assignNotification($actor, notifyBoard($actor)));

    Http::assertNothingSent();
});

it('does not use WhatsApp when no template is configured', function () {
    config(['services.whatsapp.notification_template' => null]);
    Http::fake();
    $actor = User::factory()->create();
    $recipient = User::factory()->create([
        'whatsapp_number' => '5511888',
        'notification_preferences' => ['assignment' => ['whatsapp' => true]],
    ]);

    resolve(Notifier::class)->send($recipient, assignNotification($actor, notifyBoard($actor)));

    Http::assertNothingSent();
});

it('exposes whatsapp as a selectable preference channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/notifications/preferences')
        ->assertOk()
        ->assertJsonFragment(['key' => 'whatsapp', 'label' => 'WhatsApp']);
});
