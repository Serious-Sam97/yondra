<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit + idempotency record of a milestone firing on a card (YON-63). The
 * (card_id, milestone_id) unique index makes each milestone fire exactly once.
 */
class PaymentMilestoneEvent extends Model
{
    protected $table = 'card_payment_milestone_events';

    protected $fillable = [
        'card_id', 'board_id', 'milestone_id', 'threshold_pct', 'amount_paid_at_trigger',
        'message_status', 'message_channel', 'error', 'moved_to_section_id', 'triggered_at',
    ];

    protected $casts = [
        'threshold_pct' => 'integer',
        'amount_paid_at_trigger' => 'decimal:2',
        'moved_to_section_id' => 'integer',
        'triggered_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(PaymentMilestone::class, 'milestone_id');
    }
}
