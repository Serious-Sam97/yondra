<?php
namespace App\Http\Controllers;

use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\YondraNotification;
use App\Services\MentionService;
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

        $message = Auth::user()->name . ' commented on "' . $card->name . '"';
        $notified = collect();

        if ($card->assigned_user_id && $card->assigned_user_id !== Auth::id()) {
            YondraNotification::create([
                'user_id'  => $card->assigned_user_id,
                'board_id' => $boardId,
                'card_id'  => $cardId,
                'message'  => $message,
            ]);
            $notified->push($card->assigned_user_id);
        }

        if ($card->created_by_user_id && $card->created_by_user_id !== Auth::id() && !$notified->contains($card->created_by_user_id)) {
            YondraNotification::create([
                'user_id'  => $card->created_by_user_id,
                'board_id' => $boardId,
                'card_id'  => $cardId,
                'message'  => $message,
            ]);
            $notified->push($card->created_by_user_id);
        }

        resolve(MentionService::class)->notify(
            $boardId,
            $cardId,
            $validated['body'],
            Auth::user()->name . ' mentioned you in "' . $card->name . '"',
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
