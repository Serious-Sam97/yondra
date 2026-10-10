<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vortex's whole state for one user (MK-V · the soul). See App\Services\Vortex\SoulService. */
class VortexSoul extends Model
{
    protected $fillable = ['user_id', 'state', 'relation', 'corruption', 'proximity', 'last_seen_at', 'last_tick_at'];

    protected $casts = [
        'state' => 'array',
        'last_seen_at' => 'datetime',
        'last_tick_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
