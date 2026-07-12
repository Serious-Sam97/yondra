<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningSession extends Model
{
    protected $fillable = [
        'board_id', 'card_id', 'round', 'revealed', 'started_by_user_id',
    ];

    protected $casts = [
        'round' => 'integer',
        'revealed' => 'boolean',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PlanningVote::class);
    }
}
