<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaThreadMember extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'last_read_id', 'muted', 'left_at'];

    protected $casts = ['muted' => 'boolean', 'left_at' => 'datetime'];
}
