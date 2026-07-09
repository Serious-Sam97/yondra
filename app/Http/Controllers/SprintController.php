<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SprintController extends Controller
{
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);
        return Sprint::where('board_id', $boardId)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'future' THEN 1 ELSE 2 END")
            ->orderBy('start_date')
            ->get();
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'goal'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        // Default the schedule: a sprint starts the day after the last one ends (never
        // overlapping), and runs two weeks unless the caller supplies dates.
        if (empty($validated['start_date'])) {
            $lastEnd = Sprint::where('board_id', $boardId)->whereNotNull('end_date')->max('end_date');
            $validated['start_date'] = $lastEnd
                ? Carbon::parse($lastEnd)->addDay()->toDateString()
                : now()->toDateString();
        }
        if (empty($validated['end_date'])) {
            $validated['end_date'] = Carbon::parse($validated['start_date'])->addWeeks(2)->toDateString();
        }

        // New sprints are always created in the backlog (future); use start() to activate.
        $sprint = Sprint::create(array_merge($validated, ['board_id' => $boardId, 'status' => 'future']));

        // Keep the whole schedule non-overlapping (in case supplied dates collide).
        $shifted = $this->cascadeDates($boardId);
        broadcast(new BoardEvent($boardId, 'sprint.created', $sprint->fresh()->toArray()));
        foreach ($shifted as $s) broadcast(new BoardEvent($boardId, 'sprint.updated', $s->toArray()));
        return response()->json($sprint->fresh(), 201);
    }

    public function update(Request $request, int $boardId, int $sprintId)
    {
        $this->authorizeWrite($boardId);
        $sprint = Sprint::where('board_id', $boardId)->findOrFail($sprintId);
        $validated = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'goal'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $sprint->update($validated);

        // Changing dates can push later sprints — cascade so none overlaps the previous one.
        $shifted = $this->cascadeDates($boardId);
        broadcast(new BoardEvent($boardId, 'sprint.updated', $sprint->fresh()->toArray()));
        foreach ($shifted as $s) {
            if ($s->id !== $sprint->id) broadcast(new BoardEvent($boardId, 'sprint.updated', $s->toArray()));
        }
        // Return the full ordered list so the caller reflects every cascade shift.
        return response()->json(
            Sprint::where('board_id', $boardId)
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'future' THEN 1 ELSE 2 END")
                ->orderBy('start_date')->get()
        );
    }

    /** Start a future sprint: freeze its committed scope and make it the (single) active sprint. */
    public function start(Request $request, int $boardId, int $sprintId)
    {
        $this->authorizeWrite($boardId);
        $sprint = Sprint::where('board_id', $boardId)->findOrFail($sprintId);

        if (Sprint::where('board_id', $boardId)->where('status', 'active')->where('id', '!=', $sprintId)->exists()) {
            throw ValidationException::withMessages(['sprint' => 'Another sprint is already active. Complete it first.']);
        }

        $validated = $request->validate([
            'goal'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $cards = Card::where('sprint_id', $sprintId)->whereNull('archived_at')->get();

        $sprint->update(array_merge($validated, [
            'status'           => 'active',
            'is_active'        => true,
            'started_at'       => now(),
            'start_date'       => $validated['start_date'] ?? $sprint->start_date ?? now()->toDateString(),
            'committed_points' => (int) $cards->sum('story_points'),
            'committed_count'  => $cards->count(),
        ]));

        broadcast(new BoardEvent($boardId, 'sprint.updated', $sprint->fresh()->toArray()));
        return response()->json($sprint->fresh());
    }

    /** Complete the active sprint: freeze results, snapshot the ticket set, rehome incomplete tickets. */
    public function complete(Request $request, int $boardId, int $sprintId)
    {
        $this->authorizeWrite($boardId);
        $sprint = Sprint::where('board_id', $boardId)->findOrFail($sprintId);

        // move_to is 'backlog', 'new', or the (string) id of an existing future sprint.
        $validated = $request->validate([
            'move_to'         => ['required', 'string'],
            'new_sprint_name' => ['required_if:move_to,new', 'string', 'max:255'],
        ]);
        $moveTo = $validated['move_to'];

        $doneSectionIds = $this->doneSectionIds($boardId);

        [$sprint, $movedIds, $targetSprintId, $newSprint] = DB::transaction(function () use ($sprint, $boardId, $sprintId, $moveTo, $validated, $doneSectionIds) {
            $cards = Card::where('sprint_id', $sprintId)->whereNull('archived_at')->with('assignedUser:id,name')->get();
            // Backfill done_at for anything sitting in a Done column but not yet stamped
            // (e.g. dragged before done_at tracking) so completed metrics are accurate.
            foreach ($cards as $c) {
                if ($c->done_at === null && in_array($c->section_id, $doneSectionIds, true)) {
                    $c->done_at = now();
                    $c->save();
                }
            }
            $done = $cards->filter(fn($c) => $c->done_at !== null);

            // Resolve where the incomplete tickets land.
            $targetSprintId = null; // null → backlog
            $newSprint = null;
            if ($moveTo === 'new') {
                $newSprint = Sprint::create([
                    'board_id' => $boardId,
                    'name'     => $validated['new_sprint_name'],
                    'status'   => 'future',
                ]);
                $targetSprintId = $newSprint->id;
            } elseif ($moveTo !== 'backlog') {
                // An existing future sprint id was passed as move_to.
                $target = Sprint::where('board_id', $boardId)->where('status', 'future')->find((int) $moveTo);
                $targetSprintId = $target?->id;
            }

            $movedIds = [];
            foreach ($cards as $card) {
                if ($card->done_at === null) {
                    $card->update(['sprint_id' => $targetSprintId]);
                    $movedIds[] = $card->id;
                }
            }

            $sprint->update([
                'status'           => 'completed',
                'is_active'        => false,
                'completed_at'     => now(),
                'completed_points' => (int) $done->sum('story_points'),
                'completed_count'  => $done->count(),
                'report_snapshot'  => $cards->map(fn($c) => [
                    'id'            => $c->id,
                    'name'          => $c->name,
                    'points'        => $c->story_points,
                    'done_at'       => optional($c->done_at)->toIso8601String(),
                    'assigned_user' => $c->assignedUser ? ['id' => $c->assignedUser->id, 'name' => $c->assignedUser->name] : null,
                ])->values()->all(),
            ]);

            return [$sprint, $movedIds, $targetSprintId, $newSprint];
        });

        broadcast(new BoardEvent($boardId, 'sprint.updated', $sprint->fresh()->toArray()));
        if ($newSprint) {
            broadcast(new BoardEvent($boardId, 'sprint.created', $newSprint->toArray()));
        }
        // Nudge clients to re-read cards whose sprint assignment changed.
        foreach ($movedIds as $cardId) {
            $card = Card::with(['tags', 'assignedUser:id,name', 'createdBy:id,name'])->find($cardId);
            if ($card) broadcast(new BoardEvent($boardId, 'card.updated', $card->toArray()));
        }

        return response()->json([
            'sprint'           => $sprint->fresh(),
            'new_sprint'       => $newSprint,
            'target_sprint_id' => $targetSprintId,
            'moved_ids'        => $movedIds,
        ]);
    }

    /** Sprint report: committed/completed split + a per-day burndown series. */
    public function report(int $boardId, int $sprintId)
    {
        $this->authorizeBoard($boardId);
        $sprint = Sprint::where('board_id', $boardId)->findOrFail($sprintId);

        // Completed sprints report off the frozen snapshot; active/future off live cards.
        if ($sprint->status === 'completed' && is_array($sprint->report_snapshot)) {
            $tickets = collect($sprint->report_snapshot)->map(fn($t) => [
                'id' => $t['id'], 'name' => $t['name'], 'points' => $t['points'] ?? null,
                'done_at' => $t['done_at'] ?? null,
                'assigned_user' => $t['assigned_user'] ?? null,
            ]);
        } else {
            // A live card counts as done if it's stamped (done_at) OR currently in a Done
            // column — so cards dragged to Done before done_at tracking still report correctly.
            $doneSectionIds = $this->doneSectionIds($boardId);
            $tickets = Card::where('sprint_id', $sprintId)->whereNull('archived_at')->with('assignedUser:id,name')->get()
                ->map(fn($c) => [
                    'id' => $c->id, 'name' => $c->name, 'points' => $c->story_points,
                    'done_at' => optional($c->done_at)->toIso8601String()
                        ?? (in_array($c->section_id, $doneSectionIds, true) ? now()->toIso8601String() : null),
                    'assigned_user' => $c->assignedUser ? ['id' => $c->assignedUser->id, 'name' => $c->assignedUser->name] : null,
                ]);
        }

        $completed = $tickets->filter(fn($t) => !empty($t['done_at']))->values();
        $notCompleted = $tickets->filter(fn($t) => empty($t['done_at']))->values();
        $committedPoints = $sprint->committed_points ?? (int) $tickets->sum('points');
        $completedPoints = (int) $completed->sum('points');

        return response()->json([
            'sprint'           => $sprint->makeVisible('report_snapshot')->only([
                'id', 'name', 'status', 'goal', 'start_date', 'end_date',
                'started_at', 'completed_at', 'committed_points', 'committed_count',
                'completed_points', 'completed_count',
            ]),
            'committed_points' => $committedPoints,
            'completed_points' => $completedPoints,
            'completed'        => $completed,
            'not_completed'    => $notCompleted,
            'burndown'         => $this->burndown($sprint, $tickets, $committedPoints),
        ]);
    }

    /** Per-day remaining points (from ticket done_at) vs the ideal straight line. */
    private function burndown(Sprint $sprint, $tickets, int $committedPoints): array
    {
        $start = $sprint->started_at ? $sprint->started_at->copy()->startOfDay()
            : ($sprint->start_date ? Carbon::parse($sprint->start_date)->startOfDay() : now()->startOfDay());
        $end = $sprint->completed_at ? $sprint->completed_at->copy()->startOfDay()
            : ($sprint->end_date ? Carbon::parse($sprint->end_date)->startOfDay() : now()->startOfDay());
        if ($end->lt($start)) $end = $start->copy();

        $days = $start->diffInDays($end) + 1;
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->endOfDay();
            $burned = $tickets->filter(fn($t) => !empty($t['done_at']) && Carbon::parse($t['done_at'])->lte($day))
                ->sum('points');
            $series[] = [
                'date'      => $day->toDateString(),
                'remaining' => max(0, $committedPoints - (int) $burned),
                'ideal'     => round($committedPoints * (1 - ($days > 1 ? $i / ($days - 1) : 1)), 1),
            ];
        }
        return $series;
    }

    /**
     * Enforce non-overlapping schedules: walking sprints in date order, any sprint that
     * begins before the previous one ends is pushed so it starts at the previous end,
     * preserving its own duration. Completed sprints are fixed history and anchor the walk.
     * Returns the sprints whose dates changed.
     */
    private function cascadeDates(int $boardId): array
    {
        $sprints = Sprint::where('board_id', $boardId)
            ->where('status', '!=', 'completed')
            ->whereNotNull('start_date')->whereNotNull('end_date')
            ->orderBy('start_date')->get();

        $changed = [];
        for ($i = 1; $i < $sprints->count(); $i++) {
            $prev = $sprints[$i - 1];
            $cur  = $sprints[$i];
            // A sprint must begin the day AFTER the previous one ends (no shared boundary).
            if (Carbon::parse($cur->start_date)->lte(Carbon::parse($prev->end_date))) {
                $duration = Carbon::parse($cur->start_date)->diffInDays(Carbon::parse($cur->end_date));
                $newStart = Carbon::parse($prev->end_date)->addDay();
                $cur->start_date = $newStart->toDateString();
                $cur->end_date   = $newStart->copy()->addDays($duration)->toDateString();
                $cur->save();
                $changed[] = $cur;
            }
        }
        return $changed;
    }

    /** Ids of the board's "Done" columns (a card there counts as completed). */
    private function doneSectionIds(int $boardId): array
    {
        return \App\Infrastructure\Models\Section::where('board_id', $boardId)->get()
            ->filter(fn($s) => strtolower((string) $s->name) === 'done')
            ->pluck('id')->all();
    }

    public function destroy(int $boardId, int $sprintId)
    {
        $this->authorizeWrite($boardId);
        $sprint = Sprint::where('board_id', $boardId)->findOrFail($sprintId);
        // Cards keep existing; their sprint_id is nulled by the FK's nullOnDelete.
        $sprint->delete();
        broadcast(new BoardEvent($boardId, 'sprint.deleted', ['id' => $sprintId]));
        return response()->json(null, 204);
    }
}
