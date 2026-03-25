<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $fillable = ['board_id', 'section_id', 'name', 'description'];
}
