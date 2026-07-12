<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Http\Resources\CardResource;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\User;
use App\Notifications\CardAssignedNotification;
use App\Notifications\CardStatusNotification;
use App\Rules\AssignableBoardMember;
use App\Services\CardService;
use App\Services\Notifier;
use App\Services\QualityGate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CardController extends Controller
{
    public CardService $cardService;

    public QualityGate $qualityGate;

    public function __construct()
    {
        $this->cardService = resolve(CardService::class);
        $this->qualityGate = resolve(QualityGate::class);
    }

    public function store(Request $request, int $boardId)
    {
        $board = $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id', new AssignableBoardMember($board)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('board_id', $boardId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sprint_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('board_id', $boardId)],
        ]);

        $card = $this->cardService->create(array_merge($validated, ['board_id' => $boardId]));
        $payload = CardResource::withTicketKey($card)->resolve();

        BoardActivity::create([
            'board_id' => $boardId,
            'user_id' => Auth::id(),
            'type' => 'card_created',
            // No actor name here — consumers render the actor separately, and
            // embedding it produced "Sam Sam created card …" in the dashboard feed.
            'description' => 'created card "'.$validated['name'].'"',
        ]);

        broadcast(new BoardEvent($boardId, 'card.created', $payload));

        return response()->json($payload, 201);
    }

    public function update(Request $request, int $boardId, int $cardId)
    {
        $board = $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id', new AssignableBoardMember($board)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('board_id', $boardId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'position' => ['sometimes', 'integer'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sprint_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('board_id', $boardId)],
        ]);

        // Quality gate guards every path into the done column, not just drag reorder —
        // otherwise a plain update with section_id would bypass it.
        if (array_key_exists('section_id', $validated)) {
            $blocking = $this->qualityGate->blocking(
                Board::findOrFail($boardId),
                [$cardId],
                Section::findOrFail($validated['section_id']),
            );
            if (! empty($blocking)) {
                return response()->json([
                    'message' => 'Quality gate: card has tests that failed or were not run.',
                    'blocking' => $blocking,
                ], 422);
            }
        }

        // Capture prior state so we only notify on an actual (re)assignment, and can
        // re-arm the due-date reminder when the due date changes.
        $existing = Card::where('board_id', $boardId)->where('id', $cardId)->first(['assigned_user_id', 'due_date']);
        $previousAssignee = $existing?->assigned_user_id;

        $card = $this->cardService->edit(array_merge($validated, [
            'id' => $cardId,
            'board_id' => $boardId,
        ]));
        $payload = CardResource::withTicketKey($card)->resolve();

        broadcast(new BoardEvent($boardId, 'card.updated', $payload));

        // A changed due date re-arms the reminder so the scheduler can fire again.
        if (array_key_exists('due_date', $validated)) {
            $prev = $existing?->due_date ? $existing->due_date->format('Y-m-d') : null;
            $next = $validated['due_date'] ? Carbon::parse($validated['due_date'])->format('Y-m-d') : null;
            if ($prev !== $next) {
                Card::where('board_id', $boardId)->where('id', $cardId)->update(['due_reminder_sent_at' => null]);
            }
        }

        if (array_key_exists('assigned_user_id', $validated)) {
            $newAssignee = $validated['assigned_user_id'];
            if ($newAssignee && (int) $newAssignee !== (int) $previousAssignee && (int) $newAssignee !== (int) Auth::id()) {
                if ($assignee = User::find($newAssignee)) {
                    $cardName = (string) ($card->name ?? '');
                    resolve(Notifier::class)->send($assignee, new CardAssignedNotification(
                        actorId: (int) Auth::id(),
                        actorName: Auth::user()->name,
                        boardId: $boardId,
                        cardId: $cardId,
                        cardName: $cardName,
                    ));
                }
            }
        }

        return response()->json($payload);
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

        // Cards archived by a section delete (or whose section has since been removed)
        // no longer have a live column. Re-home them into the board's first section so
        // the restored card is actually visible — the least surprising landing spot.
        // Edge: a board with zero sections leaves section_id NULL; the card stays
        // restorable data-wise and appears once a section exists and it is re-homed.
        $update = ['archived_at' => null];
        if ($card->section_id === null || ! Section::where('board_id', $boardId)->whereKey($card->section_id)->exists()) {
            $update['section_id'] = Section::where('board_id', $boardId)->orderBy('order')->value('id');
        }
        $card->update($update);
        $card->load(['tags', 'assignedUser:id,name', 'createdBy:id,name', 'checklistItems']);
        broadcast(new BoardEvent($boardId, 'card.restored', $card->toArray()));

        return response()->json(null, 204);
    }

    /**
     * Archived cards, most recently archived first, 25 per page (`?page=N`).
     * Paginated resource collections keep their `data` + `links` + `meta`
     * envelope even with JsonResource::withoutWrapping() — clients read
     * `links.next` (null on the last page) to know whether to fetch more.
     * `id` tie-breaks identical archived_at stamps (bulk section deletes).
     */
    public function archived(int $boardId)
    {
        $this->authorizeBoard($boardId);

        return CardResource::collection(
            Card::where('board_id', $boardId)
                ->whereNotNull('archived_at')
                ->with(['tags', 'assignedUser:id,name', 'createdBy:id,name'])
                ->orderByDesc('archived_at')
                ->orderByDesc('id')
                ->simplePaginate(25)
        );
    }

    public function reorder(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
        ]);

        $targetSection = $validated['section_id'];
        // A card is "done" once it enters the board's configured done/won column
        // (falls back to a "Done"-named column) — drives done_at + the QA quality gate.
        $board = Board::find($boardId);
        $targetSectionModel = Section::find($targetSection);
        $targetIsDone = $board && $targetSectionModel && $board->marksDone($targetSectionModel);

        // Cards entering "Done" — used by the quality gate and the bug resolution below.
        $enteringIds = ($board && $board->qa_enabled && $targetIsDone)
            ? Card::where('board_id', $boardId)
                ->whereIn('id', $validated['ordered_ids'])
                ->where('section_id', '!=', $targetSection)
                ->pluck('id')->all()
            : [];

        // Quality gate: block a card from entering "Done" while it has tests that failed
        // or were never run. Server-authoritative — the client reverts the optimistic drag.
        $blocking = ($board && $targetSectionModel)
            ? $this->qualityGate->blocking($board, $validated['ordered_ids'], $targetSectionModel)
            : [];
        if (! empty($blocking)) {
            return response()->json([
                'message' => 'Quality gate: card has tests that failed or were not run.',
                'blocking' => $blocking,
            ], 422);
        }

        $updatedCards = [];
        $movedCardIds = [];
        DB::transaction(function () use ($validated, $boardId, $targetSection, $targetIsDone, &$updatedCards, &$movedCardIds) {
            foreach ($validated['ordered_ids'] as $position => $cardId) {
                $card = Card::where('board_id', $boardId)->where('id', $cardId)->first();
                if (! $card) {
                    continue;
                }
                // Reset the SLA-aging clock only for cards that actually crossed into
                // this column; a pure reorder within the same column keeps its age.
                $movedColumn = $card->section_id !== $targetSection;
                $doneAt = $card->done_at;
                if ($movedColumn) {
                    $doneAt = $targetIsDone ? ($doneAt ?? now()) : null;
                    $movedCardIds[] = (int) $card->id;
                }
                $card->update([
                    'position' => $position,
                    'section_id' => $targetSection,
                    'section_entered_at' => $movedColumn ? now() : $card->section_entered_at,
                    'done_at' => $doneAt,
                ]);
                $updatedCards[] = $card->fresh();
            }
        });

        // ONE batched broadcast for the whole reorder. BoardEvent is ShouldBroadcastNow,
        // so the previous per-card loop fired N blocking Reverb pushes inside the request
        // (50-card column = 50 pushes). Each entry carries exactly the fields the client
        // merges to converge (see applyBoardEvent 'cards.reordered' in useBoardRealtime).
        // Sliced from toArray() so serialization matches the old card.updated payloads.
        // Stale clients (older Capacitor builds) that only listen for 'card.updated'
        // won't converge on reorder until refreshed — acceptable, single-developer app.
        if (! empty($updatedCards)) {
            $entries = array_map(
                fn ($card) => array_intersect_key(
                    $card->toArray(),
                    array_flip(['id', 'section_id', 'position', 'done_at', 'section_entered_at']),
                ),
                $updatedCards,
            );
            broadcast(new BoardEvent($boardId, 'cards.reordered', ['cards' => array_values($entries)]));
        }

        // Notify each moved card's assignee (unless they did the move) that it
        // changed column / was completed.
        if (! empty($movedCardIds)) {
            $sectionName = $targetSectionModel->name ?? '';
            foreach ($updatedCards as $card) {
                if (! in_array((int) $card->id, $movedCardIds, true)) {
                    continue;
                }
                if (! $card->assigned_user_id || (int) $card->assigned_user_id === (int) Auth::id()) {
                    continue;
                }
                if ($assignee = User::find($card->assigned_user_id)) {
                    resolve(Notifier::class)->send($assignee, new CardStatusNotification(
                        actorId: (int) Auth::id(),
                        actorName: Auth::user()->name,
                        boardId: $boardId,
                        cardId: (int) $card->id,
                        cardName: (string) $card->name,
                        sectionName: $sectionName,
                        done: (bool) $targetIsDone,
                    ));
                }
            }
        }

        // Bug resolution: a linked bug card entering "Done" flips its coupled test case to
        // "awaiting retest" (bidirectional bug ↔ test coupling).
        if (! empty($enteringIds)) {
            $coupled = TestCase::whereIn('bug_card_id', $enteringIds)->get();
            foreach ($coupled as $case) {
                if (! $case->awaiting_retest) {
                    $case->update(['awaiting_retest' => true]);
                    broadcast(new BoardEvent($boardId, 'qa.case.updated', $case->toSnapshot()));
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    public function subtasks(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);

        return CardResource::collection(
            Card::where('board_id', $boardId)
                ->where('parent_card_id', $cardId)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->get()
        );
    }

    public function storeSubtask(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $parent = $this->boardCard($boardId, $cardId);
        $position = Card::where('parent_card_id', $parent->id)->max('position') + 1;
        $subtask = Card::create([
            'board_id' => $boardId,
            'section_id' => $parent->section_id,
            'parent_card_id' => $parent->id,
            'name' => $validated['name'],
            'description' => '',
            'position' => $position,
            'created_by_user_id' => Auth::id(),
        ]);

        return (new CardResource($subtask))->response()->setStatusCode(201);
    }

    public function updateSubtask(Request $request, int $boardId, int $cardId, int $subtaskId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate(['is_done' => ['required', 'boolean']]);
        $subtask = Card::where('board_id', $boardId)->where('parent_card_id', $cardId)->findOrFail($subtaskId);
        $subtask->update($validated);

        return new CardResource($subtask);
    }
}
