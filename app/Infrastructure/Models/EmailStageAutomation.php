<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailStageAutomation extends Model
{
    protected $fillable = [
        'board_id', 'section_id', 'subject', 'body', 'enabled', 'paused_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Live only when enabled and not paused. */
    public function isActive(): bool
    {
        return $this->enabled && $this->paused_at === null;
    }
}
