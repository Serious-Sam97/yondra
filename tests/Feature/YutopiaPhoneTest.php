<?php

use App\Events\YutopiaPhoneEvent;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaContact;
use App\Infrastructure\Models\YutopiaMessage;
use App\Infrastructure\Models\YutopiaPhoto;
use App\Infrastructure\Models\YutopiaSpace;
use App\Jobs\NotifyYutopiaText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Yutopia's phone (yutopia/docs/plan/21-cellphone.md): settings, threads,
// texts, photos, Vortex, the call log.

beforeEach(function () {
    config(['yutopia.internal_secret' => 'internal-secret-for-tests-0123456789abc']);
    Event::fake([YutopiaPhoneEvent::class]);
    Queue::fake();
});

function phoneTeam(): array
{
    $ana = User::factory()->create(['name' => 'Ana Demo']);
    $bia = User::factory()->create(['name' => 'Bia Demo']);
    $caio = User::factory()->create(['name' => 'Caio']);
    $stranger = User::factory()->create();
    $p = Project::create(['owner_id' => $ana->id, 'name' => 'Studio']);
    $p->members()->attach($bia->id, ['role' => 'member']);
    $p->members()->attach($caio->id, ['role' => 'member']);
    $space = YutopiaSpace::create(['project_id' => $p->id, 'slug' => 'studio-'.$p->id, 'name' => 'Studio', 'map_key' => 'studio']);
    // their phone books: Ana has Bia and Caio, Bia has Ana
    foreach ([[$ana, $bia], [$ana, $caio], [$bia, $ana]] as [$a, $b]) {
        YutopiaContact::create(['user_id' => $a->id, 'contact_id' => $b->id]);
    }

    return [$ana, $bia, $caio, $stranger, $space];
}

function signedPhone($test, string $uri, array $body)
{
    $raw = json_encode($body);
    $ts = time();

    return $test->call('POST', $uri, [], [], [], [
        'HTTP_X_YUTOPIA_TIMESTAMP' => (string) $ts,
        'HTTP_X_YUTOPIA_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, config('yutopia.internal_secret')),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

it('keeps phone settings, cleaned', function () {
    [$ana] = phoneTeam();
    $this->actingAs($ana)->getJson('/api/yutopia/phone')->assertOk()->assertJsonPath('settings.case', 'walnut');
    $res = $this->actingAs($ana)->putJson('/api/yutopia/phone', ['settings' => [
        'case' => 'rust', 'ringtone' => 'vortex', 'wallpaper' => 'photo:12', 'theme' => 'light',
        'tones' => ['5' => 'bell', 'x' => 'bell', '6' => 'nope'], 'favourites' => [3, 3, -1], 'evil' => 'yes',
    ]])->assertOk();
    expect($res->json('settings.case'))->toBe('rust')
        ->and($res->json('settings.ringtone'))->toBe('vortex')
        ->and($res->json('settings.wallpaper'))->toBe('photo:12')
        ->and($res->json('settings.tones'))->toBe(['5' => 'bell'])
        ->and($res->json('settings.favourites'))->toBe([3])
        ->and($res->json('settings'))->not->toHaveKey('evil');
    $this->actingAs($ana)->getJson('/api/yutopia/phone')->assertJsonPath('settings.theme', 'light');
});

it('lists only the people you added, found by their user id', function () {
    [$ana, $bia, $caio, $stranger] = phoneTeam();
    $ids = fn ($u) => collect($this->actingAs($u)->getJson('/api/yutopia/phone/contacts')->assertOk()->json('contacts'))->pluck('uid')->all();
    expect($ids($ana))->toEqualCanonicalizing([$bia->id, $caio->id])
        ->and($ids($caio))->toBe([]); // Caio shares the project but added nobody

    // Caio looks Ana up by her id and adds her
    $this->actingAs($caio)->getJson("/api/yutopia/phone/users/{$ana->id}")->assertOk()
        ->assertJsonPath('person.name', 'Ana Demo')->assertJsonPath('added', false);
    $this->actingAs($caio)->getJson('/api/yutopia/phone/users/999999')->assertNotFound();
    $this->actingAs($caio)->getJson("/api/yutopia/phone/users/{$caio->id}")->assertNotFound();
    $this->actingAs($caio)->postJson('/api/yutopia/phone/contacts', ['id' => $ana->id])->assertCreated()->assertJsonPath('contact.uid', $ana->id);
    $this->actingAs($caio)->postJson('/api/yutopia/phone/contacts', ['id' => $ana->id])->assertCreated(); // twice: still one
    expect($ids($caio))->toBe([$ana->id]);
    // anyone can be added (a stranger too), and taken off again
    $this->actingAs($caio)->postJson('/api/yutopia/phone/contacts', ['id' => $stranger->id])->assertCreated();
    $this->actingAs($caio)->deleteJson("/api/yutopia/phone/contacts/{$stranger->id}")->assertOk();
    expect($ids($caio))->toBe([$ana->id]);
});

it('texts only people in your contacts (or someone already talking to you)', function () {
    [$ana, $bia, $caio, $stranger] = phoneTeam();
    // Caio has nobody: he can't start a thread
    $this->actingAs($caio)->postJson('/api/yutopia/phone/threads', ['members' => [$ana->id]])->assertStatus(422);
    // Ana texts Caio (he's in her contacts) — now Caio can answer without adding her
    $t = $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$caio->id]])->assertOk()->json('thread');
    $back = $this->actingAs($caio)->postJson('/api/yutopia/phone/threads', ['members' => [$ana->id]])->assertOk()->json('thread');
    expect($back['id'])->toBe($t['id']);
    $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$stranger->id]])->assertStatus(422);
});

it('texts someone: one direct thread, live to them, read receipts, unread counts', function () {
    [$ana, $bia, , $stranger] = phoneTeam();
    $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$stranger->id]])->assertStatus(422);
    $t = $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$bia->id]])->assertOk()->json('thread');
    $again = $this->actingAs($bia)->postJson('/api/yutopia/phone/threads', ['members' => [$ana->id]])->json('thread');
    expect($again['id'])->toBe($t['id'])->and($t['kind'])->toBe('direct');

    $m = $this->actingAs($ana)->postJson("/api/yutopia/phone/threads/{$t['id']}/messages", ['body' => 'board walls PR is up'])
        ->assertCreated()->json('message');
    Event::assertDispatched(YutopiaPhoneEvent::class, fn ($e) => $e->userId === $bia->id && $e->payload['type'] === 'message');
    Event::assertNotDispatched(YutopiaPhoneEvent::class, fn ($e) => $e->userId === $ana->id && $e->payload['type'] === 'message');
    Queue::assertPushed(NotifyYutopiaText::class);

    $mine = collect($this->actingAs($bia)->getJson('/api/yutopia/phone/threads')->json('threads'))->firstWhere('id', $t['id']);
    expect($mine['unread'])->toBe(1)->and($mine['last']['body'])->toBe('board walls PR is up');

    $this->actingAs($bia)->postJson("/api/yutopia/phone/threads/{$t['id']}/read", ['upto' => $m['id']])->assertOk();
    Event::assertDispatched(YutopiaPhoneEvent::class, fn ($e) => $e->userId === $ana->id && $e->payload['type'] === 'read');
    $mine = collect($this->actingAs($bia)->getJson('/api/yutopia/phone/threads')->json('threads'))->firstWhere('id', $t['id']);
    expect($mine['unread'])->toBe(0);

    // a stranger can't read or write it
    $this->actingAs($stranger)->getJson("/api/yutopia/phone/threads/{$t['id']}/messages")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/yutopia/phone/threads/{$t['id']}/messages", ['body' => 'hi'])->assertNotFound();
});

it('has a thread for the whole space, and earlier messages read as read for newcomers', function () {
    [$ana, $bia, , $stranger, $space] = phoneTeam();
    $threads = $this->actingAs($ana)->getJson("/api/yutopia/phone/threads?space={$space->id}")->json('threads');
    $st = collect($threads)->firstWhere('kind', 'space');
    expect($st['name'])->toBe('Studio');
    $this->actingAs($ana)->postJson("/api/yutopia/phone/threads/{$st['id']}/messages", ['body' => 'standup in 5!'])->assertCreated();
    Event::assertDispatched(YutopiaPhoneEvent::class, fn ($e) => $e->userId === $bia->id);
    // Bia arrives: it's in her list, unread
    $mine = collect($this->actingAs($bia)->getJson("/api/yutopia/phone/threads?space={$space->id}")->json('threads'))->firstWhere('kind', 'space');
    expect($mine['id'])->toBe($st['id']);
    $this->actingAs($stranger)->getJson("/api/yutopia/phone/threads/{$st['id']}/messages")->assertNotFound();
});

it('reacts with the drawn emotes, toggling', function () {
    [$ana, $bia] = phoneTeam();
    $t = $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$bia->id]])->json('thread');
    $m = $this->actingAs($ana)->postJson("/api/yutopia/phone/threads/{$t['id']}/messages", ['body' => 'hi'])->json('message');
    $this->actingAs($bia)->postJson("/api/yutopia/phone/messages/{$m['id']}/react", ['e' => 'heart'])->assertJsonPath('reactions.heart', [$bia->id]);
    $this->actingAs($bia)->postJson("/api/yutopia/phone/messages/{$m['id']}/react", ['e' => 'heart'])->assertJsonPath('reactions', []);
    $this->actingAs($bia)->postJson("/api/yutopia/phone/messages/{$m['id']}/react", ['e' => 'poop'])->assertStatus(422);
});

it('makes groups and logs calls from the world-server', function () {
    [$ana, $bia, $caio] = phoneTeam();
    $g = $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$bia->id, $caio->id], 'name' => 'Board walls crew'])->json('thread');
    expect($g['kind'])->toBe('group')->and($g['members'])->toHaveCount(2);

    signedPhone($this, '/api/internal/yutopia/phone/calls', ['from' => $ana->id, 'to' => [$bia->id], 'end' => 'missed', 'secs' => 0, 'video' => false, 'answered' => []])->assertOk();
    $this->call('POST', '/api/internal/yutopia/phone/calls', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);
    $calls = $this->actingAs($bia)->getJson('/api/yutopia/phone/calls')->assertOk()->json('calls');
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message']['meta']['end'])->toBe('missed')
        ->and($calls[0]['peers'])->toBe([$ana->id]);
    // the missed call is unread for Bia, not for Ana
    $t = collect($this->actingAs($bia)->getJson('/api/yutopia/phone/threads')->json('threads'))->firstWhere('kind', 'direct');
    expect($t['unread'])->toBe(1);
});

it('keeps photos: upload, list, a signed file, quota, delete removes the file', function () {
    Storage::fake('local');
    [$ana, $bia] = phoneTeam();
    $file = UploadedFile::fake()->image('photo.jpg', 1200, 800);
    $video = UploadedFile::fake()->create('clip.webm', 600, 'video/webm');
    $p = $this->actingAs($ana)->post('/api/yutopia/phone/photos', ['file' => $file, 'filter' => 'film', 'selfie' => '1', 'where' => 'Open office'], ['Accept' => 'application/json'])
        ->assertCreated()->json('photo');
    expect($p['w'])->toBe(1200)->and($p['selfie'])->toBeTrue()->and($p['url'])->toStartWith('/api/yutopia/photos/');
    $this->get($p['url'])->assertOk();
    $this->get('/api/yutopia/photos/'.$p['id'])->assertForbidden();
    expect($this->actingAs($bia)->getJson('/api/yutopia/phone/photos')->json('photos'))->toBe([]);

    // sending it in a thread
    $t = $this->actingAs($ana)->postJson('/api/yutopia/phone/threads', ['members' => [$bia->id]])->json('thread');
    $this->actingAs($ana)->postJson("/api/yutopia/phone/threads/{$t['id']}/messages", ['photo_id' => $p['id']])->assertCreated()->assertJsonPath('message.kind', 'photo');
    // someone else's photo can't be sent
    $this->actingAs($bia)->postJson("/api/yutopia/phone/threads/{$t['id']}/messages", ['photo_id' => $p['id']])->assertStatus(422);

    // Bia keeps it: a copy in her own gallery (not anyone else's)
    $kept = $this->actingAs($bia)->postJson("/api/yutopia/phone/photos/{$p['id']}/keep")->assertCreated()->json('photo');
    expect($kept['id'])->not->toBe($p['id'])->and($kept['user_id'])->toBe($bia->id);
    expect($this->actingAs($bia)->getJson('/api/yutopia/phone/photos')->json('photos'))->toHaveCount(1);
    $caioPhoto = $this->actingAs($ana)->post('/api/yutopia/phone/photos', ['file' => UploadedFile::fake()->image('b.jpg', 400, 300), 'filter' => 'none', 'selfie' => '0'], ['Accept' => 'application/json'])->json('photo');
    $this->actingAs($bia)->postJson("/api/yutopia/phone/photos/{$caioPhoto['id']}/keep")->assertNotFound(); // never sent to her

    // a video
    $v = $this->actingAs($ana)->post('/api/yutopia/phone/photos', ['file' => $video, 'kind' => 'video', 'filter' => 'film', 'selfie' => '0', 'w' => 1280, 'h' => 720, 'secs' => 12], ['Accept' => 'application/json'])
        ->assertCreated()->json('photo');
    expect($v['kind'])->toBe('video')->and($v['secs'])->toBe(12);
    $this->get($v['url'])->assertOk()->assertHeader('Content-Type', 'video/webm');

    $path = YutopiaPhoto::find($p['id'])->path;
    Storage::disk('local')->assertExists($path);
    $this->actingAs($bia)->deleteJson("/api/yutopia/phone/photos/{$p['id']}")->assertNotFound();
    $this->actingAs($ana)->deleteJson("/api/yutopia/phone/photos/{$p['id']}")->assertOk();
    Storage::disk('local')->assertMissing($path);
});

it('texts Vortex: he answers in character, and keeps what he says in the world', function () {
    [$ana] = phoneTeam();
    $r = $this->actingAs($ana)->postJson('/api/yutopia/phone/vortex', ['text' => 'who are you?'])->assertOk();
    expect($r->json('message.user_id'))->toBe($ana->id)
        ->and($r->json('reply.user_id'))->toBeNull()
        ->and(strlen($r->json('reply.body')))->toBeGreaterThan(3);
    $this->actingAs($ana)->postJson('/api/yutopia/phone/vortex/lines', ['text' => 'stop poking me.'])->assertOk();
    expect(YutopiaMessage::whereNull('user_id')->count())->toBe(2);
    $call = $this->actingAs($ana)->postJson('/api/yutopia/phone/vortex/call', ['said' => []])->assertOk();
    expect($call->json('line'))->toBeString()->and($call->json('hangup'))->toBeBool();
    $call = $this->actingAs($ana)->postJson('/api/yutopia/phone/vortex/call', ['said' => ['a', 'b']])->assertOk();
    expect($call->json('hangup'))->toBeTrue();
});
