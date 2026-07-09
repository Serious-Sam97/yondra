<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\ReusableStep;
use Illuminate\Http\Request;

class StepController extends Controller
{
    // The global reusable-step library for a board.
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);

        return response()->json([
            'steps' => ReusableStep::where('board_id', $boardId)->orderBy('title')->get()
                ->map(fn ($s) => $this->serialize($s))->all(),
        ]);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeQaBoard($boardId);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);
        $step = ReusableStep::create(array_merge($validated, ['board_id' => $boardId]));

        return $this->broadcast($boardId, 'qa.step.updated', $step, 201);
    }

    // Editing a step propagates to every test case that references it (via realtime).
    public function update(Request $request, int $boardId, int $stepId)
    {
        $this->authorizeQaBoard($boardId);
        $step = ReusableStep::where('board_id', $boardId)->findOrFail($stepId);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'],
        ]);
        $step->update($validated);

        return $this->broadcast($boardId, 'qa.step.updated', $step);
    }

    public function destroy(int $boardId, int $stepId)
    {
        $this->authorizeQaBoard($boardId);
        $step = ReusableStep::where('board_id', $boardId)->findOrFail($stepId);
        $step->delete();
        broadcast(new BoardEvent($boardId, 'qa.step.deleted', ['id' => $stepId]));

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

    private function serialize(ReusableStep $s): array
    {
        return ['id' => $s->id, 'board_id' => $s->board_id, 'title' => $s->title, 'content' => $s->content];
    }

    private function broadcast(int $boardId, string $type, ReusableStep $step, int $status = 200)
    {
        $payload = $this->serialize($step);
        broadcast(new BoardEvent($boardId, $type, $payload));

        return response()->json($payload, $status);
    }
}
