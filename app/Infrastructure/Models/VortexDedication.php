<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** O-06 · a dedication on the ghost radio, from one teammate to another. */
class VortexDedication extends Model
{
    protected $fillable = ['from_user_id', 'to_user_id', 'text', 'aired_at'];

    protected $casts = ['aired_at' => 'datetime'];

    public function from()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
