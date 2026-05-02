<?php
namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\BoardMessage;
use App\Infrastructure\Models\YondraNotification;
use Illuminate\Http\Request;
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
            'user_id'  => Auth::id(),
            'body'     => $validated['body'],
        ]);
        $message->load('user:id,name');

        // Mention detection
        preg_match_all('/@(\w+)/u', $validated['body'], $matches);
        if (!empty($matches[1])) {
            $board = \App\Infrastructure\Models\Board::with(['owner', 'sharedWith'])->find($boardId);
            $boardUsers = collect([$board->owner])->merge($board->sharedWith)->filter();
            $notified = collect();
            foreach ($matches[1] as $handle) {
                $mentioned = $boardUsers->first(fn($u) =>
                    strtolower(explode(' ', $u->name)[0]) === strtolower($handle)
                );
                if ($mentioned && $mentioned->id !== Auth::id() && !$notified->contains($mentioned->id)) {
                    YondraNotification::create([
                        'user_id'  => $mentioned->id,
                        'board_id' => $boardId,
                        'card_id'  => null,
                        'message'  => Auth::user()->name . ' mentioned you in board chat',
                    ]);
                    $notified->push($mentioned->id);
                }
            }
        }

        broadcast(new BoardEvent($boardId, 'message.created', $message->toArray()));

        return response()->json($message, 201);
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
