<?php

namespace App\Infrastructure\Models;

use App\Observers\CardRelatedObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CardRelatedObserver::class])]
class CardChecklistItem extends Model
{
    protected $fillable = ['card_id', 'text', 'is_done', 'position'];

    protected $casts = ['is_done' => 'boolean'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
