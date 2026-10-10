<?php

use App\Events\ProjectEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaSpace;
use App\Services\Yutopia\YutopiaTokens;
use Illuminate\Support\Facades\Event;

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
        ->and($res->json('avatar.top'))->toBe('tee');
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
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);

    $res = $this->actingAs($bia)->postJson('/api/yutopia/session', ['space_id' => $space->id])->assertOk();

    expect(YutopiaTokens::decode($res->json('worldToken'), config('yutopia.world_secret'))['roles'])->toBe(['member']);
});

it('saves and validates avatars', function () {
    $ana = User::factory()->create();
    $layers = ['body' => 'regular', 'skin' => '#aa8866', 'hair' => 'curly', 'hairColor' => '#111111', 'top' => 'hoodie', 'topColor' => '#556b2f',
        'bottom' => 'skirt', 'bottomColor' => '#222222', 'accessory' => 'headphones', 'accessoryColor' => '#e8a33d'];

    $this->actingAs($ana)->putJson('/api/yutopia/avatar', ['skin' => 'red'] + $layers)->assertUnprocessable();
    $this->actingAs($ana)->putJson('/api/yutopia/avatar', $layers)->assertOk()->assertJsonPath('avatar.hair', 'curly');
    $this->actingAs($ana)->getJson('/api/yutopia/avatar')->assertJsonPath('avatar.accessory', 'headphones');
});

it('rejects unsigned internal calls', function () {
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));

    $this->getJson("/api/internal/yutopia/spaces/{$space->id}")->assertUnauthorized();
    $this->withHeaders(['X-Yutopia-Timestamp' => time(), 'X-Yutopia-Signature' => 'nope'])
        ->getJson("/api/internal/yutopia/spaces/{$space->id}")->assertUnauthorized();
});

it('seeds a space once and hands board walls the project boards', function () {
    $ana = User::factory()->create();
    $project = yutopiaProject($ana);
    $b1 = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'Dev', 'description' => '', 'position' => 0]);
    $b2 = Board::create(['user_id' => $ana->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'position' => 1]);
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);
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

it('moves and deletes objects from build mode', function () {
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));

    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'plant-1', 'kind' => 'plant', 'x' => 2, 'y' => 3])->assertOk();
    internalCall($this, 'PUT', "/api/internal/yutopia/spaces/{$space->id}/objects", ['id' => 'plant-1', 'kind' => 'plant', 'x' => 5, 'y' => 3, 'rot' => 2])
        ->assertJsonPath('object.x', 5)->assertJsonPath('object.rot', 2);
    internalCall($this, 'DELETE', "/api/internal/yutopia/spaces/{$space->id}/objects/plant-1")->assertOk();

    expect($space->objects()->count())->toBe(0);
});

it('assigns each person a stable free desk', function () {
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor(yutopiaProject(User::factory()->create()));
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $desks = ['desk-1', 'desk-2'];

    $a = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $ana->id, 'desks' => $desks])->json('desk');
    $b = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $bia->id, 'desks' => $desks])->json('desk');
    $again = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/desk", ['user_id' => $ana->id, 'desks' => $desks])->json('desk');

    expect([$a, $b, $again])->toBe(['desk-1', 'desk-2', 'desk-1']);
});

it('stores presence, broadcasts changes to the project and serves it to Yondra', function () {
    Event::fake([ProjectEvent::class]);
    $ana = User::factory()->create();
    $project = yutopiaProject($ana);
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);
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
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);

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
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor(yutopiaProject($ana));

    for ($i = 0; $i < 20; $i++) {
        $res = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/vortex", ['event' => 'ambient'])->assertOk();
        expect($res->json('personal'))->toBeFalse()
            ->and($res->json('line'))->toBe(mb_strtolower($res->json('line')))
            ->and($res->json('line'))->not->toContain('Ana');
    }

    $res = internalCall($this, 'POST', "/api/internal/yutopia/spaces/{$space->id}/vortex", ['event' => 'poke', 'user_id' => $ana->id])->assertOk();
    expect($res->json('personal'))->toBeTrue()->and($res->json('line'))->toBeString();

    // poking him in the studio is a fragment (once)
    $soul = app(\App\Services\Vortex\SoulService::class)->for($ana);
    expect(app(\App\Services\Vortex\FragmentService::class)->owned($soul->fresh()))->toContain('Y01');

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
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);
    $frags = app(\App\Services\Vortex\FragmentService::class);
    $souls = app(\App\Services\Vortex\SoulService::class);

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
});

it('stores a validated map layout and lists who can own rooms', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bia = User::factory()->create(['name' => 'Bia']);
    $project = yutopiaProject($ana);
    $project->members()->attach($bia->id, ['role' => 'member']);
    $space = app(\App\Services\Yutopia\YutopiaSpaces::class)->ensureFor($project);
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

    $this->actingAs($bia)->getJson("/api/yutopia/spaces/{$space->id}/members")
        ->assertOk()->assertJsonPath('members.0.name', 'Ana')->assertJsonPath('members.1.name', 'Bia');
    $this->actingAs(User::factory()->create())->getJson("/api/yutopia/spaces/{$space->id}/members")->assertNotFound();
});
