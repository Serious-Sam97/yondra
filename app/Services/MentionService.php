<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\YondraNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class MentionService
{
    /**
     * Notify board members mentioned in $body as @FullNameWithoutSpaces (exact casing),
     * skipping the author and anyone already in $alreadyNotified.
     * Returns the collection of notified user ids.
     */
    public function notify(int $boardId, ?int $cardId, string $body, string $message, ?Collection $alreadyNotified = null): Collection
    {
        $notified = $alreadyNotified ?? collect();

        preg_match_all('/@(\w+)/u', $body, $matches);
        if (empty($matches[1])) {
            return $notified;
        }

        $board = Board::with(['owner', 'sharedWith'])->find($boardId);
        if (!$board) {
            return $notified;
        }
        $boardUsers = collect([$board->owner])->merge($board->sharedWith)->filter();

        foreach ($matches[1] as $handle) {
            $mentioned = $boardUsers->first(
                fn ($u) => preg_replace('/\s+/u', '', $u->name) === $handle
            );
            if ($mentioned && $mentioned->id !== Auth::id() && !$notified->contains($mentioned->id)) {
                YondraNotification::create([
                    'user_id'  => $mentioned->id,
                    'board_id' => $boardId,
                    'card_id'  => $cardId,
                    'message'  => $message,
                ]);
                $notified->push($mentioned->id);
            }
        }

        return $notified;
    }
}
