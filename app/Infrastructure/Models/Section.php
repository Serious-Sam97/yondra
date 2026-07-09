<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = ['board_id', 'name', 'order', 'aging_hours'];

    protected $casts = ['aging_hours' => 'integer'];
}
