<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestCase extends Model
{
    protected $fillable = [
        'board_id', 'card_id', 'title', 'type', 'qa_planner_user_id', 'target_env',
        'gherkin', 'preconditions', 'postconditions', 'step_refs', 'data_matrix',
        'bug_card_id', 'awaiting_retest', 'position', 'version', 'edited_by_user_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'version' => 'integer',
        'step_refs' => 'array',
        'data_matrix' => 'array',
        'awaiting_retest' => 'boolean',
    ];

    // Single source of truth for the client-facing case shape (used by QaController and
    // the reorder bug-resolution). latest_status is derived: awaiting-retest wins, else
    // the newest run, else "not_run".
    public function toSnapshot(): array
    {
        $this->loadMissing(['planner:id,name', 'editor:id,name', 'runs.executor:id,name', 'plans:id']);

        $runs = $this->runs->map(fn (TestRun $r) => [
            'id' => $r->id,
            'status' => $r->status,
            'executor' => $r->executor ? ['id' => $r->executor->id, 'name' => $r->executor->name] : null,
            'environment' => $r->environment,
            'device' => $r->device,
            'executed_at' => optional($r->executed_at)->toIso8601String(),
            'evidence' => $r->evidence ?? [],
            'logs' => $r->logs,
        ])->values()->all();

        $latest = $this->awaiting_retest ? 'awaiting_retest' : ($runs[0]['status'] ?? 'not_run');

        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'board_id' => $this->board_id,
            'title' => $this->title,
            'type' => $this->type,
            'target_env' => $this->target_env,
            'gherkin' => $this->gherkin,
            'preconditions' => $this->preconditions,
            'postconditions' => $this->postconditions,
            'step_refs' => $this->step_refs ?? [],
            'data_matrix' => $this->data_matrix ?? ['columns' => [], 'rows' => []],
            'test_plan_ids' => $this->plans->pluck('id')->all(),
            'bug_card_id' => $this->bug_card_id,
            'awaiting_retest' => (bool) $this->awaiting_retest,
            'position' => $this->position,
            'version' => $this->version,
            'planner' => $this->planner ? ['id' => $this->planner->id, 'name' => $this->planner->name] : null,
            'editor' => $this->editor ? ['id' => $this->editor->id, 'name' => $this->editor->name] : null,
            'latest_status' => $latest,
            'runs' => $runs,
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function planner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qa_planner_user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }

    // Runs newest-first — the card header reflects runs()->first(). `id` breaks ties
    // when two runs share the same executed_at timestamp.
    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class)->orderByDesc('executed_at')->orderByDesc('id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(TestPlan::class, 'test_plan_case');
    }
}
