<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\TestRun;
use App\Infrastructure\Models\User;
use App\Notifications\CardAssignedNotification;
use App\Notifications\CardStatusNotification;
use App\Services\CardService;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
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

        BoardActivity::create([
            'board_id' => $boardId,
            'user_id' => Auth::id(),
            'type' => 'card_created',
            'description' => Auth::user()->name.' created card "'.$validated['name'].'"',
        ]);

        broadcast(new BoardEvent($boardId, 'card.created', $card));

        return response()->json($card, 201);
    }

    public function update(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
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

        // Capture prior state so we only notify on an actual (re)assignment, and can
        // re-arm the due-date reminder when the due date changes.
        $existing = Card::where('board_id', $boardId)->where('id', $cardId)->first(['assigned_user_id', 'due_date']);
        $previousAssignee = $existing?->assigned_user_id;

        $card = $this->cardService->edit(array_merge($validated, [
            'id' => $cardId,
            'board_id' => $boardId,
        ]));

        broadcast(new BoardEvent($boardId, 'card.updated', $card));

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
                    $cardName = is_array($card) ? ($card['name'] ?? '') : ($card->name ?? '');
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
        $blocking = [];
        foreach ($enteringIds as $cid) {
            $cases = $this->qaBlockingCases((int) $cid);
            if ($cases) {
                $blocking[] = ['card_id' => (int) $cid, 'cases' => $cases];
            }
        }
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

        // Fan out each moved/repositioned card so other clients converge live. Reuses the
        // 'card.updated' path (merges section_id/position/done_at) — no new event type.
        foreach ($updatedCards as $card) {
            broadcast(new BoardEvent($boardId, 'card.updated', $card->toArray()));
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

    // Test cases on a card whose latest run failed, or that were never run — these
    // block the QA quality gate on a move to "Done".
    private function qaBlockingCases(int $cardId): array
    {
        $blocking = [];
        $cases = TestCase::where('card_id', $cardId)->get(['id', 'title']);
        foreach ($cases as $c) {
            $latest = TestRun::where('test_case_id', $c->id)
                ->orderByDesc('executed_at')->orderByDesc('id')->first();
            if (! $latest || $latest->status === 'failed') {
                $blocking[] = ['id' => $c->id, 'title' => $c->title, 'status' => $latest?->status ?? 'not_run'];
            }
        }

        return $blocking;
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
            'board_id' => $boardId,
            'section_id' => $parent->section_id,
            'parent_card_id' => $parent->id,
            'name' => $validated['name'],
            'description' => '',
            'position' => $position,
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
