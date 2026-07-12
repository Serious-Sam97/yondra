<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\CardChecklistItem;
use Illuminate\Http\Request;

class CardChecklistController extends Controller
{
    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $position = CardChecklistItem::where('card_id', $card->id)->max('position') + 1;
        $item = CardChecklistItem::create(['card_id' => $card->id, 'text' => $validated['text'], 'position' => $position]);

        return response()->json($item, 201);
    }

    public function update(Request $request, int $boardId, int $cardId, int $itemId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate([
            'text' => ['sometimes', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
        ]);
        $item = CardChecklistItem::where('card_id', $card->id)->findOrFail($itemId);
        $item->update($validated);

        return response()->json($item);
    }

    public function destroy(int $boardId, int $cardId, int $itemId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);
        CardChecklistItem::where('card_id', $card->id)->findOrFail($itemId)->delete();

        return response()->json(null, 204);
    }
}
