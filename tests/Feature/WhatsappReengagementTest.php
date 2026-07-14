<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use App\Infrastructure\Models\WhatsappReengagementPolicy;
use App\Notifications\LeadDroppedNotification;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** WhatsApp-connected board with Leads / Won / Lost sections. */
function reBoard(User $user): array
{
    $board = Board::create([
        'user_id' => $user->id, 'name' => 'CRM', 'description' => '',
        'whatsapp_provider' => 'meta', 'whatsapp_phone_number_id' => '111222333',
        'whatsapp_token' => 'tok', 'whatsapp_app_secret' => 'shhh',
    ]);
    $leads = Section::create(['board_id' => $board->id, 'name' => 'Leads', 'order' => 0]);
    $won = Section::create(['board_id' => $board->id, 'name' => 'Won', 'order' => 1]);
    $lost = Section::create(['board_id' => $board->id, 'name' => 'Lost', 'order' => 2]);

    return [$board, $leads, $won, $lost];
}

/** A lead card + an idle conversation (last reply `$idleDays` ago). Unique phone per call. */
function reLead(Board $board, Section $section, int $idleDays = 40, array $convo = [], string $phone = '5511999'): array
{
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Lead', 'description' => '']);
    $conversation = WhatsappConversation::create(array_merge([
        'board_id' => $board->id, 'card_id' => $card->id, 'wa_phone' => $phone,
        'last_inbound_at' => now()->subDays($idleDays),
        'service_window_expires_at' => now()->subDays($idleDays)->addDay(),
    ], $convo));

    return [$card, $conversation];
}

function rePolicy(Board $board, array $attrs = []): WhatsappReengagementPolicy
{
    return WhatsappReengagementPolicy::create(array_merge([
        'board_id' => $board->id, 'enabled' => true, 'idle_days' => 30,
        'retry_interval_days' => 7, 'max_attempts' => 4,
        'template_name' => 'reengage_v1', 'language' => 'pt_BR',
    ], $attrs));
}

it('sends a template to an idle lead and advances the attempt counter', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.R']]], 200)]);
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board);
    [, $conversation] = reLead($board, $leads);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    $out = WhatsappMessage::where('direction', 'out')->first();
    expect($out?->template_name)->toBe('reengage_v1');
    expect($conversation->fresh()->reengagement_attempts)->toBe(1);
    expect($conversation->fresh()->last_reengagement_at)->not->toBeNull();
    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'reengage_v1'));
});

it('respects the retry interval — no re-send before the interval, sends after', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.R']]], 200)]);
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board, ['retry_interval_days' => 7]);
    [, $conversation] = reLead($board, $leads, convo: [
        'reengagement_attempts' => 1, 'last_reengagement_at' => now()->subDays(3),
    ]);

    $this->artisan('whatsapp:reengage')->assertSuccessful();
    expect($conversation->fresh()->reengagement_attempts)->toBe(1); // too soon

    $conversation->update(['last_reengagement_at' => now()->subDays(8)]);
    $this->artisan('whatsapp:reengage')->assertSuccessful();
    expect($conversation->fresh()->reengagement_attempts)->toBe(2); // interval passed
});

it('drops the lead to the Lost stage and notifies the owner at max attempts', function () {
    Notification::fake();
    Http::fake();
    $owner = User::factory()->create();
    [$board, $leads, , $lost] = reBoard($owner);
    rePolicy($board, ['max_attempts' => 4, 'lost_section_id' => $lost->id]);
    [$card] = reLead($board, $leads, convo: ['reengagement_attempts' => 4]);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    expect($card->fresh()->section_id)->toBe($lost->id);
    expect(WhatsappMessage::where('direction', 'out')->count())->toBe(0); // drop sends nothing
    Notification::assertSentTo($owner, LeadDroppedNotification::class);
});

it('archives the lead when no Lost stage is configured, and still notifies', function () {
    Notification::fake();
    Http::fake();
    $owner = User::factory()->create();
    [$board, $leads] = reBoard($owner);
    rePolicy($board, ['max_attempts' => 4, 'lost_section_id' => null]);
    [$card] = reLead($board, $leads, convo: ['reengagement_attempts' => 4]);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    expect($card->fresh()->archived_at)->not->toBeNull();
    Notification::assertSentTo($owner, LeadDroppedNotification::class);
});

it('never cold-starts a lead that has no conversation', function () {
    Http::fake();
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board);
    Card::create(['board_id' => $board->id, 'section_id' => $leads->id, 'name' => 'Cold', 'description' => '']);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    Http::assertNothingSent();
    expect(WhatsappMessage::count())->toBe(0);
});

it('skips a conversation whose number quality is degraded', function () {
    Http::fake();
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board);
    reLead($board, $leads, convo: ['quality_state' => 'red']);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    Http::assertNothingSent();
});

it('excludes completed (done) and archived leads', function () {
    Http::fake();
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board);
    [$done] = reLead($board, $leads, phone: '5511001');
    $done->update(['done_at' => now()]);
    [$archived] = reLead($board, $leads, phone: '5511002');
    $archived->update(['archived_at' => now()]);

    $this->artisan('whatsapp:reengage')->assertSuccessful();

    Http::assertNothingSent();
});

it('resets the ladder when the lead replies', function () {
    [$board, $leads] = reBoard(User::factory()->create());
    [, $conversation] = reLead($board, $leads, convo: [
        'reengagement_attempts' => 3, 'last_reengagement_at' => now()->subDay(),
    ]);

    app(WhatsappService::class)->handleInbound($board, ['entry' => [['changes' => [['value' => [
        'contacts' => [['wa_id' => '5511999', 'profile' => ['name' => 'Lead']]],
        'messages' => [['from' => '5511999', 'id' => 'wamid.IN', 'type' => 'text', 'text' => ['body' => 'oi']]],
    ]]]]]]);

    expect($conversation->fresh()->reengagement_attempts)->toBe(0);
    expect($conversation->fresh()->last_reengagement_at)->toBeNull();
});

it('does nothing for a disabled policy', function () {
    Http::fake();
    [$board, $leads] = reBoard(User::factory()->create());
    rePolicy($board, ['enabled' => false]);
    reLead($board, $leads);

    $this->artisan('whatsapp:reengage')->assertSuccessful();
    Http::assertNothingSent();
});

it('owner-gates the policy endpoint', function () {
    $owner = User::factory()->create();
    [$board] = reBoard($owner);

    // Non-owner is rejected.
    $this->actingAs(User::factory()->create())
        ->putJson("/api/boards/{$board->id}/whatsapp/reengagement", ['template_name' => 't'])
        ->assertStatus(403);

    // Owner can save.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/whatsapp/reengagement", [
            'enabled' => true, 'idle_days' => 30, 'template_name' => 'reengage_v1',
        ])
        ->assertStatus(201)
        ->assertJsonPath('template_name', 'reengage_v1');
});
