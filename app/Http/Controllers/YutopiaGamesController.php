<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaGame;
use App\Infrastructure\Models\YutopiaGameSave;
use App\Infrastructure\Models\YutopiaSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

// Yutopia's game room: the cartridges a team brings to its space (ROMs its
// members own — Yutopia never ships commercial games; the built-in, openly
// licensed ones are served by Yutopia's client) and the saves of every game
// played there. Only members of the space see, play or save them; only the
// person about to run a game downloads its ROM (everyone else watches the TV).
class YutopiaGamesController extends Controller
{
    // [extensions, max bytes, players] — the same table as yutopia/packages/shared/src/games.ts
    private const SYSTEMS = [
        '2600' => [['a26', 'bin'], 64 * 1024, 2],
        'nes' => [['nes'], 1024 * 1024, 2],
        'snes' => [['sfc', 'smc'], 8 * 1024 * 1024, 2],
        'md' => [['md', 'gen', 'bin', 'smd'], 8 * 1024 * 1024, 2],
        'gb' => [['gb', 'gbc'], 8 * 1024 * 1024, 1],
    ];

    private const QUOTA = 128 * 1024 * 1024; // per space

    private const STATE_MAX = 4 * 1024 * 1024;

    private const SRAM_MAX = 1024 * 1024;

    private const GAME_KEY = '/^(b:[a-z0-9-]{1,32}|u:\d{1,10})$/';

    public function index(Request $request, int $spaceId): JsonResponse
    {
        $space = $this->member($request->user(), $spaceId);
        $games = YutopiaGame::where('space_id', $space->id)->latest('id')->get();

        return response()->json(['games' => $games->map(fn (YutopiaGame $g) => $this->shape($g, $request->user()))]);
    }

    public function store(Request $request, int $spaceId): JsonResponse
    {
        $user = $request->user();
        $space = $this->member($user, $spaceId);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:8192'],
            'system' => ['required', 'string', 'in:'.implode(',', array_keys(self::SYSTEMS))],
            'title' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'players' => ['nullable', 'integer', 'min:1', 'max:2'],
        ]);
        [$exts, $max, $ports] = self::SYSTEMS[$data['system']];
        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = (int) $file->getSize();
        abort_unless(in_array($ext, $exts, true), 422, 'That file isn\'t a cartridge for this console.');
        abort_if($bytes <= 0 || $bytes > $max, 422, 'That cartridge is too big for this console.');
        $used = (int) YutopiaGame::where('space_id', $space->id)->sum('bytes');
        abort_if($used + $bytes > self::QUOTA, 422, 'The shelf is full: remove a cartridge first.');
        $sha1 = sha1_file($file->getRealPath());
        abort_if(YutopiaGame::where('space_id', $space->id)->where('sha1', $sha1)->exists(), 422, 'That cartridge is already on the shelf.');
        $path = $file->storeAs("yutopia/games/{$space->id}", "{$sha1}.{$ext}", 'local');

        $game = YutopiaGame::create([
            'space_id' => $space->id,
            'uploaded_by' => $user->id,
            'system' => $data['system'],
            'title' => trim($data['title']),
            'color' => strtolower($data['color']),
            'players' => min($ports, (int) ($data['players'] ?? 1)),
            'file_name' => mb_substr($name, 0, 120),
            'path' => $path,
            'bytes' => $bytes,
            'sha1' => $sha1,
        ]);

        return response()->json(['game' => $this->shape($game, $user)], 201);
    }

    public function destroy(Request $request, int $spaceId, int $gameId): JsonResponse
    {
        $user = $request->user();
        $space = $this->member($user, $spaceId);
        $game = YutopiaGame::where('space_id', $space->id)->findOrFail($gameId);
        abort_unless($this->mayRemove($game, $space, $user), 403, 'Only who brought it (or the owner) can take it away.');
        Storage::disk('local')->delete($game->path);
        $save = YutopiaGameSave::where('space_id', $space->id)->where('game', "u:{$game->id}")->first();
        if ($save) {
            Storage::disk('local')->delete(array_filter([$save->state_path, $save->sram_path]));
            $save->delete();
        }
        $game->delete();

        return response()->json(['ok' => true]);
    }

    public function rom(Request $request, int $spaceId, int $gameId): Response
    {
        $space = $this->member($request->user(), $spaceId);
        $game = YutopiaGame::where('space_id', $space->id)->findOrFail($gameId);
        abort_unless(Storage::disk('local')->exists($game->path), 404);

        return Storage::disk('local')->response($game->path, $game->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // Where a game left off in this space: which saves there are.
    public function saveInfo(Request $request, int $spaceId, string $game): JsonResponse
    {
        $space = $this->member($request->user(), $spaceId);
        $save = $this->saveRow($space, $game);

        return response()->json([
            'state' => (bool) $save?->state_path,
            'sram' => (bool) $save?->sram_path,
            'updatedAt' => $save?->updated_at?->toIso8601String(),
        ]);
    }

    public function saveFile(Request $request, int $spaceId, string $game, string $kind): Response
    {
        $space = $this->member($request->user(), $spaceId);
        $save = $this->saveRow($space, $game);
        $path = $kind === 'state' ? $save?->state_path : $save?->sram_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, "{$kind}.bin", [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function putSave(Request $request, int $spaceId, string $game): JsonResponse
    {
        $user = $request->user();
        $space = $this->member($user, $spaceId);
        $this->checkGameKey($space, $game);
        $request->validate([
            'state' => ['nullable', 'file', 'max:'.(self::STATE_MAX / 1024)],
            'sram' => ['nullable', 'file', 'max:'.(self::SRAM_MAX / 1024)],
        ]);
        abort_unless($request->hasFile('state') || $request->hasFile('sram'), 422, 'Nothing to save.');
        $dir = "yutopia/saves/{$space->id}/".str_replace(':', '-', $game);
        $save = YutopiaGameSave::firstOrNew(['space_id' => $space->id, 'game' => $game]);
        foreach (['state', 'sram'] as $kind) {
            if ($request->hasFile($kind)) {
                $save->{"{$kind}_path"} = $request->file($kind)->storeAs($dir, "{$kind}.bin", 'local');
            }
        }
        $save->updated_by = $user->id;
        $save->save();

        return response()->json(['ok' => true, 'updatedAt' => $save->updated_at->toIso8601String()]);
    }

    private function saveRow(YutopiaSpace $space, string $game): ?YutopiaGameSave
    {
        $this->checkGameKey($space, $game);

        return YutopiaGameSave::where('space_id', $space->id)->where('game', $game)->first();
    }

    private function checkGameKey(YutopiaSpace $space, string $game): void
    {
        abort_unless(preg_match(self::GAME_KEY, $game) === 1, 404);
        if (str_starts_with($game, 'u:')) {
            abort_unless(YutopiaGame::where('space_id', $space->id)->whereKey((int) substr($game, 2))->exists(), 404);
        }
    }

    private function member(User $user, int $spaceId): YutopiaSpace
    {
        $space = YutopiaSpace::with('project')->find($spaceId);
        abort_unless($space && $space->isAccessibleBy($user->id), 404);

        return $space;
    }

    private function mayRemove(YutopiaGame $game, YutopiaSpace $space, User $user): bool
    {
        return $game->uploaded_by === $user->id || $space->project?->owner_id === $user->id;
    }

    private function shape(YutopiaGame $g, User $user): array
    {
        return [
            'id' => "u:{$g->id}",
            'system' => $g->system,
            'title' => $g->title,
            'color' => $g->color,
            'players' => $g->players,
            'bytes' => $g->bytes,
            'builtIn' => false,
            'mine' => $g->uploaded_by === $user->id,
        ];
    }
}
