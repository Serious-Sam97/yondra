<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Notifications\BoardSharedNotification;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BoardShareController extends Controller
{
    /**
     * List members of the board's parent project so the owner can grant board
     * access by picking from the roster instead of retyping emails. Each entry
     * is annotated with whether that user already has the board shared to them.
     */
    public function candidates(int $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (! $board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException('Only a board owner can manage sharing.');
        }

        if (! $board->project_id) {
            return response()->json([]);
        }

        $project = Project::with('members:id,name,email')->find($board->project_id);

        if (! $project) {
            return response()->json([]);
        }

        $shared = BoardShare::where('board_id', $boardId)->pluck('permission', 'user_id');

        $members = $project->members
            ->reject(fn ($u) => $u->id === $board->user_id) // owner already has full access
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
                'shared' => $shared->has($u->id),
                'permission' => $shared->get($u->id),
            ])
            ->values();

        return response()->json($members);
    }

    public function store(Request $request, int $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (! $board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException('Only a board owner can share it.');
        }

        $request->validate([
            'email' => ['required_without:user_id', 'email', 'exists:users,email'],
            'user_id' => ['required_without:email', 'integer', 'exists:users,id'],
            'permission' => ['sometimes', 'in:read,write,owner'],
        ]);

        $user = $request->filled('user_id')
            ? User::findOrFail($request->integer('user_id'))
            : User::where('email', $request->email)->firstOrFail();

        $permission = $request->input('permission', $board->default_permission ?? 'write');

        $isNew = ! BoardShare::where('board_id', $boardId)->where('user_id', $user->id)->exists();

        BoardShare::updateOrCreate(
            ['board_id' => $boardId, 'user_id' => $user->id],
            ['permission' => $permission]
        );

        // Notify on a fresh share only (not on a permission change to an existing member).
        if ($isNew && $user->id !== Auth::id()) {
            resolve(Notifier::class)->send($user, new BoardSharedNotification(
                actorId: (int) Auth::id(),
                actorName: Auth::user()->name,
                boardId: $boardId,
                boardName: $board->name,
            ));
        }

        return response()->json([
            'message' => 'Board shared successfully.',
            'user' => array_merge($user->only('id', 'name', 'email'), ['permission' => $permission]),
        ], 201);
    }

    public function update(Request $request, int $boardId, int $userId)
    {
        $board = Board::findOrFail($boardId);

        if (! $board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException('Only a board owner can manage sharing.');
        }

        $request->validate(['permission' => ['required', 'in:read,write,owner']]);

        BoardShare::where('board_id', $boardId)->where('user_id', $userId)
            ->update(['permission' => $request->permission]);

        return response()->json(['permission' => $request->permission]);
    }

    public function destroy(int $boardId, int $userId)
    {
        $board = Board::findOrFail($boardId);

        if (! $board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException('Only a board owner can manage sharing.');
        }

        BoardShare::where('board_id', $boardId)->where('user_id', $userId)->delete();

        return response()->json(null, 204);
    }
}
