<?php
namespace App\Http\Controllers;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardChecklistItem;
use Illuminate\Http\Request;

class CardChecklistController extends Controller
{
    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $position = CardChecklistItem::where('card_id', $cardId)->max('position') + 1;
        $item = CardChecklistItem::create(['card_id' => $cardId, 'text' => $validated['text'], 'position' => $position]);
        return response()->json($item, 201);
    }

    public function update(Request $request, int $boardId, int $cardId, int $itemId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'text'    => ['sometimes', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
        ]);
        $item = CardChecklistItem::where('card_id', $cardId)->findOrFail($itemId);
        $item->update($validated);
        return response()->json($item);
    }

    public function destroy(int $boardId, int $cardId, int $itemId)
    {
        $this->authorizeWrite($boardId);
        CardChecklistItem::where('card_id', $cardId)->findOrFail($itemId)->delete();
        return response()->json(null, 204);
    }
}
