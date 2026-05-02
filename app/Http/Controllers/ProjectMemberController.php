<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    private ProjectService $service;

    public function __construct()
    {
        $this->service = resolve(ProjectService::class);
    }

    public function store(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role'  => ['nullable', 'in:member,viewer'],
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();
        $role = $validated['role'] ?? 'member';

        return $this->service->addMember($projectId, $user->id, $role);
    }

    public function update(Request $request, int $projectId, int $userId)
    {
        $validated = $request->validate([
            'role' => ['required', 'in:member,viewer'],
        ]);

        return $this->service->updateMember($projectId, $userId, $validated['role']);
    }

    public function destroy(int $projectId, int $userId)
    {
        $this->service->removeMember($projectId, $userId);
        return response()->json(null, 204);
    }
}
