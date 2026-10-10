<?php

namespace App\Services\Yutopia;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaAvatar;
use App\Infrastructure\Models\YutopiaDesk;
use App\Infrastructure\Models\YutopiaObject;
use App\Infrastructure\Models\YutopiaSpace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class YutopiaSpaces
{
    public const DEFAULT_AVATAR = [
        'body' => 'regular', 'skin' => '#c68a5e', 'hair' => 'short', 'hairColor' => '#3b2a20',
        'top' => 'tee', 'topColor' => '#b5562f', 'bottom' => 'jeans', 'bottomColor' => '#3d4a5c',
        'accessory' => 'none', 'accessoryColor' => '#e8a33d',
    ];

    // Every active project the user can open gets a space (created lazily).
    public function forUser(User $user): Collection
    {
        $projects = Project::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->where('owner_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id)))
            ->orderBy('name')
            ->get();

        return $projects->map(fn (Project $p) => $this->ensureFor($p));
    }

    public function ensureFor(Project $project): YutopiaSpace
    {
        return YutopiaSpace::firstOrCreate(
            ['project_id' => $project->id],
            ['slug' => Str::slug($project->name).'-'.$project->id, 'name' => $project->name, 'map_key' => 'studio'],
        );
    }

    public function avatarFor(User $user): array
    {
        $row = YutopiaAvatar::where('user_id', $user->id)->first();

        return array_merge(self::DEFAULT_AVATAR, $row?->layers ?? []);
    }

    // First join seeds the room with the map's default furniture; board walls get
    // the project's boards in order. Idempotent: a seeded space is left alone.
    public function seed(YutopiaSpace $space, array $objects): Collection
    {
        return DB::transaction(function () use ($space, $objects) {
            $space->refresh();
            if ($space->seeded_at === null) {
                $boards = $space->project
                    ? Board::where('project_id', $space->project_id)->whereNull('archived_at')->orderBy('position')->pluck('id')->all()
                    : [];
                $next = 0;
                foreach ($objects as $o) {
                    $ref = $o['refId'] ?? null;
                    if (($o['kind'] ?? null) === 'board_wall' && $ref === null) {
                        $ref = $boards[$next++] ?? null;
                    }
                    YutopiaObject::updateOrCreate(
                        ['space_id' => $space->id, 'uid' => $o['id']],
                        ['kind' => $o['kind'], 'x' => $o['x'], 'y' => $o['y'], 'rot' => $o['rot'] ?? 0, 'ref_id' => $ref, 'props' => $o['props'] ?? null],
                    );
                }
                $space->update(['seeded_at' => now()]);
            }

            return $space->objects()->orderBy('id')->get();
        });
    }

    public function deskFor(YutopiaSpace $space, int $userId, array $deskUids): ?string
    {
        return DB::transaction(function () use ($space, $userId, $deskUids) {
            $mine = YutopiaDesk::where('space_id', $space->id)->where('user_id', $userId)->first();
            if ($mine && in_array($mine->desk_uid, $deskUids, true)) {
                return $mine->desk_uid;
            }
            $taken = YutopiaDesk::where('space_id', $space->id)->pluck('desk_uid')->all();
            $free = array_values(array_diff($deskUids, $taken));
            if ($free === []) {
                return null;
            }
            YutopiaDesk::updateOrCreate(['space_id' => $space->id, 'user_id' => $userId], ['desk_uid' => $free[0]]);

            return $free[0];
        });
    }

    // What each member is doing right now: assigned, open cards outside the first
    // (backlog/to-do) and done sections. Shown at desks and in the standup corner.
    public function status(YutopiaSpace $space): array
    {
        if (! $space->project_id) {
            return [];
        }
        $boards = Board::where('project_id', $space->project_id)->whereNull('archived_at')
            ->with(['sections' => fn ($q) => $q->orderBy('order')])->get();
        $out = [];
        foreach ($boards as $board) {
            $sections = $board->sections;
            $skip = array_filter([$sections->first()?->id, $board->done_section_id, $board->lost_section_id]);
            $doingIds = $sections->filter(fn ($s) => ! in_array($s->id, $skip, true)
                && ! preg_match('/done|feito|conclu|closed|archiv/i', $s->name))->pluck('id');
            if ($doingIds->isEmpty()) {
                continue;
            }
            $cards = Card::where('board_id', $board->id)
                ->whereIn('section_id', $doingIds)
                ->whereNotNull('assigned_user_id')->whereNull('archived_at')->whereNull('parent_card_id')
                ->where('is_done', false)
                ->orderBy('section_entered_at', 'desc')
                ->limit(200)
                ->get(['id', 'name', 'section_id', 'assigned_user_id', 'ticket_number', 'blocked_at']);
            foreach ($cards as $card) {
                $out[$card->assigned_user_id][] = [
                    'id' => $card->id,
                    'name' => $card->name,
                    'boardId' => $board->id,
                    'boardName' => $board->name,
                    'section' => $sections->firstWhere('id', $card->section_id)?->name,
                    'ticket' => Card::ticketKey($board->ticket_prefix, $card->ticket_number),
                    'blocked' => $card->blocked_at !== null,
                ];
            }
        }

        return collect($out)->map(fn ($cards, $uid) => ['userId' => (int) $uid, 'doing' => array_slice($cards, 0, 5)])->values()->all();
    }

    // Live counts for the board walls: cards per section, drawn in-world.
    public function boardSummaries(YutopiaSpace $space, int $userId): array
    {
        if (! $space->project_id) {
            return [];
        }

        return Board::where('project_id', $space->project_id)->whereNull('archived_at')
            ->with(['sections' => fn ($q) => $q->orderBy('order')])
            ->orderBy('position')->get()
            ->filter(fn (Board $b) => $b->isAccessibleBy($userId))
            ->map(function (Board $b) {
                $counts = Card::where('board_id', $b->id)->whereNull('archived_at')->whereNull('parent_card_id')
                    ->selectRaw('section_id, count(*) as n')->groupBy('section_id')->pluck('n', 'section_id');

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'type' => $b->type,
                    'sections' => $b->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'count' => (int) ($counts[$s->id] ?? 0)])->values(),
                ];
            })->values()->all();
    }
}
