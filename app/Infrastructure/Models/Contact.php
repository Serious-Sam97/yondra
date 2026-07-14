<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = ['board_id', 'name', 'email', 'phone', 'confirm_token', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    /** Has this contact clicked the opt-in link (whitelisting our sender)? */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
