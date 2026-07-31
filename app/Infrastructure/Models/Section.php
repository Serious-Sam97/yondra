<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /**
     * Name of the reserved per-board parking section used by the kanban/CRM backlog
     * view. Mirrors BACKLOG_NAME in the frontend's useBoardBacklog hook; scrum boards
     * do not use it (their backlog is the null-sprint pool).
     */
    public const RESERVED_BACKLOG = 'Backlog';

    protected $fillable = ['board_id', 'name', 'order', 'aging_hours'];

    protected $casts = ['aging_hours' => 'integer'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
