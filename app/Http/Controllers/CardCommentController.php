<?php
namespace App\Http\Controllers;

use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\YondraNotification;
use App\Infrastructure\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardCommentController extends Controller
{
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        return CardComment::where('card_id', $cardId)->with('user:id,name')->latest()->get();
    }

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate(['body' => ['required', 'string']]);
        $comment = CardComment::create(['card_id' => $cardId, 'user_id' => Auth::id(), 'body' => $validated['body']]);
        $comment->load('user:id,name');

        $card = Card::find($cardId);
        if ($card) {
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

            // Mention detection
            preg_match_all('/@(\w+)/u', $validated['body'], $matches);
            if (!empty($matches[1])) {
                $board = \App\Infrastructure\Models\Board::with(['owner', 'sharedWith'])->find($boardId);
                $boardUsers = collect([$board->owner])->merge($board->sharedWith)->filter();
                foreach ($matches[1] as $handle) {
                    $mentioned = $boardUsers->first(fn($u) =>
                        strtolower(explode(' ', $u->name)[0]) === strtolower($handle)
                    );
                    if ($mentioned && $mentioned->id !== Auth::id() && !$notified->contains($mentioned->id)) {
                        YondraNotification::create([
                            'user_id'  => $mentioned->id,
                            'board_id' => $boardId,
                            'card_id'  => $cardId,
                            'message'  => Auth::user()->name . ' mentioned you in "' . $card->name . '"',
                        ]);
                        $notified->push($mentioned->id);
                    }
                }
            }
        }

        return response()->json($comment, 201);
    }

    public function destroy(int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $comment = CardComment::where('card_id', $cardId)->where('user_id', Auth::id())->findOrFail($commentId);
        $comment->delete();
        return response()->json(null, 204);
    }
}
