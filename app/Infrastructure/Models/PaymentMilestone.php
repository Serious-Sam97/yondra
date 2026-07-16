<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-board payment milestone rule (YON-63): when a deal's paid % crosses
 * threshold_pct, fire its actions (message, move to move_to_section_id, and/or
 * generate a nota fiscal invoice — YON-68).
 */
class PaymentMilestone extends Model
{
    protected $fillable = [
        'board_id', 'threshold_pct', 'label', 'notify', 'channel',
        'whatsapp_template_name', 'language', 'email_subject', 'email_body',
        'move_to_section_id', 'generate_invoice', 'enabled', 'position',
    ];

    protected $casts = [
        'threshold_pct' => 'integer',
        'notify' => 'boolean',
        'move_to_section_id' => 'integer',
        'generate_invoice' => 'boolean',
        'enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function moveToSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'move_to_section_id');
    }
}
