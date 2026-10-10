<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** N-14 · a trade offer between teammates. */
class VortexTrade extends Model
{
    protected $fillable = ['from_user_id', 'to_user_id', 'give', 'give_tokens', 'want', 'want_tokens', 'status'];

    public function from()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
