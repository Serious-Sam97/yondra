<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Mail\VoidReportMail;
use App\Services\Vortex\OutsideService;
use App\Services\Vortex\SoulService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))])); // throwaway key for signed links

it('every channel is off until switched on, and the slack url must be a real webhook', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->getJson('/api/mascot/outside')->assertOk()
        ->assertJson(['email' => false, 'calendar' => null, 'slack' => null]);
    $this->actingAs($u)->postJson('/api/mascot/outside', ['slack' => 'https://evil.example/hook'])->assertStatus(422);
    $r = $this->actingAs($u)->postJson('/api/mascot/outside', ['email' => true, 'calendar' => true, 'slack' => 'https://hooks.slack.com/services/T1/B2/xyz'])->assertOk()->json('settings');
    expect($r['email'])->toBeTrue()
        ->and($r['calendar'])->toContain('/api/mascot/calendar/')
        ->and($r['slack'])->not->toContain('xyz'); // never echoes the secret back
});

it('serves the calendar by token, with deadlines and his dates', function () {
    $u = User::factory()->create();
    $board = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $s->id, 'name' => 'ship, the thing', 'description' => '', 'due_date' => now()->addDays(3)]);
    $url = $this->actingAs($u)->postJson('/api/mascot/outside', ['calendar' => true])->json('settings.calendar');
    $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->getContent();
    expect($ics)->toContain('BEGIN:VCALENDAR')->toContain('SUMMARY:due: ship\, the thing')->toContain('tape moon')->toContain('episode: Rewind Night')->toContain('RRULE:FREQ=WEEKLY');
    $this->get('/api/mascot/calendar/'.str_repeat('a', 40).'.ics')->assertNotFound();
    $this->actingAs($u)->postJson('/api/mascot/outside', ['calendar' => false]);
    $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
});

it('the weekly report goes only to opted-in users, the signed link unsubscribes', function () {
    Mail::fake();
    Http::fake();
    $in = User::factory()->create();
    $out = User::factory()->create();
    $this->actingAs($in)->postJson('/api/mascot/outside', ['email' => true, 'slack' => 'https://hooks.slack.com/services/T1/B2/xyz']);
    app(SoulService::class)->for($out);
    $this->artisan('vortex:outside-weekly')->assertSuccessful();
    Mail::assertQueued(VoidReportMail::class, 1);
    Mail::assertQueued(VoidReportMail::class, fn ($m) => $m->hasTo($in->email));
    Http::assertSentCount(1);

    $this->get(app(OutsideService::class)->unsubscribeUrl($in))->assertOk();
    expect(app(SoulService::class)->for($in)->fresh()->state['outside']['email'])->toBeFalse();
    $this->get(URL::route('vortex.unsubscribe', ['user' => $out->id]))->assertForbidden();
});

it('sends exactly one note after fourteen days gone', function () {
    Mail::fake();
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/api/mascot/outside', ['email' => true]);
    $soul = app(SoulService::class)->for($u);
    $soul->last_seen_at = now()->subDays(15);
    $soul->save();
    $svc = app(OutsideService::class);
    expect($svc->absence($u, $soul->fresh()))->toBeTrue()
        ->and($svc->absence($u, $soul->fresh()))->toBeFalse();
    Mail::assertQueued(VoidReportMail::class, 1);
});

it('api errors carry a comment from him, unless asked not to', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->getJson('/api/boards/999999')->assertNotFound()->assertJsonStructure(['vortex']);
    $r = $this->actingAs($u)->withHeader('X-Vortex', 'off')->getJson('/api/boards/999999')->assertNotFound()->json();
    expect($r)->not->toHaveKey('vortex');
});

it('the terminal key is read-only, shows open cards, and dies when switched off', function () {
    $u = User::factory()->create(['name' => 'Ana Lima']);
    $board = Board::create(['user_id' => $u->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $s = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $s->id, 'name' => 'late thing', 'description' => '', 'due_date' => now()->subDay()]);
    $key = $this->actingAs($u)->postJson('/api/mascot/outside', ['terminal' => true])->json('settings.terminal');
    $r = $this->getJson("/api/mascot/terminal/{$key}")->assertOk()->json();
    expect($r['name'])->toBe('Ana')->and($r['cards'][0])->toMatchArray(['name' => 'late thing', 'overdue' => true]);
    $this->actingAs($u)->postJson('/api/mascot/outside', ['terminal' => false]);
    $this->getJson("/api/mascot/terminal/{$key}")->assertNotFound();
});
