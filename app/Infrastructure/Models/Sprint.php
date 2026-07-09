<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    protected $fillable = [
        'board_id', 'name', 'status', 'goal', 'start_date', 'end_date', 'is_active',
        'started_at', 'completed_at',
        'committed_points', 'committed_count', 'completed_points', 'completed_count',
        'report_snapshot',
    ];

    protected $casts = [
        'start_date'       => 'date:Y-m-d',
        'end_date'         => 'date:Y-m-d',
        'is_active'        => 'boolean',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'committed_points' => 'integer',
        'committed_count'  => 'integer',
        'completed_points' => 'integer',
        'completed_count'  => 'integer',
        'report_snapshot'  => 'array',
    ];

    // report_snapshot can be large; keep it out of the board payload (fetched via the report endpoint).
    protected $hidden = ['report_snapshot'];

    public function board(): BelongsTo {
        return $this->belongsTo(Board::class);
    }

    public function cards(): HasMany {
        return $this->hasMany(Card::class);
    }
}
