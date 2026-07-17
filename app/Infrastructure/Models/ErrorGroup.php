<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One distinct fault in the Vortex "Anomalies" error monitor (YON-74), keyed by
 * its `fingerprint`. Every real hit of the same fault increments occurrences_count
 * and appends an ErrorOccurrence; the group carries the triage state (open /
 * resolved / ignored). Written only by App\Services\Monitoring\ErrorRecorder.
 */
class ErrorGroup extends Model
{
    protected $fillable = [
        'fingerprint', 'source', 'level', 'exception_class', 'message',
        'file', 'line', 'status', 'occurrences_count',
        'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected $casts = [
        'line' => 'integer',
        'occurrences_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class);
    }
}
