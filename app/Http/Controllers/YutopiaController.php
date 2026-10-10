<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaAvatar;
use App\Infrastructure\Models\YutopiaSpace;
use App\Services\Yutopia\YutopiaPresence;
use App\Services\Yutopia\YutopiaSpaces;
use App\Services\Yutopia\YutopiaTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Yutopia (the isometric world) — the user-facing API. See yutopia/docs/plan/02-architecture.md.
class YutopiaController extends Controller
{
    public function __construct(
        private readonly YutopiaSpaces $spaces,
        private readonly YutopiaTokens $tokens,
        private readonly YutopiaPresence $presence,
    ) {}

    public function spaces(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = $this->spaces->forUser($user)->map(fn (YutopiaSpace $s) => [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'mapKey' => $s->map_key,
            'projectId' => $s->project_id,
            'color' => $s->project?->color,
            'online' => count($this->presence->get($s)['people']),
        ]);

        return response()->json(['spaces' => $list, 'avatar' => $this->spaces->avatarFor($user), 'user' => ['id' => $user->id, 'name' => $user->name]]);
    }

    public function session(Request $request): JsonResponse
    {
        $data = $request->validate(['space_id' => ['required', 'integer']]);
        $user = $request->user();
        $space = $this->accessible($user, (int) $data['space_id']);
        $avatar = $this->spaces->avatarFor($user);

        return response()->json([
            'space' => ['id' => $space->id, 'name' => $space->name, 'mapKey' => $space->map_key, 'projectId' => $space->project_id, 'canBuild' => $space->isBuildableBy($user->id)],
            'user' => ['id' => $user->id, 'name' => $user->name],
            'avatar' => $avatar,
            'worldUrl' => config('yutopia.world_url'),
            'worldToken' => $this->tokens->world($user, $space, $avatar),
            'livekitUrl' => config('yutopia.livekit.url'),
            'livekitToken' => $this->tokens->livekit($user, $space),
            'expiresIn' => config('yutopia.token_ttl'),
        ]);
    }

    public function showAvatar(Request $request): JsonResponse
    {
        return response()->json(['avatar' => $this->spaces->avatarFor($request->user())]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $slug = ['required', 'string', 'alpha_dash', 'max:24'];
        $layers = $request->validate([
            'body' => $slug, 'skin' => $hex, 'hair' => $slug, 'hairColor' => $hex,
            'top' => $slug, 'topColor' => $hex, 'bottom' => $slug, 'bottomColor' => $hex,
            'accessory' => $slug, 'accessoryColor' => $hex,
        ]);
        YutopiaAvatar::updateOrCreate(['user_id' => $request->user()->id], ['layers' => $layers]);

        return response()->json(['avatar' => $this->spaces->avatarFor($request->user())]);
    }

    public function boards(Request $request, int $spaceId): JsonResponse
    {
        $space = $this->accessible($request->user(), $spaceId);

        return response()->json(['boards' => $this->spaces->boardSummaries($space, $request->user()->id)]);
    }

    public function status(Request $request, int $spaceId): JsonResponse
    {
        $space = $this->accessible($request->user(), $spaceId);

        return response()->json(['status' => $this->spaces->status($space)]);
    }

    // People who can be given a room or a desk: the project's owner and members.
    public function members(Request $request, int $spaceId): JsonResponse
    {
        $space = $this->accessible($request->user(), $spaceId);
        $project = $space->project;
        $people = collect([$project->owner])->merge($project->members)->filter()->unique('id')
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->sortBy('name')->values();

        return response()->json(['members' => $people]);
    }

    // For Yondra: who of this project is in Yutopia right now, and where.
    public function projectPresence(Request $request, int $projectId): JsonResponse
    {
        $space = YutopiaSpace::where('project_id', $projectId)->first();
        $project = Project::find($projectId);
        abort_unless($project && $project->isAccessibleBy($request->user()->id), 404);
        $space = $space ?? $this->spaces->ensureFor($project);
        $space->setRelation('project', $project);

        return response()->json($this->presence->get($space) + ['clientUrl' => config('yutopia.client_url')]);
    }

    // Same, from a board page: the board's project decides the space.
    public function boardPresence(Request $request, int $boardId): JsonResponse
    {
        $board = Board::find($boardId);
        abort_unless($board && $board->isAccessibleBy($request->user()->id), 404);
        if (! $board->project_id) {
            return response()->json(['spaceId' => null, 'projectId' => null, 'people' => [], 'at' => null, 'clientUrl' => config('yutopia.client_url')]);
        }

        return $this->projectPresence($request, $board->project_id);
    }

    // Yondra → Yutopia without logging in again: a one-time code, good for 60s.
    public function handoff(Request $request): JsonResponse
    {
        $code = Str::random(48);
        Cache::put('yutopia:handoff:'.$code, $request->user()->id, 60);

        return response()->json(['code' => $code, 'url' => rtrim(config('yutopia.client_url'), '/').'/handoff?code='.$code]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:48']]);
        $userId = Cache::pull('yutopia:handoff:'.$data['code']);
        abort_unless($userId, 401, 'Invalid or expired code');
        $user = User::findOrFail($userId);

        return response()->json(['token' => $user->createToken('yutopia')->plainTextToken, 'user' => $user]);
    }

    private function accessible(User $user, int $spaceId): YutopiaSpace
    {
        $space = YutopiaSpace::with('project')->find($spaceId);
        abort_unless($space && $space->isAccessibleBy($user->id), 404);

        return $space;
    }
}
