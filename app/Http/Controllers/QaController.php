<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\TestRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class QaController extends Controller
{
    private const TYPES = ['manual', 'automated', 'performance', 'security'];

    private const RUN_STATUSES = ['passed', 'failed', 'blocked'];

    // All test cases on a card, each with its runs (newest first).
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        $cases = TestCase::where('card_id', $cardId)
            ->orderBy('position')->orderBy('id')
            ->with(['planner:id,name', 'editor:id,name', 'runs.executor:id,name', 'plans:id'])
            ->get();

        return response()->json(['cases' => $cases->map(fn ($c) => $this->serializeCase($c))->all()]);
    }

    public function storeCase(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeQaBoard($boardId);
        $this->boardCard($boardId, $cardId);
        $validated = $this->validateCase($request);

        $planIds = $validated['test_plan_ids'] ?? null;
        unset($validated['test_plan_ids']);

        $position = TestCase::where('card_id', $cardId)->max('position') + 1;
        $case = TestCase::create(array_merge($validated, [
            'board_id' => $boardId,
            'card_id' => $cardId,
            'position' => $position,
            'version' => 1,
            'edited_by_user_id' => Auth::id(),
        ]));
        if ($planIds !== null) {
            $case->plans()->sync($planIds);
        }

        return $this->broadcastCase($boardId, 'qa.case.updated', $case, 201);
    }

    public function updateCase(Request $request, int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);
        $validated = $this->validateCase($request, false);
        $planIds = $validated['test_plan_ids'] ?? null;
        unset($validated['test_plan_ids']);

        // Each save bumps the version and records the editor (lightweight audit).
        $case->update(array_merge($validated, [
            'version' => $case->version + 1,
            'edited_by_user_id' => Auth::id(),
        ]));
        if ($planIds !== null) {
            $case->plans()->sync($planIds);
        }

        return $this->broadcastCase($boardId, 'qa.case.updated', $case);
    }

    public function destroyCase(int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);
        $case->delete();

        broadcast(new BoardEvent($boardId, 'qa.case.deleted', ['card_id' => $cardId, 'id' => $caseId]));

        return response()->json(null, 204);
    }

    // Append a run (report) to a case — never edits an existing run.
    public function storeRun(Request $request, int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::RUN_STATUSES)],
            'environment' => ['nullable', 'string', 'max:255'],
            'device' => ['nullable', 'string', 'max:255'],
            'logs' => ['nullable', 'string'],
            'evidence' => ['nullable', 'array'],
            'evidence.*.url' => ['required_with:evidence', 'string'],
            'evidence.*.kind' => ['nullable', 'string'],
        ]);

        TestRun::create(array_merge($validated, [
            'test_case_id' => $case->id,
            'board_id' => $boardId,
            'executor_user_id' => Auth::id(),
            'executed_at' => now(),
        ]));

        // Logging a run IS the retest — clear the awaiting-retest flag.
        if ($case->awaiting_retest) {
            $case->update(['awaiting_retest' => false]);
        }

        return $this->broadcastCase($boardId, 'qa.run.created', $case->fresh(), 201);
    }

    // A failed test can spawn a Bug card on the board, coupled to the case. Resolving
    // that bug (moving it to Done) flips the case to awaiting-retest via CardController.
    public function linkBug(int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);

        $section = Section::where('board_id', $boardId)->orderBy('order')->first();
        $bug = Card::create([
            'board_id' => $boardId,
            'section_id' => $section->id,
            'name' => 'Bug: '.$case->title,
            'description' => 'Auto-generated from failed test case "'.$case->title.'" (#'.$case->id.').',
            'priority' => 'high',
            'position' => (Card::where('section_id', $section->id)->max('position') ?? -1) + 1,
            'created_by_user_id' => Auth::id(),
        ]);
        $case->update(['bug_card_id' => $bug->id, 'awaiting_retest' => false]);

        // Show the new bug on the board live, then push the coupled case.
        broadcast(new BoardEvent($boardId, 'card.created', $bug->fresh()->toArray()));

        return $this->broadcastCase($boardId, 'qa.case.updated', $case);
    }

    // --- helpers ---

    private function authorizeQaBoard(int $boardId): Board
    {
        $board = $this->authorizeWrite($boardId);
        if (! $board->qa_enabled) {
            abort(422, 'QA (Sentinel) is not enabled on this board.');
        }

        return $board;
    }

    private function validateCase(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'target_env' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gherkin' => ['sometimes', 'nullable', 'string'],
            'preconditions' => ['sometimes', 'nullable', 'string'],
            'postconditions' => ['sometimes', 'nullable', 'string'],
            'step_refs' => ['sometimes', 'nullable', 'array'],
            'step_refs.*.step_id' => ['required_with:step_refs', 'integer'],
            'step_refs.*.overrides' => ['sometimes', 'nullable', 'string'],
            'data_matrix' => ['sometimes', 'nullable', 'array'],
            'data_matrix.columns' => ['sometimes', 'array'],
            'data_matrix.columns.*' => ['string'],
            'data_matrix.rows' => ['sometimes', 'array'],
            'test_plan_ids' => ['sometimes', 'array'],
            'test_plan_ids.*' => ['integer'],
        ]);
    }

    private function broadcastCase(int $boardId, string $type, TestCase $case, int $status = 200)
    {
        $case->load(['planner:id,name', 'editor:id,name', 'runs.executor:id,name', 'plans:id']);
        $payload = $this->serializeCase($case);
        broadcast(new BoardEvent($boardId, $type, $payload));

        return response()->json($payload, $status);
    }

    private function serializeCase(TestCase $case): array
    {
        return $case->toSnapshot();
    }
}
