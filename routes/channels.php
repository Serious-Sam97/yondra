<?php

use App\Infrastructure\Models\Board;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('board.{boardId}', function ($user, $boardId) {
    $board = Board::find($boardId);
    return $board && $board->isAccessibleBy($user->id);
});
