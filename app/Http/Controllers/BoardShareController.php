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
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You already own this board.'], 422);
        }

        BoardShare::firstOrCreate([
            'board_id' => $boardId,
            'user_id'  => $user->id,
        ]);

        return response()->json(['message' => 'Board shared successfully.', 'user' => $user->only('id', 'name', 'email')], 201);
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
