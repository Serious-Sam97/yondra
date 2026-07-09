<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\User;
use App\Notifications\CardCommentedNotification;
use App\Services\MentionService;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardCommentController extends Controller
{
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);

        return CardComment::where('card_id', $card->id)->with('user:id,name')->latest()->get();
    }

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate(['body' => ['required', 'string']]);
        $comment = CardComment::create(['card_id' => $card->id, 'user_id' => Auth::id(), 'body' => $validated['body']]);
        $comment->load('user:id,name');

        $actor = Auth::user();

        // Notify the assignee and the card's creator (deduped, minus the author).
        $notified = collect([$card->assigned_user_id, $card->created_by_user_id])
            ->filter()
            ->reject(fn ($id) => $id === $actor->id)
            ->unique()
            ->values();

        if ($notified->isNotEmpty()) {
            resolve(Notifier::class)->send(
                User::whereIn('id', $notified)->get(),
                new CardCommentedNotification(
                    actorId: (int) $actor->id,
                    actorName: $actor->name,
                    boardId: $boardId,
                    cardId: $cardId,
                    cardName: $card->name,
                ),
            );
        }

        resolve(MentionService::class)->notify(
            $boardId,
            $cardId,
            $validated['body'],
            $actor->name.' mentioned you in "'.$card->name.'"',
            $notified,
        );

        return response()->json($comment, 201);
    }

    public function destroy(int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $comment = CardComment::where('card_id', $card->id)->where('user_id', Auth::id())->findOrFail($commentId);
        $comment->delete();

        return response()->json(null, 204);
    }
}
