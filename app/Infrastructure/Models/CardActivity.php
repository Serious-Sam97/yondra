<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a card's History tab. Append-only — written via App\Services\CardHistory. */
class CardActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['card_id', 'board_id', 'user_id', 'source', 'type', 'changes', 'meta'];

    protected $casts = [
        'changes' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
