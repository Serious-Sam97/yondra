<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    protected $fillable = [
        'board_id', 'card_id', 'wa_phone', 'contact_name',
        'last_inbound_at', 'service_window_expires_at', 'quality_state',
        'reengagement_attempts', 'last_reengagement_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'service_window_expires_at' => 'datetime',
        'reengagement_attempts' => 'integer',
        'last_reengagement_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'conversation_id')->oldest();
    }

    /**
     * True while the free 24h customer-service window is open — i.e. we may send
     * free-form (non-template) replies. Outside it, only approved templates are allowed.
     */
    public function windowOpen(): bool
    {
        return $this->service_window_expires_at !== null
            && $this->service_window_expires_at->isFuture();
    }
}
