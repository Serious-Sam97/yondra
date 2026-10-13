<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaSetup extends Model
{
    protected $fillable = ['user_id', 'name', 'items'];

    protected $casts = ['items' => 'array'];
}
