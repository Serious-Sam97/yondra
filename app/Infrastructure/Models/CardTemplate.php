<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class CardTemplate extends Model
{
    protected $fillable = ['board_id', 'user_id', 'name', 'template_data'];

    protected $casts = ['template_data' => 'array'];
}
