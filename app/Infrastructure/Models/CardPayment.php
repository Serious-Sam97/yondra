<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment received against a deal (YON-63). The card's total paid is the sum
 * of its payments; {@see PaymentService} caches that sum on cards.amount_paid.
 */
class CardPayment extends Model
{
    protected $fillable = [
        'card_id', 'board_id', 'amount', 'note', 'paid_at', 'recorded_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
