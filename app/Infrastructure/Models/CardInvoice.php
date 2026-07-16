<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nota fiscal / invoice issued for a CRM deal (YON-68). Created when a card hits
 * a "generate invoice" payment milestone (typically 100% paid) or via a manual
 * "issue" click. One row per card (idempotent); `number` is a per-board sequence.
 * The rendered PDF is attached to the card as a system-owned CardDocument.
 *
 * This is a simplified invoice document, NOT a SEFAZ-registered fiscal NF-e.
 */
class CardInvoice extends Model
{
    protected $fillable = [
        'card_id', 'board_id', 'document_id', 'number', 'currency',
        'amount', 'issuer', 'recipient', 'issued_at', 'issued_by_user_id',
    ];

    protected $casts = [
        'number' => 'integer',
        'amount' => 'decimal:2',
        'issuer' => 'array',
        'recipient' => 'array',
        'issued_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CardDocument::class, 'document_id');
    }
}
