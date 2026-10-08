<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Small board-scoped endpoints behind the Vortex mascot: notes left for a
 * teammate ("from Vortex") and the teammate "impression" (a phrase someone
 * keeps using in comments). Everything is limited to boards the caller can
 * see, and both users of a note must have access to the board.
 */
class VortexController extends Controller
{
    private const STOP = ['the', 'and', 'that', 'this', 'with', 'for', 'you', 'are', 'was', 'but', 'not',
        'have', 'just', 'can', 'will', 'its', "it's", 'from', 'what', 'there', 'they', 'them', 'our', 'your',
        'all', 'one', 'out', 'has', 'had', 'did', 'too', 'now', 'then', 'than', 'into', 'about', 'when', 'que',
        'para', 'com', 'uma', 'por', 'mas', 'não', 'nao', 'isso', 'esse', 'essa', 'como', 'mais', 'tem'];

    private function board(int $boardId): Board
    {
        $board = Board::find($boardId);
        abort_unless($board && $board->isAccessibleBy((int) Auth::id()), 404);

        return $board;
    }

    /** Leave a note for a teammate on this board. */
    public function storeNote(Request $request, int $boardId)
    {
        $board = $this->board($boardId);
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:200'],
        ]);
        $to = (int) $data['to_user_id'];
        abort_if($to === (int) Auth::id(), 422, 'Vortex will not pass notes to yourself.');
        abort_unless($board->isAccessibleBy($to), 422, 'They can\'t see this board.');

        // A little flood guard: at most 10 undelivered notes from one person per board.
        $pending = VortexNote::where('board_id', $boardId)->where('from_user_id', Auth::id())
            ->whereNull('delivered_at')->count();
        abort_if($pending >= 10, 429, 'Vortex is already carrying too many notes.');

        $note = VortexNote::create([
            'board_id' => $boardId,
            'from_user_id' => Auth::id(),
            'to_user_id' => $to,
            'body' => trim($data['body']),
        ]);

        return response()->json(['id' => $note->id], 201);
    }

    /** Notes waiting for the caller on this board; delivered (and marked) once. */
    public function pendingNotes(int $boardId)
    {
        $this->board($boardId);
        $notes = VortexNote::with('from:id,name')
            ->where('board_id', $boardId)
            ->where('to_user_id', Auth::id())
            ->whereNull('delivered_at')
            ->oldest()
            ->limit(5)
            ->get();
        VortexNote::whereIn('id', $notes->pluck('id'))->update(['delivered_at' => now()]);

        return response()->json($notes->map(fn ($n) => [
            'id' => $n->id,
            'from' => $n->from?->name,
            'body' => $n->body,
            'created_at' => $n->created_at,
        ])->values());
    }

    /**
     * For each teammate who commented on this board recently, the two-word phrase
     * they use most (at least twice). Fun only — derived from comments the caller
     * can already read on this board.
     */
    public function impressions(int $boardId)
    {
        $this->board($boardId);
        $cardIds = Card::where('board_id', $boardId)->pluck('id');
        $comments = CardComment::whereIn('card_id', $cardIds)
            ->where('user_id', '!=', Auth::id())
            ->latest()
            ->limit(300)
            ->get(['user_id', 'body']);

        $out = [];
        foreach ($comments->groupBy('user_id') as $userId => $rows) {
            $counts = [];
            foreach ($rows as $row) {
                $words = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(strip_tags((string) $row->body)), -1, PREG_SPLIT_NO_EMPTY);
                for ($i = 0; $i + 1 < count($words); $i++) {
                    [$a, $b] = [$words[$i], $words[$i + 1]];
                    if (mb_strlen($a) < 3 || mb_strlen($b) < 3 || in_array($a, self::STOP, true) || in_array($b, self::STOP, true)) {
                        continue;
                    }
                    $counts["$a $b"] = ($counts["$a $b"] ?? 0) + 1;
                }
            }
            arsort($counts);
            $phrase = array_key_first($counts);
            if ($phrase !== null && $counts[$phrase] >= 2) {
                $out[] = ['user_id' => (int) $userId, 'name' => User::find($userId)?->name, 'phrase' => $phrase];
            }
        }

        return response()->json($out);
    }
}
