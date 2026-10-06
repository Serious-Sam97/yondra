<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardActivity;
use App\Services\CardHistory;

class CardHistoryController extends Controller
{
    /** A card's History tab: newest first, 30 per page (`?page=N`, read `next_page_url`). */
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = Card::where('board_id', $boardId)->with('createdBy:id,name')->findOrFail($cardId);

        $page = CardActivity::where('card_id', $card->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->simplePaginate(30);

        $data = collect($page->items())->map(fn (CardActivity $a) => CardHistory::serialize($a))->all();

        // Cards older than this feature have no card.created row — close their history
        // with one synthesized from the card itself so it never starts mid-story.
        if (! $page->hasMorePages() && ! CardActivity::where('card_id', $card->id)->where('type', 'card.created')->exists()) {
            $data[] = [
                'id' => null,
                'card_id' => $card->id,
                'type' => 'card.created',
                'source' => 'user',
                'changes' => (object) [],
                'meta' => (object) ['name' => $card->name, 'synthetic' => true],
                'user' => $card->createdBy ? ['id' => $card->createdBy->id, 'name' => $card->createdBy->name] : null,
                'created_at' => $card->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $data,
            'current_page' => $page->currentPage(),
            'next_page_url' => $page->nextPageUrl(),
        ]);
    }
}
