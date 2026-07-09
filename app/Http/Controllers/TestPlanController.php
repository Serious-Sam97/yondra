<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\TestPlan;
use Illuminate\Http\Request;

class TestPlanController extends Controller
{
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);

        return response()->json([
            'plans' => TestPlan::where('board_id', $boardId)->withCount('cases')->orderBy('name')->get()
                ->map(fn ($p) => $this->serialize($p))->all(),
        ]);
    }

    // Cross-card overview: every plan with its linked cases + each case's live status,
    // for the board-level QA/plans view (re-execute a suite across a release).
    public function overview(int $boardId)
    {
        $this->authorizeBoard($boardId);
        $plans = TestPlan::where('board_id', $boardId)->orderBy('name')
            ->with(['cases.card:id,name', 'cases.runs'])->get();

        return response()->json([
            'plans' => $plans->map(function (TestPlan $plan) {
                $cases = $plan->cases->map(function ($c) {
                    $latest = $c->runs->first();

                    return [
                        'id' => $c->id,
                        'title' => $c->title,
                        'card_id' => $c->card_id,
                        'card_name' => $c->card->name ?? null,
                        'latest_status' => $c->awaiting_retest ? 'awaiting_retest' : ($latest->status ?? 'not_run'),
                    ];
                })->values()->all();

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'cases' => $cases,
                ];
            })->all(),
        ]);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeQaBoard($boardId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
        $plan = TestPlan::create(array_merge($validated, ['board_id' => $boardId]));

        return $this->broadcast($boardId, 'qa.plan.updated', $plan, 201);
    }

    public function update(Request $request, int $boardId, int $planId)
    {
        $this->authorizeQaBoard($boardId);
        $plan = TestPlan::where('board_id', $boardId)->findOrFail($planId);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);
        $plan->update($validated);

        return $this->broadcast($boardId, 'qa.plan.updated', $plan);
    }

    public function destroy(int $boardId, int $planId)
    {
        $this->authorizeQaBoard($boardId);
        $plan = TestPlan::where('board_id', $boardId)->findOrFail($planId);
        $plan->delete();
        broadcast(new BoardEvent($boardId, 'qa.plan.deleted', ['id' => $planId]));

        return response()->json(null, 204);
    }

    private function authorizeQaBoard(int $boardId): Board
    {
        $board = $this->authorizeWrite($boardId);
        if (! $board->qa_enabled) {
            abort(422, 'QA (Sentinel) is not enabled on this board.');
        }

        return $board;
    }

    private function serialize(TestPlan $p): array
    {
        return [
            'id' => $p->id,
            'board_id' => $p->board_id,
            'name' => $p->name,
            'description' => $p->description,
            'cases_count' => $p->cases_count ?? $p->cases()->count(),
        ];
    }

    private function broadcast(int $boardId, string $type, TestPlan $plan, int $status = 200)
    {
        $plan->loadCount('cases');
        $payload = $this->serialize($plan);
        broadcast(new BoardEvent($boardId, $type, $payload));

        return response()->json($payload, $status);
    }
}
