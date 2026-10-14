<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaSpawn extends Model
{
    protected $fillable = ['space_id', 'user_id', 'x', 'y'];

    protected $casts = ['x' => 'float', 'y' => 'float'];
}
