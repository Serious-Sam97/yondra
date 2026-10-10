<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaDesk extends Model
{
    protected $fillable = ['space_id', 'user_id', 'desk_uid'];
}
