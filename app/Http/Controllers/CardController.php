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
        $this->authorizeWrite($boardId);
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
        $this->authorizeWrite($boardId);
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
        $this->authorizeWrite($boardId);
        Card::where('board_id', $boardId)->findOrFail($cardId)->update(['archived_at' => now()]);
        return response()->json(null, 204);
    }

    public function restore(int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        Card::where('board_id', $boardId)->findOrFail($cardId)->update(['archived_at' => null]);
        return response()->json(null, 204);
    }

    public function archived(int $boardId)
    {
        $this->authorizeBoard($boardId);
        return Card::where('board_id', $boardId)
            ->whereNotNull('archived_at')
            ->with(['tags', 'assignedUser:id,name', 'createdBy:id,name'])
            ->orderByDesc('archived_at')
            ->get();
    }

    public function reorder(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
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

    public function subtasks(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        return Card::where('board_id', $boardId)
            ->where('parent_card_id', $cardId)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->get();
    }

    public function storeSubtask(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $position = Card::where('parent_card_id', $cardId)->max('position') + 1;
        $subtask = Card::create([
            'board_id'           => $boardId,
            'section_id'         => Card::find($cardId)->section_id,
            'parent_card_id'     => $cardId,
            'name'               => $validated['name'],
            'description'        => '',
            'position'           => $position,
            'created_by_user_id' => Auth::id(),
        ]);
        return response()->json($subtask, 201);
    }

    public function updateSubtask(Request $request, int $boardId, int $cardId, int $subtaskId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate(['is_done' => ['required', 'boolean']]);
        $subtask = Card::where('board_id', $boardId)->where('parent_card_id', $cardId)->findOrFail($subtaskId);
        $subtask->update($validated);
        return response()->json($subtask);
    }
}
