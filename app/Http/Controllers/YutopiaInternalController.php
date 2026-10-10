<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\YutopiaObject;
use App\Infrastructure\Models\YutopiaSpace;
use App\Services\Yutopia\YutopiaPresence;
use App\Services\Yutopia\YutopiaSpaces;
use App\Services\Yutopia\YutopiaVortex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// World-server → Laravel. Every request is signed with HMAC-SHA256 over
// "{timestamp}.{raw body}" using YUTOPIA_INTERNAL_SECRET (see verify()).
class YutopiaInternalController extends Controller
{
    public function __construct(
        private readonly YutopiaSpaces $spaces,
        private readonly YutopiaPresence $presence,
    ) {}

    public function space(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json([
            'id' => $space->id,
            'name' => $space->name,
            'mapKey' => $space->map_key,
            'projectId' => $space->project_id,
            'seeded' => $space->seeded_at !== null,
            'layout' => $space->layout,
            'objects' => $space->objects()->orderBy('id')->get()->map->toWorld(),
        ]);
    }

    public function seed(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate($this->objectRules('objects.*.'));
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json(['objects' => $this->spaces->seed($space, $data['objects'])->map->toWorld()]);
    }

    public function upsertObject(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $o = $request->validate($this->objectRules(''));
        $space = YutopiaSpace::findOrFail($spaceId);
        $row = YutopiaObject::updateOrCreate(
            ['space_id' => $space->id, 'uid' => $o['id']],
            ['kind' => $o['kind'], 'x' => $o['x'], 'y' => $o['y'], 'rot' => $o['rot'] ?? 0, 'ref_id' => $o['refId'] ?? null, 'props' => $o['props'] ?? null],
        );

        return response()->json(['object' => $row->toWorld()]);
    }

    public function deleteObject(Request $request, int $spaceId, string $uid): JsonResponse
    {
        $this->verify($request);
        YutopiaObject::where('space_id', $spaceId)->where('uid', $uid)->delete();

        return response()->json(['ok' => true]);
    }

    public function desk(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'desks' => ['present', 'array'], 'desks.*' => ['string', 'max:64']]);
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json(['desk' => $this->spaces->deskFor($space, (int) $data['user_id'], $data['desks'])]);
    }

    public function presence(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate([
            'people' => ['present', 'array', 'max:500'],
            'people.*.user_id' => ['required', 'integer'],
            'people.*.name' => ['nullable', 'string', 'max:120'],
            'people.*.area' => ['nullable', 'string', 'max:64'],
            'people.*.area_name' => ['nullable', 'string', 'max:120'],
            'people.*.status' => ['nullable', 'string', 'max:16'],
        ]);
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json($this->presence->put($space, $data['people']));
    }

    // Builders edited the map (walls, floors, rooms, size). The world-server has
    // already applied the shared rules; this is the storage-side shape check.
    public function layout(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate([
            'width' => ['required', 'integer', 'between:16,96'],
            'height' => ['required', 'integer', 'between:16,96'],
            'tiles' => ['required', 'array'],
            'tiles.*' => ['string', 'regex:/^[#\\ .,_:~x]*$/'],
            'spawn' => ['required', 'array'],
            'spawn.x' => ['required', 'numeric', 'min:0'],
            'spawn.y' => ['required', 'numeric', 'min:0'],
            'areas' => ['present', 'array', 'max:200'],
            'areas.*.key' => ['required', 'string', 'max:64'],
            'areas.*.name' => ['required', 'string', 'max:60'],
            'areas.*.kind' => ['required', 'string', 'in:open,private,booth,stage,standup'],
            'areas.*.x' => ['required', 'integer', 'min:0'],
            'areas.*.y' => ['required', 'integer', 'min:0'],
            'areas.*.w' => ['required', 'integer', 'min:1'],
            'areas.*.h' => ['required', 'integer', 'min:1'],
            'areas.*.priority' => ['nullable', 'integer'],
            'areas.*.ownerId' => ['nullable', 'integer'],
            'areas.*.ownerName' => ['nullable', 'string', 'max:120'],
        ]);
        abort_unless(count($data['tiles']) === $data['height'], 422, 'tiles must have one row per height');
        foreach ($data['tiles'] as $row) {
            abort_unless(strlen($row) === $data['width'], 422, 'every row must be width long');
        }
        $space = YutopiaSpace::findOrFail($spaceId);
        $space->update(['layout' => $data]);

        return response()->json(['ok' => true]);
    }

    public function vortex(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate([
            'event' => ['required', 'string', 'in:ambient,radio,night,standup,sleep,greet,poke'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json(app(YutopiaVortex::class)->line($space, $data['event'], $data['user_id'] ?? null));
    }

    public function fragment(Request $request, int $spaceId): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate([
            'fragment' => ['required', 'string', 'max:8'],
            'user_ids' => ['required', 'array', 'max:50'],
            'user_ids.*' => ['integer'],
        ]);
        $space = YutopiaSpace::findOrFail($spaceId);

        return response()->json(['granted' => app(YutopiaVortex::class)->grantWorldFragment($space, $data['fragment'], $data['user_ids'])]);
    }

    private function objectRules(string $p): array
    {
        $rules = [
            $p.'id' => ['required', 'string', 'max:64'],
            $p.'kind' => ['required', 'string', 'max:40'],
            $p.'x' => ['required', 'integer', 'between:0,1000'],
            $p.'y' => ['required', 'integer', 'between:0,1000'],
            $p.'rot' => ['nullable', 'integer', 'between:0,3'],
            $p.'refId' => ['nullable', 'integer'],
            $p.'props' => ['nullable', 'array'],
        ];

        return $p === '' ? $rules : ['objects' => ['present', 'array', 'max:2000']] + $rules;
    }

    private function verify(Request $request): void
    {
        $secret = (string) config('yutopia.internal_secret');
        $ts = (int) $request->header('X-Yutopia-Timestamp');
        $sig = (string) $request->header('X-Yutopia-Signature');
        abort_if($secret === '' || abs(time() - $ts) > 300, 401);
        $expected = hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $sig), 401);
    }
}
