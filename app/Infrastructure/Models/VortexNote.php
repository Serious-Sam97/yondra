<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note left for a teammate "from Vortex" on a board (see VortexController). */
class VortexNote extends Model
{
    protected $fillable = ['board_id', 'from_user_id', 'to_user_id', 'body', 'delivered_at'];

    protected $casts = ['delivered_at' => 'datetime'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
