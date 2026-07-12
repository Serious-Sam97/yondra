<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningVote extends Model
{
    protected $fillable = [
        'planning_session_id', 'user_id', 'round', 'value', 'voted_at', 'last_seen_at',
        'is_spectator',
    ];

    protected $casts = [
        'round' => 'integer',
        'voted_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_spectator' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PlanningSession::class, 'planning_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
