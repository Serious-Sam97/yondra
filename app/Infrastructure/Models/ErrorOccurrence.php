<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single hit of an ErrorGroup (YON-74) — the stack + request context captured
 * for one occurrence. The recorder keeps only the newest N per group
 * (config('monitoring.occurrence_cap')) so a hot bug can't grow the table without
 * bound while still preserving a recent window for inspection.
 */
class ErrorOccurrence extends Model
{
    protected $fillable = [
        'error_group_id', 'message', 'stack', 'context', 'environment', 'occurred_at',
    ];

    protected $casts = [
        'stack' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'error_group_id');
    }
}
