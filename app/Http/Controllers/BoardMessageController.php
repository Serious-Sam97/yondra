<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardMessage;
use App\Notifications\BoardChatNotification;
use App\Services\MentionService;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class BoardMessageController extends Controller
{
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);

        return BoardMessage::where('board_id', $boardId)->with('user:id,name')->oldest()->get();
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = BoardMessage::create([
            'board_id' => $boardId,
            'user_id' => Auth::id(),
            'body' => $validated['body'],
        ]);
        $message->load('user:id,name');

        $mentioned = resolve(MentionService::class)->notify(
            $boardId,
            null,
            $validated['body'],
            Auth::user()->name.' mentioned you in board chat',
        );

        broadcast(new BoardEvent($boardId, 'message.created', $message->toArray()));

        $this->notifyChatMembers($boardId, $mentioned);

        return response()->json($message, 201);
    }

    /**
     * Ping board members about new chat — but collapsed: skip anyone who already
     * has an unread chat notification for this board, so an active conversation
     * yields one bell entry per member, not one per message. Mentioned users and
     * the author are excluded (mentions are handled separately).
     *
     * @param  Collection<int,int>  $mentioned  ids already notified
     */
    private function notifyChatMembers(int $boardId, $mentioned): void
    {
        $board = Board::with(['owner:id,name', 'sharedWith:id,name'])->find($boardId);
        if (! $board) {
            return;
        }

        $members = collect([$board->owner])->merge($board->sharedWith)->filter()
            ->reject(fn ($u) => $u->id === Auth::id() || $mentioned->contains($u->id))
            ->unique('id');

        foreach ($members as $member) {
            $alreadyPinged = $member->unreadNotifications()
                ->where('type', BoardChatNotification::class)
                ->get()
                ->contains(fn ($n) => ($n->data['board_id'] ?? null) === $boardId);
            if ($alreadyPinged) {
                continue;
            }

            resolve(Notifier::class)->send($member, new BoardChatNotification(
                actorId: (int) Auth::id(),
                boardId: $boardId,
                boardName: $board->name,
            ));
        }
    }

    public function destroy(int $boardId, int $messageId)
    {
        $this->authorizeBoard($boardId);
        $message = BoardMessage::where('board_id', $boardId)->where('user_id', Auth::id())->findOrFail($messageId);
        $message->delete();
        broadcast(new BoardEvent($boardId, 'message.deleted', ['id' => $messageId]));

        return response()->json(null, 204);
    }
}
