<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class Controller
{
    protected function boardCard(int $boardId, int $cardId): Card
    {
        return Card::where('board_id', $boardId)->findOrFail($cardId);
    }

    protected function authorizeBoard(int $boardId): Board
    {
        $board = Board::findOrFail($boardId);

        if (!$board->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }

        return $board;
    }

    protected function authorizeOwner(int $boardId): Board
    {
        $board = Board::findOrFail($boardId);

        if (Auth::id() !== $board->user_id) {
            throw new AccessDeniedHttpException();
        }

        return $board;
    }

    protected function authorizeWrite(int $boardId): Board
    {
        $board = Board::findOrFail($boardId);

        if (!$board->isWritableBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }

        return $board;
    }

    /**
     * Owner-level actions (delete, manage sharing). Allows the board creator, an
     * 'owner' share, or a project owner — see Board::isOwnedBy.
     */
    protected function authorizeManage(int $boardId): Board
    {
        $board = Board::findOrFail($boardId);

        if (!$board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }

        return $board;
    }
}
