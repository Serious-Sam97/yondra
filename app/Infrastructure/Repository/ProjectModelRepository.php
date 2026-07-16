<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\ProjectRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProjectModelRepository implements ProjectRepository
{
    public function index()
    {
        $userId = Auth::id();

        $eagerLoad = [
            'owner:id,name',
            'members:id,name,email',
            // Archived boards are hidden from the lists; count excludes them too.
            'boards' => fn ($q) => $q->whereNull('archived_at')->with(['owner:id,name', 'sharedWith:id,name']),
        ];
        $boardsCount = ['boards as boards_count' => fn ($q) => $q->whereNull('archived_at')];

        // "Owned" = you are the primary owner OR carry the 'owner' pivot role (co-owner).
        $owned = Project::whereNull('archived_at')
            ->where(fn ($q) => $q
                ->where('owner_id', $userId)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId)->where('role', 'owner')))
            ->with($eagerLoad)
            ->withCount($boardsCount)
            ->get();

        $ownedIds = $owned->pluck('id');

        $member = Project::whereNull('archived_at')
            ->whereNotIn('id', $ownedIds)
            ->whereHas('members', fn ($q) => $q->where('users.id', $userId))
            ->with($eagerLoad)
            ->withCount($boardsCount)
            ->get();

        $flattenSharedWith = fn ($projects) => $projects->each(function ($p) {
            $p->boards->each(fn ($b) => $b->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write'));
            $p->members->each(fn ($u) => $u->role = $u->pivot->role);
        });

        // Project owners see every board; non-owner members only see boards
        // they own or are shared onto.
        $member->each(fn ($p) => $this->restrictBoardsForMember($p, $userId));

        $flattenSharedWith($owned);
        $flattenSharedWith($member);

        // Real per-board card totals + To Do/Doing/Done flow, in a few grouped
        // queries across every visible board at once (drives the rail progress %).
        $this->attachFlow($owned->pluck('boards')->flatten(1)->merge($member->pluck('boards')->flatten(1)));

        return ['owned' => $owned->values(), 'member' => $member->values()];
    }

    public function show(int $id)
    {
        $userId = Auth::id();

        $project = Project::with([
            'owner:id,name,email',
            'members:id,name,email',
            'boards' => fn ($q) => $q->whereNull('archived_at')->with(['owner:id,name', 'sharedWith:id,name']),
        ])->findOrFail($id);

        $this->authorizeAccess($project);

        $project->members->each(fn ($u) => $u->role = $u->pivot->role);

        // Non-owner members only see boards they own or are shared onto.
        $isOwner = $project->isOwnedBy($userId);
        if (! $isOwner) {
            $this->restrictBoardsForMember($project, $userId);
        }

        // show() doesn't withCount('boards'); keep boards_count in sync with the
        // boards actually returned so clients don't read a stale/absent count.
        $project->boards_count = $project->boards->count();

        $project->boards->each(fn ($b) => $b->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write'));

        // Archived boards live in a separate list the client toggles into view.
        $archived = Board::where('project_id', $id)->whereNotNull('archived_at')
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->orderByDesc('archived_at')
            ->get();
        if (! $isOwner) {
            $archived = $archived->filter(fn ($b) => $b->user_id === $userId
                || $b->sharedWith->contains(fn ($u) => $u->id === $userId))->values();
        }
        $project->archived_boards = $archived;

        // Real card totals + To Do/Doing/Done flow for every board shown.
        $this->attachFlow($project->boards->merge($archived));

        // Expose the current user's manage capability so the client can gate the
        // settings page without re-deriving the (co-owner-aware) rules.
        $project->can_manage = $isOwner;

        return $project;
    }

    /**
     * Attach real card totals + a {todo, doing, done} flow breakdown to each board,
     * using a handful of grouped aggregates instead of loading cards. Overwrites
     * cards_count with the scoped total (non-archived, non-subtask) and sets flow.
     */
    private function attachFlow($boards): void
    {
        $ids = collect($boards)->pluck('id')->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $scoped = fn () => Card::whereIn('board_id', $ids)
            ->whereNull('archived_at')
            ->whereNull('parent_card_id');

        $total = $scoped()->selectRaw('board_id, count(*) as c')->groupBy('board_id')->pluck('c', 'board_id');
        $done = $scoped()->whereNotNull('done_at')->selectRaw('board_id, count(*) as c')->groupBy('board_id')->pluck('c', 'board_id');

        // "To Do" = the first ordered non-Backlog column of each board.
        $firstSectionIds = [];
        foreach (Section::whereIn('board_id', $ids)->orderBy('order')->get(['id', 'board_id', 'name']) as $s) {
            if (strtolower((string) $s->name) === 'backlog') {
                continue;
            }
            $firstSectionIds[$s->board_id] ??= $s->id;
        }
        $todo = empty($firstSectionIds)
            ? collect()
            : $scoped()->whereNull('done_at')->whereIn('section_id', array_values($firstSectionIds))
                ->selectRaw('board_id, count(*) as c')->groupBy('board_id')->pluck('c', 'board_id');

        foreach ($boards as $b) {
            $t = (int) ($total[$b->id] ?? 0);
            $d = (int) ($done[$b->id] ?? 0);
            $td = (int) ($todo[$b->id] ?? 0);
            $b->cards_count = $t;
            $b->done_count = $d;
            $b->flow = ['todo' => $td, 'doing' => max(0, $t - $d - $td), 'done' => $d];
        }
    }

    /**
     * Drop boards the given user has no direct access to (not the board owner,
     * not shared onto it) and recount, so a project member never sees boards
     * that were never shared with them.
     */
    private function restrictBoardsForMember(Project $project, int $userId): void
    {
        $accessible = $project->boards
            ->filter(fn ($b) => $b->user_id === $userId || $b->sharedWith->contains(fn ($u) => $u->id === $userId))
            ->values();

        $project->setRelation('boards', $accessible);
        $project->boards_count = $accessible->count();
    }

    public function save(array $request)
    {
        $userId = Auth::id();

        $project = Project::create([
            'owner_id' => $userId,
            'name' => $request['name'],
            'description' => $request['description'] ?? null,
            'color' => $request['color'] ?? '#1976D2',
        ]);

        $project->members()->attach($userId, ['role' => 'owner']);

        $project->load(['owner:id,name', 'members:id,name,email'])->loadCount('boards');
        $project->setAttribute('boards', collect());

        return $project;
    }

    public function update(array $request)
    {
        $project = Project::findOrFail($request['id']);
        $this->authorizeOwner($project);

        $project->update([
            'name' => $request['name'] ?? $project->name,
            'description' => array_key_exists('description', $request) ? $request['description'] : $project->description,
            'color' => $request['color'] ?? $project->color,
            'default_permission' => $request['default_permission'] ?? $project->default_permission,
        ]);

        return $project->fresh()->load([
            'owner:id,name',
            'members:id,name,email',
            'boards' => fn ($q) => $q->withCount('cards')->with(['owner:id,name', 'sharedWith:id,name']),
        ])->loadCount('boards');
    }

    public function delete(int $id)
    {
        $project = Project::findOrFail($id);
        $this->authorizeOwner($project);

        Board::where('project_id', $id)->update(['project_id' => null]);
        $project->delete();
    }

    public function addMember(int $projectId, int $userId, string $role)
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwner($project);

        $project->members()->syncWithoutDetaching([$userId => ['role' => $role]]);

        return $this->show($projectId);
    }

    public function updateMember(int $projectId, int $userId, string $role)
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwner($project);

        // The primary owner (creator) must always remain an owner.
        if ($userId === $project->owner_id && $role !== 'owner') {
            abort(422, 'Cannot change the primary owner\'s role.');
        }

        $project->members()->updateExistingPivot($userId, ['role' => $role]);

        return $this->show($projectId);
    }

    public function removeMember(int $projectId, int $userId)
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwner($project);

        if ($userId === $project->owner_id) {
            abort(422, 'Cannot remove the project owner.');
        }

        $project->members()->detach($userId);
    }

    public function setArchived(int $id, bool $archived)
    {
        $project = Project::findOrFail($id);
        $this->authorizeOwner($project);
        $project->update(['archived_at' => $archived ? now() : null]);

        return $this->show($id);
    }

    /**
     * Persist a manual board order within a project. The `where('project_id', …)`
     * guard means ids belonging to another project are silently ignored, so a
     * caller can never reassign a board's project through this path.
     */
    public function reorderBoards(int $projectId, array $boardIds): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwner($project);

        DB::transaction(function () use ($projectId, $boardIds) {
            foreach ($boardIds as $order => $id) {
                Board::where('project_id', $projectId)->where('id', $id)->update(['position' => $order]);
            }
        });
    }

    public function duplicate(int $id, ?string $name, bool $includeBoards, bool $includeCards)
    {
        $source = Project::with('boards')->findOrFail($id);
        $this->authorizeAccess($source);
        $userId = Auth::id();

        $copy = Project::create([
            'owner_id' => $userId,
            'name' => $name ?: ($source->name.' (copy)'),
            'description' => $source->description,
            'color' => $source->color,
            'default_permission' => $source->default_permission,
        ]);
        $copy->members()->attach($userId, ['role' => 'owner']);

        if ($includeBoards) {
            foreach ($source->boards as $board) {
                $this->cloneBoardInto($board, $copy->id, $userId, $includeCards);
            }
        }

        return $this->show($copy->id);
    }

    /**
     * Deep-clone a board (columns, tags, and optionally cards) into another project,
     * owned by the given user. Mirrors BoardModelRepository::duplicate.
     */
    private function cloneBoardInto(Board $board, int $projectId, int $userId, bool $includeCards): void
    {
        $board->load(['sections', 'tags']);

        $copy = Board::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'name' => $board->name,
            'description' => $board->description,
            'ticket_prefix' => $board->ticket_prefix,
            'background' => $board->background,
            'default_permission' => $board->default_permission,
        ]);

        $sectionMap = [];
        foreach ($board->sections as $section) {
            $new = Section::create(['board_id' => $copy->id, 'name' => $section->name, 'order' => $section->order]);
            $sectionMap[$section->id] = $new->id;
        }

        $tagMap = [];
        foreach ($board->tags as $tag) {
            $new = Tag::create(['board_id' => $copy->id, 'name' => $tag->name, 'color' => $tag->color]);
            $tagMap[$tag->id] = $new->id;
        }

        if ($includeCards) {
            $cards = Card::where('board_id', $board->id)
                ->whereNull('archived_at')
                ->whereNull('parent_card_id')
                ->with('tags:id')
                ->orderBy('position')
                ->get();
            $ticket = 1;
            foreach ($cards as $card) {
                $clone = $card->replicate(['ticket_number', 'archived_at', 'done_at']);
                $clone->board_id = $copy->id;
                $clone->section_id = $sectionMap[$card->section_id] ?? $copy->sections()->min('id');
                $clone->ticket_number = $ticket++;
                $clone->save();
                $newTagIds = $card->tags->pluck('id')->map(fn ($tid) => $tagMap[$tid] ?? null)->filter()->all();
                if ($newTagIds) {
                    $clone->tags()->sync($newTagIds);
                }
            }
            $copy->update(['next_ticket_number' => $ticket]);
        }
    }

    public function memberCandidates(int $projectId, ?string $q)
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwner($project);

        $memberIds = $project->members()->pluck('users.id')->push($project->owner_id)->unique();

        return User::whereNotIn('id', $memberIds)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);
    }

    private function authorizeAccess(Project $project): void
    {
        if (! $project->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }
    }

    private function authorizeOwner(Project $project): void
    {
        if (! $project->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }
    }
}
