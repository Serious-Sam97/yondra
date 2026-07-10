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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QaController extends Controller
{
    private const TYPES = ['manual', 'automated', 'performance', 'security'];

    private const RUN_STATUSES = ['passed', 'failed', 'blocked'];

    // Human Quality Gate verdicts — distinct from the run-derived status.
    private const VERDICTS = ['approved', 'rejected', 'blocked', 'awaiting_info'];

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
            // Optional per-block checklist layer (the run stays append-only).
            'items' => ['nullable', 'array'],
            'items.*.block_key' => ['sometimes', 'nullable', 'string'],
            'items.*.block_title' => ['sometimes', 'nullable', 'string'],
            'items.*.ok' => ['sometimes', 'nullable', 'boolean'],
            'items.*.bug_card_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.evidence' => ['sometimes', 'nullable', 'array'],
            'items.*.lines' => ['sometimes', 'nullable', 'array'],
        ]);

        TestRun::create(array_merge($validated, [
            'test_case_id' => $case->id,
            'board_id' => $boardId,
            'executor_user_id' => Auth::id(),
            'executed_at' => now(),
            'source' => 'manual',
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

    // Human Quality Gate parecer — set/clear the verdict, independent of run status.
    public function setVerdict(Request $request, int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);
        $validated = $request->validate([
            'verdict' => ['present', 'nullable', Rule::in(self::VERDICTS)],
        ]);
        $v = $validated['verdict'] ?? null;
        $case->update([
            'verdict' => $v,
            'verdict_by_user_id' => $v ? Auth::id() : null,
            'verdict_at' => $v ? now() : null,
        ]);

        return $this->broadcastCase($boardId, 'qa.case.updated', $case);
    }

    // Mint (or rotate) the case's CI webhook token. External pipelines POST results to it.
    public function ciToken(int $boardId, int $cardId, int $caseId)
    {
        $this->authorizeQaBoard($boardId);
        $case = TestCase::where('card_id', $cardId)->findOrFail($caseId);
        $case->update(['ci_token' => Str::random(48)]);

        return $this->broadcastCase($boardId, 'qa.case.updated', $case);
    }

    // Public CI ingress — a pipeline posts a run keyed only by the case's ci_token.
    // No auth: the unguessable token IS the credential (same pattern as GitHub webhooks).
    public function ciHook(Request $request, string $token)
    {
        $case = TestCase::where('ci_token', $token)->firstOrFail();
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::RUN_STATUSES)],
            'environment' => ['nullable', 'string', 'max:255'],
            'logs' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
        ]);

        TestRun::create([
            'test_case_id' => $case->id,
            'board_id' => $case->board_id,
            'status' => $validated['status'],
            'environment' => $validated['environment'] ?? 'CI',
            'logs' => $validated['logs'] ?? null,
            'items' => $validated['items'] ?? null,
            'source' => 'ci',
            'executor_user_id' => null,
            'executed_at' => now(),
        ]);
        if ($case->awaiting_retest) {
            $case->update(['awaiting_retest' => false]);
        }

        broadcast(new BoardEvent($case->board_id, 'qa.run.created', $case->fresh()->toSnapshot()));

        return response()->json(['ok' => true], 201);
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
            // Timeline of blocks. A block is either a library ref ({step_id}) or a
            // case-local block ({local_key, title, lines}); both may carry evidence.
            'step_refs' => ['sometimes', 'nullable', 'array'],
            'step_refs.*.step_id' => ['sometimes', 'nullable', 'integer'],
            'step_refs.*.overrides' => ['sometimes', 'nullable', 'string'],
            'step_refs.*.scope' => ['sometimes', 'nullable', Rule::in(['local', 'global'])],
            'step_refs.*.local_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'step_refs.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'step_refs.*.lines' => ['sometimes', 'nullable', 'array'],
            'step_refs.*.lines.*.keyword' => ['sometimes', 'string', 'max:16'],
            'step_refs.*.lines.*.text' => ['sometimes', 'nullable', 'string'],
            'step_refs.*.evidence' => ['sometimes', 'nullable', 'array'],
            'step_refs.*.evidence.*.url' => ['sometimes', 'string'],
            'step_refs.*.evidence.*.kind' => ['sometimes', 'nullable', 'string'],
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
