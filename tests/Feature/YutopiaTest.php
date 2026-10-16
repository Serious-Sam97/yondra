<?php

use App\Events\ProjectEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaDesk;
use App\Infrastructure\Models\YutopiaGame;
use App\Infrastructure\Models\YutopiaSpace;
use App\Infrastructure\Models\YutopiaSpawn;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SoulService;
use App\Services\Yutopia\YutopiaSpaces;
use App\Services\Yutopia\YutopiaTokens;
use App\Services\Yutopia\YutopiaVortex;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'yutopia.world_secret' => 'world-secret-for-tests-0123456789abcdef',
        'yutopia.internal_secret' => 'internal-secret-for-tests-0123456789abc',
        'yutopia.livekit.key' => 'APItest',
        'yutopia.livekit.secret' => 'livekit-secret-for-tests-0123456789abcd',
    ]);
});

function yutopiaProject(User $owner, string $name = 'Studio'): Project
{
    return Project::create(['owner_id' => $owner->id, 'name' => $name]);
}

function internalCall($test, string $method, string $uri, array $body = [])
{
    $raw = $method === 'GET' || $method === 'DELETE' ? '' : json_encode($body);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$raw, config('yutopia.internal_secret'));

    return $test->call($method, $uri, [], [], [], [
        'HTTP_X_YUTOPIA_TIMESTAMP' => (string) $ts,
        'HTTP_X_YUTOPIA_SIGNATURE' => $sig,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

it('lists a space for every project the user can open', function () {
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $mine = yutopiaProject($ana, 'Mine');
    $shared = yutopiaProject($bia, 'Shared');
    $shared->members()->attach($ana->id, ['role' => 'member']);
    yutopiaProject($bia, 'Not mine');

    $res = $this->actingAs($ana)->getJson('/api/yutopia/spaces')->assertOk();

    expect(collect($res->json('spaces'))->pluck('name')->all())->toBe(['Mine', 'Shared'])
        ->and($res->json('avatar.top'))->toBe('tee')
        ->and($res->json('spaces.0.projectName'))->toBe('Mine')
        ->and($res->json('spaces.0.theme'))->toBe('clouds')
        ->and($res->json('spaces.0.people'))->toBe([]);
});

it('mints world and livekit tokens only for members', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $stranger = User::factory()->create();
    $project = yutopiaProject($ana);
    $this->actingAs($ana)->getJson('/api/yutopia/spaces');
    $space = YutopiaSpace::where('project_id', $project->id)->first();

    $this->actingAs($stranger)->postJson('/api/yutopia/session', ['space_id' => $space->id])->assertNotFound();

    $res = $this->actingAs($ana)->postJson('/api/yutopia/session', ['space_id' => $space->id])->assertOk();
    $world = YutopiaTokens::decode($res->json('worldToken'), config('yutopia.world_secret'));
    $lk = YutopiaTokens::decode($res->json('livekitToken'), config('yutopia.livekit.secret'));

    expect($world['sub'])->toBe((string) $ana->id)
        ->and($world['space_id'])->toBe($space->id)
        ->and($world['roles'])->toContain('builder')
        ->and($lk['video']['room'])->toBe('space-'.$space->id)
        ->and($lk['video']['canSubscribe'])->toBeTrue()
        ->and($lk['iss'])->toBe('APItest');
});

it('gives plain members no build role', function () {
    $owner = User::factory()->create();
    $bia = User::factory()->create();
    $project = yutopiaProject($owner);
    $project->members()->attach($bia->id, ['role' => 'member']);
    $space = app(YutopiaSpaces::class)->ensureFor($project);

    $res = $this->actingAs($bia)->postJson('/api/yutopia/session', ['space_id' => $space->id])->assertOk();

    expect(YutopiaTokens::decode($res->json('worldToken'), config('yutopia.world_secret'))['roles'])->toBe(['member']);
});

it('saves and validates avatars', function () {
    $ana = User::factory()->create();
    $layers = ['body' => 'regular', 'skin' => '#aa8866', 'hair' => 'curly', 'hairColor' => '#111111', 'top' => 'hoodie', 'topColor' => '#556b2f',
        'bottom' => 'skirt', 'bottomColor' => '#222222', 'accessory' => 'headphones', 'accessoryColor' => '#e8a33d'];

    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['skin' => 'red'] + $layers)->assertUnprocessable();
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', $layers)->assertOk()->assertJsonPath('avatar.hair', 'curly');
    $this->actingAs($ana)->getJson('/api/yutopia/avatar')->assertJsonPath('avatar.accessory', 'headphones')
        ->assertJsonPath('avatar.style', 'citypop')->assertJsonPath('avatar.gender', 'neutral');
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['gender' => 'other'] + $layers)->assertUnprocessable();
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['gender' => 'feminine'] + $layers)->assertOk()->assertJsonPath('avatar.gender', 'feminine');
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['style' => 'pixel'] + $layers)->assertUnprocessable();
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['style' => 'ligne'] + $layers)->assertOk()->assertJsonPath('avatar.style', 'ligne');
    // glasses, one more thing and shoes: optional slots, defaulted when missing
    $this->actingAs($ana)->getJson('/api/yutopia/avatar')->assertJsonPath('avatar.shoes', 'sneakers')->assertJsonPath('avatar.eyewear', 'none');
    $more = ['eyewear' => 'sunglasses', 'eyewearColor' => '#2a2420', 'extra' => 'scarf', 'extraColor' => '#b5562f', 'shoes' => 'boots', 'shoesColor' => '#5b3a26'];
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', $more + $layers)->assertOk()
        ->assertJsonPath('avatar.extra', 'scarf')->assertJsonPath('avatar.shoes', 'boots')->assertJsonPath('avatar.eyewear', 'sunglasses');
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['shoesColor' => 'brown'] + $more + $layers)->assertUnprocessable();
});

it('rejects unsigned internal calls', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));

    $this->getJson("/api/internal/yutopia/spaces/{$space->id}")->assertUnauthorized();
    $this->withHeaders(['X-Yutopia-Timestamp' => time(), 'X-Yutopia-Signature' => 'nope'])
        ->getJson("/api/internal/yutopia/spaces/{$space->id}")->assertUnauthorized();
});

it('seeds a space once and hands board walls the project boards', function () {
    $ana = User::factory()->create();
    $project = yutopiaProject($ana);
    $b1 = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'Dev', 'description' => '', 'position' => 0]);
    $b2 = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'position' => 1]);
    $space = app(YutopiaSpaces::class)->ensureFor($project);
    $objects = [
        ['id' => 'wall-a', 'kind' => 'board_wall', 'x' => 3, 'y' => 1],
        ['id' => 'wall-b', 'kind' => 'board_wall', 'x' => 6, 'y' => 1],
        ['id' => 'desk-1', 'kind' => 'desk', 'x' => 4, 'y' => 5, 'rot' => 1, 'props' => ['label' => 'A']],
    ];

    $res = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/seed", ['objects' => $objects])->assertOk();
    expect(collect($res->json('objects'))->pluck('refId', 'id')->all())->toBe(['wall-a' => $b1->id, 'wall-b' => $b2->id, 'desk-1' => null]);

    // Seeding again doesn't duplicate or move anything.
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/seed", ['objects' => [['id' => 'desk-1', 'kind' => 'desk', 'x' => 9, 'y' => 9]]])->assertOk();
    $show = internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}")->assertOk();
    expect($show->json('seeded'))->toBeTrue()
        ->and($show->json('objects'))->toHaveCount(3)
        ->and(collect($show->json('objects'))->firstWhere('id', 'desk-1')['x'])->toBe(4);
});

it('keeps a TV\'s and a radio\'s media with their objects', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $media = ['item' => ['src' => 'yt', 'ref' => 'M7lc1UVf-VE', 'title' => 'A song', 'dur' => 212], 'at' => 1791915000000, 'paused' => false, 'pos' => 0, 'loop' => true, 'queue' => [], 'by' => 'Ana', 'lock' => false];

    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'tv-1', 'kind' => 'tv_hd', 'x' => 4, 'y' => 1, 'props' => ['media' => $media]])
        ->assertOk()->assertJsonPath('object.props.media.item.title', 'A song');
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'radio-1', 'kind' => 'radio', 'x' => 6, 'y' => 3, 'props' => ['radio' => ['source' => 'yt', 'playing' => true, 'media' => $media]]])
        ->assertOk()->assertJsonPath('object.props.radio.media.item.ref', 'M7lc1UVf-VE');
});

it('keeps the playlists played in a space', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $list = ['list' => 'PLfirst00001', 'title' => 'Lo-fi beats', 'channel' => 'Chill', 'items' => [['src' => 'yt', 'ref' => 'M7lc1UVf-VE', 'title' => 'A song', 'dur' => 212]], 'by' => 'Ana', 'usedAt' => 1791915000000, 'uses' => 1];

    expect(internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}")->json('playlists'))->toBe([]);
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/playlists", ['playlists' => [$list]])->assertOk();
    $show = internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}")->assertOk();
    expect($show->json('playlists.0.title'))->toBe('Lo-fi beats')
        ->and($show->json('playlists.0.items.0.ref'))->toBe('M7lc1UVf-VE');
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/playlists", ['playlists' => 'nope'])->assertUnprocessable();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/playlists", ['playlists' => []])->assertOk();
    expect(internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}")->json('playlists'))->toBe([]);
});

it('moves and deletes objects from build mode', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));

    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'plant-1', 'kind' => 'plant', 'x' => 2, 'y' => 3])->assertOk();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'plant-1', 'kind' => 'plant', 'x' => 5, 'y' => 3, 'rot' => 2])
        ->assertJsonPath('object.x', 5)->assertJsonPath('object.rot', 2);
    internalCall($this, 'DELETE', "/api/internal/yutopia/spaces/{$space->id}/objects/plant-1")->assertOk();

    expect($space->objects()->count())->toBe(0);
});

it('assigns each person a stable free desk', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $desks = ['desk-1', 'desk-2'];

    $a = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $ana->id, 'desks' => $desks])->json('desk');
    $b = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $bia->id, 'desks' => $desks])->json('desk');
    $again = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $ana->id, 'desks' => $desks])->json('desk');

    expect([$a, $b, $again])->toBe(['desk-1', 'desk-2', 'desk-1']);
});

it('looks a desk up without handing one out when asked not to assign', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $desks = ['desk-1', 'desk-2'];
    $url = "/api/internal/yutopia/spaces/{$space->id}/desk";

    internalCall($this, 'POST', $url, ['user_id' => $ana->id, 'desks' => $desks])->assertOk();
    // Ana kept hers; Bia has none and doesn't get one
    expect(internalCall($this, 'POST', $url, ['user_id' => $ana->id, 'desks' => $desks, 'assign' => false])->json('desk'))->toBe('desk-1')
        ->and(internalCall($this, 'POST', $url, ['user_id' => $bia->id, 'desks' => $desks, 'assign' => false])->json('desk'))->toBeNull()
        ->and(YutopiaDesk::where('user_id', $bia->id)->exists())->toBeFalse();
});

it('keeps each person\'s own arrival point per space, and forgets it on reset', function () {
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $url = fn (User $u) => "/api/internal/yutopia/spaces/{$space->id}/spawn/{$u->id}";

    expect(internalCall($this, 'GET', $url($ana))->assertOk()->json('spawn'))->toBeNull();
    internalCall($this, 'PUT', $url($ana), ['spawn' => ['x' => 12.5, 'y' => 7.5]])->assertOk();
    internalCall($this, 'PUT', $url($ana), ['spawn' => ['x' => 14.5, 'y' => 8.5]])->assertOk();

    expect(internalCall($this, 'GET', $url($ana))->json('spawn'))->toEqual(['x' => 14.5, 'y' => 8.5])
        ->and(internalCall($this, 'GET', $url($bia))->json('spawn'))->toBeNull()
        ->and(YutopiaSpawn::count())->toBe(1);

    internalCall($this, 'PUT', $url($ana), ['spawn' => ['x' => 500, 'y' => 1]])->assertStatus(422);
    internalCall($this, 'PUT', $url($ana), ['spawn' => null])->assertOk();
    expect(internalCall($this, 'GET', $url($ana))->json('spawn'))->toBeNull();
});

it('stores presence, broadcasts changes to the project and serves it to Yondra', function () {
    Event::fake([ProjectEvent::class]);
    $ana = User::factory()->create();
    $project = yutopiaProject($ana);
    $space = app(YutopiaSpaces::class)->ensureFor($project);
    $people = ['people' => [['user_id' => $ana->id, 'name' => 'Ana', 'area' => 'war-room', 'area_name' => 'War Room', 'status' => 'online']]];

    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/presence", $people)->assertOk();
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/presence", $people)->assertOk();

    Event::assertDispatchedTimes(ProjectEvent::class, 1);
    $this->actingAs($ana)->getJson("/api/yutopia/projects/{$project->id}/presence")
        ->assertOk()->assertJsonPath('people.0.areaName', 'War Room');
    $this->actingAs(User::factory()->create())->getJson("/api/yutopia/projects/{$project->id}/presence")->assertNotFound();

    $board = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'B', 'description' => '']);
    $this->actingAs($ana)->getJson("/api/yutopia/boards/{$board->id}/presence")
        ->assertOk()->assertJsonPath('spaceId', $space->id)->assertJsonPath('people.0.name', 'Ana');
});

it('reports what each member is doing from their open cards', function () {
    $ana = User::factory()->create();
    $project = yutopiaProject($ana);
    $board = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'Dev', 'description' => '', 'ticket_prefix' => 'DEV']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To do', 'order' => 0]);
    $doing = Section::create(['board_id' => $board->id, 'name' => 'Doing', 'order' => 1]);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 2]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Later', 'description' => '', 'assigned_user_id' => $ana->id, 'position' => 0]);
    Card::create(['board_id' => $board->id, 'section_id' => $doing->id, 'name' => 'Now', 'description' => '', 'assigned_user_id' => $ana->id, 'position' => 0, 'ticket_number' => 7]);
    Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'name' => 'Shipped', 'description' => '', 'assigned_user_id' => $ana->id, 'position' => 0]);
    $space = app(YutopiaSpaces::class)->ensureFor($project);

    $res = $this->actingAs($ana)->getJson("/api/yutopia/spaces/{$space->id}/status")->assertOk();

    expect($res->json('status.0.userId'))->toBe($ana->id)
        ->and(collect($res->json('status.0.doing'))->pluck('name')->all())->toBe(['Now'])
        ->and($res->json('status.0.doing.0.ticket'))->toBe('DEV-7');

    $boards = $this->actingAs($ana)->getJson("/api/yutopia/spaces/{$space->id}/boards")->assertOk();
    expect(collect($boards->json('boards.0.sections'))->pluck('count', 'name')->all())->toBe(['To do' => 1, 'Doing' => 1, 'Done' => 1]);
});

it('hands off a Yondra login with a one-time code', function () {
    $ana = User::factory()->create();
    $code = $this->actingAs($ana)->postJson('/api/yutopia/handoff')->assertOk()->json('code');
    app('auth')->forgetGuards();

    $this->postJson('/api/yutopia/handoff/exchange', ['code' => $code])->assertOk()->assertJsonPath('user.id', $ana->id);
    $this->postJson('/api/yutopia/handoff/exchange', ['code' => $code])->assertUnauthorized();
});

it('gives Vortex public lines that name nobody and personal lines from the soul', function () {
    $ana = User::factory()->create(['name' => 'Ana Lima']);
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject($ana));

    for ($i = 0; $i < 20; $i++) {
        $res = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/vortex", ['event' => 'ambient'])->assertOk();
        expect($res->json('personal'))->toBeFalse()
            ->and($res->json('line'))->toBe(mb_strtolower($res->json('line')))
            ->and($res->json('line'))->not->toContain('Ana');
    }

    $res = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/vortex", ['event' => 'poke', 'user_id' => $ana->id])->assertOk();
    expect($res->json('personal'))->toBeTrue()->and($res->json('line'))->toBeString();

    // poking him in the studio is a fragment (once)
    $soul = app(SoulService::class)->for($ana);
    expect(app(FragmentService::class)->owned($soul->fresh()))->toContain('Y01');

    // strangers get nothing
    $stranger = User::factory()->create();
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/vortex", ['event' => 'poke', 'user_id' => $stranger->id])
        ->assertOk()->assertJsonPath('line', null);
});

it('only grants world fragments on the allowlist, with their prerequisites', function () {
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $project = yutopiaProject($ana);
    $project->members()->attach($bia->id, ['role' => 'member']);
    $space = app(YutopiaSpaces::class)->ensureFor($project);
    $frags = app(FragmentService::class);
    $souls = app(SoulService::class);

    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/fragment", ['fragment' => 'F64', 'user_ids' => [$ana->id]])
        ->assertJsonPath('granted', []);
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/fragment", ['fragment' => 'Y03', 'user_ids' => [$ana->id, $bia->id]])
        ->assertJsonPath('granted', []); // Y01 + Y02 first

    foreach ([$ana, $bia] as $u) {
        $frags->grant($souls->for($u), 'Y01');
    }
    // the name comes from Yondra's lore first
    expect($frags->claim($ana, $souls->for($ana)->fresh(), 'Y02', 'last take')['ok'])->toBeFalse();
    $frags->grant($souls->for($ana)->fresh(), 'F33');
    expect($frags->claim($ana, $souls->for($ana)->fresh(), 'Y02', 'Last Take')['ok'])->toBeTrue();

    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/fragment", ['fragment' => 'Y03', 'user_ids' => [$ana->id, $bia->id]])
        ->assertJsonPath('granted', [$ana->id]);

    // Y04: signing in to VortexOS on a desk computer (after Y01), once
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/fragment", ['fragment' => 'Y04', 'user_ids' => [$bia->id]])
        ->assertJsonPath('granted', [$bia->id]);
    internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/fragment", ['fragment' => 'Y04', 'user_ids' => [$bia->id]])
        ->assertJsonPath('granted', []);
});

it('lets Vortex react, in public, to VortexOS booting near him', function () {
    $ana = User::factory()->create();
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject($ana));
    $line = app(YutopiaVortex::class)->line($space, 'vortexos', $ana->id);
    expect($line['personal'])->toBeFalse()->and($line['line'])->toBeString()->not->toBeEmpty();
});

it('stores a validated map layout and lists who can own rooms', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bia = User::factory()->create(['name' => 'Bia']);
    $project = yutopiaProject($ana);
    $project->members()->attach($bia->id, ['role' => 'member']);
    $space = app(YutopiaSpaces::class)->ensureFor($project);
    $row = str_repeat('.', 16);
    $layout = [
        'width' => 16, 'height' => 16,
        'tiles' => array_fill(0, 16, $row),
        'spawn' => ['x' => 8.5, 'y' => 8.5],
        'areas' => [['key' => 'room-ana', 'name' => "Ana's office", 'kind' => 'private', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 4, 'ownerId' => $ana->id, 'ownerName' => 'Ana']],
    ];

    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", $layout)->assertOk();
    expect($space->fresh()->layout['areas'][0]['ownerId'])->toBe($ana->id);
    internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}")->assertJsonPath('layout.width', 16);

    // bad shapes are refused
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['width' => 200] + $layout)->assertUnprocessable();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['tiles' => array_fill(0, 16, str_repeat('Z', 16))] + $layout)->assertUnprocessable();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['tiles' => array_fill(0, 15, $row)] + $layout)->assertStatus(422);
    $this->putJson("/api/internal/yutopia/spaces/{$space->id}/layout", $layout)->assertUnauthorized();

    // map v2: walls on tile edges
    $v2 = ['version' => 2, 'hWalls' => array_fill(0, 17, str_repeat('p', 16)), 'vWalls' => array_fill(0, 16, 'p'.str_repeat('.', 15).'W')] + $layout;
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", $v2)->assertOk();
    expect($space->fresh()->layout['hWalls'])->toHaveCount(17)
        ->and($space->fresh()->layout['vWalls'][0])->toBe('p'.str_repeat('.', 15).'W');
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['hWalls' => array_fill(0, 16, str_repeat('p', 16))] + $v2)->assertStatus(422);
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['vWalls' => array_fill(0, 16, str_repeat('Z', 17))] + $v2)->assertUnprocessable();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['tiles' => array_fill(0, 16, '#'.str_repeat('.', 15))] + $v2)->assertStatus(422);
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['hWalls' => null] + $v2)->assertUnprocessable();
    // two-faced walls: the other face's material where it differs, kept
    $faces = ['hBacks' => array_fill(0, 17, 'b'.str_repeat('.', 15)), 'vBacks' => array_fill(0, 16, str_repeat('.', 16).'k')] + $v2;
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", $faces)->assertOk();
    expect($space->fresh()->layout['hBacks'][0])->toBe('b'.str_repeat('.', 15))
        ->and($space->fresh()->layout['vBacks'][3])->toBe(str_repeat('.', 16).'k');
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['hBacks' => array_fill(0, 17, 'g'.str_repeat('.', 15))] + $faces)->assertUnprocessable();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['hBacks' => array_fill(0, 16, str_repeat('.', 16))] + $faces)->assertStatus(422);
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['vBacks' => array_fill(0, 16, '.')] + $faces)->assertStatus(422);
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['vBacks' => null] + $faces)->assertUnprocessable();
    // the theme around the studio
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['theme' => 'snow'] + $v2)->assertOk();
    expect($space->fresh()->layout['theme'])->toBe('snow');
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", ['theme' => 'lava'] + $v2)->assertUnprocessable();

    $this->actingAs($bia)->getJson("/api/yutopia/spaces/{$space->id}/members")
        ->assertOk()->assertJsonPath('members.0.name', 'Ana')->assertJsonPath('members.1.name', 'Bia');
    $this->actingAs(User::factory()->create())->getJson("/api/yutopia/spaces/{$space->id}/members")->assertNotFound();
});

it('keeps my build setups: save, list, delete — mine only, checked', function () {
    $ana = User::factory()->create();
    $bea = User::factory()->create();
    $desk = ['name' => 'My desk', 'items' => [
        ['kind' => 'desk', 'dx' => 0, 'dy' => 0, 'rot' => 0, 'props' => ['variant' => 2]],
        ['kind' => 'tabletop', 'dx' => 0, 'dy' => 0, 'rot' => 0, 'props' => ['d' => 'monitor', 'c' => [1, 1]]],
        ['kind' => 'chair', 'dx' => 0, 'dy' => 1, 'rot' => 2],
    ]];
    $id = $this->actingAs($ana)->postJson('/api/yutopia/setups', $desk)->assertCreated()
        ->assertJsonPath('setup.name', 'My desk')->json('setup.id');
    $this->actingAs($ana)->getJson('/api/yutopia/setups')->assertOk()
        ->assertJsonCount(1, 'setups')->assertJsonPath('setups.0.items.1.props.d', 'monitor')
        ->assertJsonPath('setups.0.items.2.props', null);
    // someone else's are their own
    $this->actingAs($bea)->getJson('/api/yutopia/setups')->assertJsonCount(0, 'setups');
    $this->actingAs($bea)->deleteJson("/api/yutopia/setups/{$id}")->assertNotFound();
    // checked: kinds, offsets, turns, size
    $this->actingAs($ana)->postJson('/api/yutopia/setups', ['name' => 'x', 'items' => []])->assertUnprocessable();
    $this->actingAs($ana)->postJson('/api/yutopia/setups', ['name' => 'x', 'items' => [['kind' => 'desk', 'dx' => -1, 'dy' => 0, 'rot' => 0]]])->assertUnprocessable();
    $this->actingAs($ana)->postJson('/api/yutopia/setups', ['name' => 'x', 'items' => [['kind' => 'desk', 'dx' => 0, 'dy' => 0, 'rot' => 4]]])->assertUnprocessable();
    $this->actingAs($ana)->postJson('/api/yutopia/setups', ['name' => 'x', 'items' => [['kind' => '<b>', 'dx' => 0, 'dy' => 0, 'rot' => 0]]])->assertUnprocessable();
    $this->actingAs($ana)->deleteJson("/api/yutopia/setups/{$id}")->assertOk();
    $this->actingAs($ana)->getJson('/api/yutopia/setups')->assertJsonCount(0, 'setups');
});

it('keeps a team\'s cartridges: upload, list, play, take away — members only, checked', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $bia = User::factory()->create();
    $stranger = User::factory()->create();
    $project = yutopiaProject($owner);
    $project->members()->attach($bia->id, ['role' => 'member']);
    $space = app(YutopiaSpaces::class)->ensureFor($project);
    $url = "/api/yutopia/spaces/{$space->id}/games";
    $rom = fn (string $name, int $kb) => UploadedFile::fake()->createWithContent($name, str_repeat("\x4e", $kb * 1024));

    $res = $this->actingAs($bia)->post($url, ['file' => $rom('pong.nes', 24), 'system' => 'nes', 'title' => 'Pong', 'color' => '#C4402B', 'players' => 2])
        ->assertCreated();
    $id = (int) substr($res->json('game.id'), 2);
    expect($res->json('game'))->toMatchArray(['system' => 'nes', 'title' => 'Pong', 'color' => '#c4402b', 'players' => 2, 'builtIn' => false, 'mine' => true]);

    // wrong console, too big, the same one twice, strangers
    $this->actingAs($bia)->post($url, ['file' => $rom('pong.sfc', 24), 'system' => 'nes', 'title' => 'X', 'color' => '#000000'])->assertStatus(422);
    $this->actingAs($bia)->post($url, ['file' => $rom('big.a26', 65), 'system' => '2600', 'title' => 'X', 'color' => '#000000'])->assertStatus(422);
    $this->actingAs($bia)->post($url, ['file' => $rom('again.nes', 24), 'system' => 'nes', 'title' => 'Again', 'color' => '#000000'])->assertStatus(422);
    $this->actingAs($stranger)->getJson($url)->assertNotFound();
    $this->actingAs($stranger)->get("{$url}/{$id}/rom")->assertNotFound();

    // everyone in the space sees it and can play it
    expect($this->actingAs($owner)->getJson($url)->json('games.0'))->toMatchArray(['id' => "u:{$id}", 'mine' => false]);
    $this->actingAs($owner)->get("{$url}/{$id}/rom")->assertOk();

    // the world-server can check it
    internalCall($this, 'GET', "/api/internal/yutopia/spaces/{$space->id}/games/{$id}")
        ->assertOk()->assertJsonPath('game.title', 'Pong');

    // a member who didn't bring it can't take it away; the owner can
    $other = User::factory()->create();
    $project->members()->attach($other->id, ['role' => 'member']);
    $this->actingAs($other)->deleteJson("{$url}/{$id}")->assertForbidden();
    $this->actingAs($owner)->deleteJson("{$url}/{$id}")->assertOk();
    expect(YutopiaGame::count())->toBe(0);
});

it('keeps where a game left off in a space: a save state and a battery save', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject($owner));
    $url = "/api/yutopia/spaces/{$space->id}/saves";
    $blob = fn (string $name, string $data) => UploadedFile::fake()->createWithContent($name, $data);

    expect($this->actingAs($owner)->getJson("{$url}/b:ucity")->json())->toMatchArray(['state' => false, 'sram' => false]);
    $this->actingAs($owner)->post("{$url}/b:ucity", ['state' => $blob('state.bin', 'STATE-1')])->assertOk();
    $this->actingAs($owner)->post("{$url}/b:ucity", ['sram' => $blob('sram.bin', 'SRAM')])->assertOk();
    expect($this->actingAs($owner)->getJson("{$url}/b:ucity")->json())->toMatchArray(['state' => true, 'sram' => true]);
    expect($this->actingAs($owner)->get("{$url}/b:ucity/state")->streamedContent())->toBe('STATE-1');
    expect($this->actingAs($owner)->get("{$url}/b:ucity/sram")->streamedContent())->toBe('SRAM');
    // odd keys and other spaces' uploads aren't save buckets
    $this->actingAs($owner)->getJson("{$url}/../etc")->assertNotFound();
    $this->actingAs($owner)->post("{$url}/u:999", ['state' => $blob('state.bin', 'X')])->assertNotFound();
    $this->actingAs($owner)->post("{$url}/b:ucity", [])->assertStatus(422);
});

it('keeps the empty space a resize adds (rows ending in void, all-void rows)', function () {
    $ana = User::factory()->create();
    $space = app(YutopiaSpaces::class)->ensureFor(yutopiaProject($ana));
    // grown from 12×12 to 16×16: new columns and rows are void (" ")
    $tiles = array_merge(array_fill(0, 12, str_repeat('.', 12).'    '), array_fill(0, 4, str_repeat(' ', 16)));
    $layout = ['version' => 2, 'width' => 16, 'height' => 16, 'tiles' => $tiles,
        'hWalls' => array_fill(0, 17, str_repeat('.', 16)), 'vWalls' => array_fill(0, 16, str_repeat('.', 17)),
        'spawn' => ['x' => 2.5, 'y' => 2.5], 'areas' => []];
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/layout", $layout)->assertOk();
    expect($space->fresh()->layout['tiles'])->toBe($tiles)->and($space->fresh()->layout['width'])->toBe(16);
});

it('keeps someone\'s pets for the world-server: create, read, update, delete, at most three', function () {
    $ana = User::factory()->create();
    $url = "/api/internal/yutopia/pets/{$ana->id}";
    expect(internalCall($this, 'GET', $url)->assertOk()->json('pets'))->toBe([]);
    $rec = fn (string $name) => [
        'species' => 'platypus', 'name' => $name, 'look' => ['color' => '#5b3a26', 'mark' => 'socks', 'markColor' => '#efe4cc'],
        'outfit' => ['hat' => 'none', 'hatColor' => '#c4402b', 'collar' => 'none', 'collarColor' => '#c4402b', 'coat' => 'none', 'coatColor' => '#6e7b3a'],
        'needs' => ['food' => 100, 'water' => 100, 'energy' => 100, 'fun' => 100, 'health' => 100],
        'xp' => 0, 'tricks' => [], 'alive' => true, 'wins' => 0, 'losses' => 0, 'bornAt' => 1760000000000, 'diedAt' => null, 'seenAt' => 1760000000000,
    ];
    $created = internalCall($this, 'POST', $url, $rec('Perry'))->assertCreated()->json('pet');
    expect($created['name'])->toBe('Perry')->and($created['seenAt'])->toBe(1760000000000)->and($created['tricks'])->toBe([]);
    expect($created['traits'])->toBeNull()->and($created['bonds'])->toBe([]);
    internalCall($this, 'PUT', "$url/{$created['id']}", ['xp' => 120, 'tricks' => ['sit' => 100], 'needs' => ['food' => 40, 'water' => 50, 'energy' => 60, 'fun' => 70, 'health' => 90], 'seenAt' => 1760000100000,
        'traits' => ['energy' => 0.7, 'independence' => 0.2, 'courage' => 0.5, 'greed' => 0.6, 'curiosity' => 0.5, 'affection' => 0.8, 'obedience' => 0.9],
        'memory' => ['toys' => ['ball' => 3], 'spots' => [], 'bowls' => [], 'grudge' => []], 'bonds' => [(string) $ana->id => 42.5]])->assertOk();
    $pets = internalCall($this, 'GET', $url)->json('pets');
    expect($pets)->toHaveCount(1)->and($pets[0]['xp'])->toBe(120)->and($pets[0]['tricks'])->toBe(['sit' => 100])->and($pets[0]['needs']['food'])->toBe(40)
        ->and($pets[0]['traits']['obedience'])->toBe(0.9)->and($pets[0]['memory']['toys'])->toBe(['ball' => 3])->and($pets[0]['bonds'][(string) $ana->id])->toBe(42.5);
    // a trait out of range is refused
    internalCall($this, 'PUT', "$url/{$created['id']}", ['traits' => ['energy' => 3]])->assertStatus(422);
    internalCall($this, 'POST', $url, $rec('Two'))->assertCreated();
    internalCall($this, 'POST', $url, $rec('Three'))->assertCreated();
    internalCall($this, 'POST', $url, $rec('Four'))->assertStatus(422);
    // a bad species is refused, someone else's pet can't be touched
    internalCall($this, 'POST', '/api/internal/yutopia/pets/'.User::factory()->create()->id, ['species' => 'dragon'] + $rec('X'))->assertStatus(422);
    internalCall($this, 'PUT', '/api/internal/yutopia/pets/'.User::factory()->create()->id."/{$created['id']}", ['xp' => 1])->assertNotFound();
    internalCall($this, 'DELETE', "$url/{$created['id']}")->assertOk();
    expect(internalCall($this, 'GET', $url)->json('pets'))->toHaveCount(2);
    // unsigned: no
    $this->getJson($url)->assertStatus(401);
});
