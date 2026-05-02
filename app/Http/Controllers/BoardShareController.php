<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BoardShareController extends Controller
{
    public function store(Request $request, int $boardId)
    {
        $board = Board::findOrFail($boardId);

        if ($board->user_id !== Auth::id()) {
            throw new AccessDeniedHttpException('Only the board owner can share it.');
        }

        $request->validate([
            'email'      => ['required', 'email', 'exists:users,email'],
            'permission' => ['sometimes', 'in:read,write'],
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You already own this board.'], 422);
        }

        $permission = $request->input('permission', 'write');

        BoardShare::updateOrCreate(
            ['board_id' => $boardId, 'user_id' => $user->id],
            ['permission' => $permission]
        );

        return response()->json([
            'message' => 'Board shared successfully.',
            'user'    => array_merge($user->only('id', 'name', 'email'), ['permission' => $permission]),
        ], 201);
    }

    public function update(Request $request, int $boardId, int $userId)
    {
        $board = Board::findOrFail($boardId);

        if ($board->user_id !== Auth::id()) {
            throw new AccessDeniedHttpException('Only the board owner can manage sharing.');
        }

        $request->validate(['permission' => ['required', 'in:read,write']]);

        BoardShare::where('board_id', $boardId)->where('user_id', $userId)
            ->update(['permission' => $request->permission]);

        return response()->json(['permission' => $request->permission]);
    }

    public function destroy(int $boardId, int $userId)
    {
        $board = Board::findOrFail($boardId);

        if ($board->user_id !== Auth::id()) {
            throw new AccessDeniedHttpException('Only the board owner can manage sharing.');
        }

        BoardShare::where('board_id', $boardId)->where('user_id', $userId)->delete();

        return response()->json(null, 204);
    }
}
