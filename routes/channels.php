<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('board.{boardId}', function ($user, $boardId) {
    $board = Board::find($boardId);

    return $board && $board->isAccessibleBy($user->id);
});

Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    $project = Project::find($projectId);

    return $project && $project->isAccessibleBy($user->id);
});

// Presence for the Vortex mascot: who else is on this board right now, so each
// visitor's Vortex can appear as a translucent ghost. Members see only id + name.
Broadcast::channel('board-presence.{boardId}', function ($user, $boardId) {
    $board = Board::find($boardId);

    return $board && $board->isAccessibleBy($user->id)
        ? ['id' => (int) $user->id, 'name' => (string) $user->name]
        : false;
});
