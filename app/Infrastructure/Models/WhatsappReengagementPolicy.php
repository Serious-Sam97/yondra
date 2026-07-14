<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappReengagementPolicy extends Model
{
    protected $fillable = [
        'board_id', 'enabled', 'idle_days', 'retry_interval_days',
        'max_attempts', 'template_name', 'language', 'lost_section_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'idle_days' => 'integer',
        'retry_interval_days' => 'integer',
        'max_attempts' => 'integer',
        'lost_section_id' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function lostSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'lost_section_id');
    }

    /** The sweep only acts on enabled policies. */
    public function isActive(): bool
    {
        return (bool) $this->enabled;
    }
}
