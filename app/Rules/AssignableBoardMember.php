<?php

declare(strict_types=1);

namespace App\Rules;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A card may only be assigned to someone who can actually see the board:
 * the board owner, a user the board is shared with, or — when the board
 * belongs to a project — the project owner or any project member.
 *
 * Without this check any authenticated user id was accepted, which leaked
 * the board id and card name to strangers via CardAssignedNotification.
 */
class AssignableBoardMember implements ValidationRule
{
    public function __construct(private Board $board) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $userId = (int) $value;

        if ($this->board->user_id === $userId) {
            return;
        }

        if ($this->board->sharedWith()->where('users.id', $userId)->exists()) {
            return;
        }

        if ($this->board->project_id) {
            $project = Project::find($this->board->project_id);
            if ($project && $project->isAccessibleBy($userId)) {
                return;
            }
        }

        $fail('The selected user does not have access to this board.');
    }
}
