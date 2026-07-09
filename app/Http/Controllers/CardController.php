<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'section_id'       => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tag_ids'          => ['sometimes', 'array'],
            'tag_ids.*'        => ['integer', Rule::exists('tags', 'id')->where('board_id', $boardId)],
            'name'             => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'priority'         => ['nullable', 'in:low,medium,high'],
            'value'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'story_points'     => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sprint_id'        => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('board_id', $boardId)],
        ]);

        $card = $this->cardService->create(array_merge($validated, ['board_id' => $boardId]));

        BoardActivity::create([
            'board_id'    => $boardId,
            'user_id'     => Auth::id(),
            'type'        => 'card_created',
            'description' => Auth::user()->name . ' created card "' . $validated['name'] . '"',
        ]);

        broadcast(new BoardEvent($boardId, 'card.created', $card));

        return response()->json($card, 201);
    }

    public function update(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_id'       => ['sometimes', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tag_ids'          => ['sometimes', 'array'],
            'tag_ids.*'        => ['integer', Rule::exists('tags', 'id')->where('board_id', $boardId)],
            'name'             => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'priority'         => ['nullable', 'in:low,medium,high'],
            'position'         => ['sometimes', 'integer'],
            'value'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'story_points'     => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sprint_id'        => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('board_id', $boardId)],
        ]);

        $card = $this->cardService->edit(array_merge($validated, [
            'id'       => $cardId,
            'board_id' => $boardId,
        ]));

        broadcast(new BoardEvent($boardId, 'card.updated', $card));

        return response()->json($card);
    }

    public function destroy(int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        Card::where('board_id', $boardId)->findOrFail($cardId)->update(['archived_at' => now()]);
        broadcast(new BoardEvent($boardId, 'card.deleted', ['id' => $cardId]));
        return response()->json(null, 204);
    }

    public function restore(int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = Card::where('board_id', $boardId)->findOrFail($cardId);
        $card->update(['archived_at' => null]);
        $card->load(['tags', 'assignedUser:id,name', 'createdBy:id,name', 'checklistItems']);
        broadcast(new BoardEvent($boardId, 'card.restored', $card->toArray()));
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
            'section_id'    => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
        ]);

        DB::transaction(function () use ($validated, $boardId) {
            $targetSection = $validated['section_id'];
            // A card is "done" once it enters the board's configured done/won column
            // (falls back to a "Done"-named column) — same rule the card-edit path uses,
            // so dragging there sets done_at (drives CRM won/sprint reports/metrics).
            $board = \App\Infrastructure\Models\Board::find($boardId);
            $targetSectionModel = \App\Infrastructure\Models\Section::find($targetSection);
            $targetIsDone = $board && $targetSectionModel && $board->marksDone($targetSectionModel);
            foreach ($validated['ordered_ids'] as $position => $cardId) {
                $card = Card::where('board_id', $boardId)->where('id', $cardId)->first();
                if (!$card) continue;
                // Reset the SLA-aging clock only for cards that actually crossed into
                // this column; a pure reorder within the same column keeps its age.
                $movedColumn = $card->section_id !== $targetSection;
                $doneAt = $card->done_at;
                if ($movedColumn) {
                    $doneAt = $targetIsDone ? ($doneAt ?? now()) : null;
                }
                $card->update([
                    'position'           => $position,
                    'section_id'         => $targetSection,
                    'section_entered_at' => $movedColumn ? now() : $card->section_entered_at,
                    'done_at'            => $doneAt,
                ]);
            }
        });

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
        $parent = $this->boardCard($boardId, $cardId);
        $position = Card::where('parent_card_id', $parent->id)->max('position') + 1;
        $subtask = Card::create([
            'board_id'           => $boardId,
            'section_id'         => $parent->section_id,
            'parent_card_id'     => $parent->id,
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
