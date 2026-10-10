<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaAvatar extends Model
{
    protected $fillable = ['user_id', 'layers'];

    protected $casts = ['layers' => 'array'];
}
