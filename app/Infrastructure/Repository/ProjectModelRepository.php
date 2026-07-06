<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\ProjectRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProjectModelRepository implements ProjectRepository
{
    public function index()
    {
        $userId = Auth::id();

        $eagerLoad = [
            'owner:id,name',
            'members:id,name,email',
            'boards' => fn($q) => $q->withCount('cards')->with(['owner:id,name', 'sharedWith:id,name']),
        ];

        // "Owned" = you are the primary owner OR carry the 'owner' pivot role (co-owner).
        $owned = Project::where(fn($q) => $q
                ->where('owner_id', $userId)
                ->orWhereHas('members', fn($m) => $m->where('users.id', $userId)->where('role', 'owner')))
            ->with($eagerLoad)
            ->withCount('boards')
            ->get();

        $ownedIds = $owned->pluck('id');

        $member = Project::whereNotIn('id', $ownedIds)
            ->whereHas('members', fn($q) => $q->where('users.id', $userId))
            ->with($eagerLoad)
            ->withCount('boards')
            ->get();

        $flattenSharedWith = fn($projects) => $projects->each(function ($p) {
            $p->boards->each(fn($b) => $b->sharedWith->each(fn($u) => $u->permission = $u->pivot->permission ?? 'write'));
            $p->members->each(fn($u) => $u->role = $u->pivot->role);
        });

        // Project owners see every board; non-owner members only see boards
        // they own or are shared onto.
        $member->each(fn($p) => $this->restrictBoardsForMember($p, $userId));

        $flattenSharedWith($owned);
        $flattenSharedWith($member);

        return ['owned' => $owned->values(), 'member' => $member->values()];
    }

    public function show(int $id)
    {
        $userId = Auth::id();

        $project = Project::with([
            'owner:id,name,email',
            'members:id,name,email',
            'boards' => fn($q) => $q->withCount('cards')->with(['owner:id,name', 'sharedWith:id,name']),
        ])->findOrFail($id);

        $this->authorizeAccess($project);

        $project->members->each(fn($u) => $u->role = $u->pivot->role);

        // Non-owner members only see boards they own or are shared onto.
        if (!$project->isOwnedBy($userId)) {
            $this->restrictBoardsForMember($project, $userId);
        }

        // show() doesn't withCount('boards'); keep boards_count in sync with the
        // boards actually returned so clients don't read a stale/absent count.
        $project->boards_count = $project->boards->count();

        $project->boards->each(fn($b) => $b->sharedWith->each(fn($u) => $u->permission = $u->pivot->permission ?? 'write'));

        return $project;
    }

    /**
     * Drop boards the given user has no direct access to (not the board owner,
     * not shared onto it) and recount, so a project member never sees boards
     * that were never shared with them.
     */
    private function restrictBoardsForMember(Project $project, int $userId): void
    {
        $accessible = $project->boards
            ->filter(fn($b) => $b->user_id === $userId || $b->sharedWith->contains(fn($u) => $u->id === $userId))
            ->values();

        $project->setRelation('boards', $accessible);
        $project->boards_count = $accessible->count();
    }

    public function save(array $request)
    {
        $userId = Auth::id();

        $project = Project::create([
            'owner_id'    => $userId,
            'name'        => $request['name'],
            'description' => $request['description'] ?? null,
            'color'       => $request['color'] ?? '#1976D2',
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
            'name'        => $request['name'] ?? $project->name,
            'description' => array_key_exists('description', $request) ? $request['description'] : $project->description,
            'color'       => $request['color'] ?? $project->color,
        ]);

        return $project->fresh()->load([
            'owner:id,name',
            'members:id,name,email',
            'boards' => fn($q) => $q->withCount('cards')->with(['owner:id,name', 'sharedWith:id,name']),
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

    private function authorizeAccess(Project $project): void
    {
        if (!$project->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }
    }

    private function authorizeOwner(Project $project): void
    {
        if (!$project->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }
    }
}
