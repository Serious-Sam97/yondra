<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Notifications\ProjectMemberAddedNotification;
use App\Services\Notifier;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectMemberController extends Controller
{
    private ProjectService $service;

    public function __construct()
    {
        $this->service = resolve(ProjectService::class);
    }

    public function candidates(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->service->memberCandidates($projectId, $validated['q'] ?? null);
    }

    public function store(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['nullable', 'in:owner,member,viewer'],
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();
        $role = $validated['role'] ?? 'member';

        $result = $this->service->addMember($projectId, $user->id, $role);

        if ($user->id !== Auth::id()) {
            resolve(Notifier::class)->send($user, new ProjectMemberAddedNotification(
                actorId: (int) Auth::id(),
                actorName: Auth::user()->name,
                projectId: $projectId,
                projectName: (string) (Project::find($projectId)?->name ?? 'a project'),
            ));
        }

        return $result;
    }

    public function update(Request $request, int $projectId, int $userId)
    {
        $validated = $request->validate([
            'role' => ['required', 'in:owner,member,viewer'],
        ]);

        return $this->service->updateMember($projectId, $userId, $validated['role']);
    }

    public function destroy(int $projectId, int $userId)
    {
        $this->service->removeMember($projectId, $userId);

        return response()->json(null, 204);
    }
}
