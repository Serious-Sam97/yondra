<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    public CardService $cardService;

    public function __construct()
    {
        $this->cardService = resolve(CardService::class);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'section_id'       => ['required', 'integer'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tag_ids'          => ['sometimes', 'array'],
            'tag_ids.*'        => ['integer', 'exists:tags,id'],
            'name'             => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'priority'         => ['nullable', 'in:low,medium,high'],
        ]);

        $card = $this->cardService->create(array_merge($validated, ['board_id' => $boardId]));

        BoardActivity::create([
            'board_id'    => $boardId,
            'user_id'     => Auth::id(),
            'type'        => 'card_created',
            'description' => Auth::user()->name . ' created card "' . $validated['name'] . '"',
        ]);

        return response()->json($card, 201);
    }

    public function update(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'section_id'       => ['sometimes', 'integer'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tag_ids'          => ['sometimes', 'array'],
            'tag_ids.*'        => ['integer', 'exists:tags,id'],
            'name'             => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'priority'         => ['nullable', 'in:low,medium,high'],
            'position'         => ['sometimes', 'integer'],
        ]);

        $card = $this->cardService->edit(array_merge($validated, [
            'id'       => $cardId,
            'board_id' => $boardId,
        ]));

        return response()->json($card);
    }

    public function destroy(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        Card::where('board_id', $boardId)->findOrFail($cardId)->delete();
        return response()->json(null, 204);
    }

    public function reorder(Request $request, int $boardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'ordered_ids'   => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
            'section_id'    => ['required', 'integer'],
        ]);

        foreach ($validated['ordered_ids'] as $position => $cardId) {
            Card::where('board_id', $boardId)->where('id', $cardId)
                ->update(['position' => $position, 'section_id' => $validated['section_id']]);
        }

        return response()->json(['ok' => true]);
    }
}
