<?php

namespace App\Infrastructure\Models;

use App\Observers\CardRelatedObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CardRelatedObserver::class])]
class CardLink extends Model
{
    protected $fillable = [
        'card_id', 'board_id', 'created_by_user_id', 'provider', 'type', 'url',
        'owner', 'repo', 'number', 'title', 'state', 'merged', 'checks_state',
        'author', 'html_url', 'last_synced_at',
    ];

    protected $casts = [
        'merged' => 'boolean',
        'number' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
