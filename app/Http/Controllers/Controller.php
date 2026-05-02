<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class Controller
{
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
}
