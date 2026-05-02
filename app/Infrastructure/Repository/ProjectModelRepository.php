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

        $owned = Project::where('owner_id', $userId)
            ->with($eagerLoad)
            ->withCount('boards')
            ->get();

        $member = Project::where('owner_id', '!=', $userId)
            ->whereHas('members', fn($q) => $q->where('users.id', $userId))
            ->with($eagerLoad)
            ->withCount('boards')
            ->get();

        $flattenSharedWith = fn($projects) => $projects->each(function ($p) {
            $p->boards->each(fn($b) => $b->sharedWith->each(fn($u) => $u->permission = $u->pivot->permission ?? 'write'));
            $p->members->each(fn($u) => $u->role = $u->pivot->role);
        });

        $flattenSharedWith($owned);
        $flattenSharedWith($member);

        return ['owned' => $owned->values(), 'member' => $member->values()];
    }

    public function show(int $id)
    {
        $project = Project::with([
            'owner:id,name,email',
            'members:id,name,email',
            'boards' => fn($q) => $q->withCount('cards')->with(['owner:id,name', 'sharedWith:id,name']),
        ])->findOrFail($id);

        $this->authorizeAccess($project);

        $project->members->each(fn($u) => $u->role = $u->pivot->role);

        return $project;
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
